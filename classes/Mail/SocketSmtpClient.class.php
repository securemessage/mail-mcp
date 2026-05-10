<?php
/**
 * SecureMessage Mail MCP Server — Pure PHP Socket SMTP Client
 *
 * SMTP client using native PHP sockets with TLS support.
 * Supports AUTH LOGIN, AUTH PLAIN, and AUTH XOAUTH2.
 * No external extensions required beyond openssl.
 *
 * Designed for future extraction to Enchilada Extras as EnchiladaMail.
 *
 * @package    MailMCP\Mail
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace Mail;

class SocketSmtpClient implements SmtpClientInterface
{
	/** @var resource|null */
	private $socket = null;

	/** @var bool */
	private bool $authenticated = false;

	/** @var string[] Server capabilities from EHLO */
	private array $capabilities = [];

	/** @var int Socket timeout in seconds */
	private int $timeout = 30;

	/** @var bool Whether to verify SSL peer certificate */
	private bool $verifySsl = true;

	/**
	 * @param int  $timeout   Socket timeout in seconds
	 * @param bool $verifySsl Verify SSL peer certificate (disable for self-signed)
	 */
	public function __construct(int $timeout = 30, bool $verifySsl = true)
	{
		$this->timeout = $timeout;
		$this->verifySsl = $verifySsl;
	}

	public function connect(string $host, int $port, bool $tls = true, bool $starttls = true): void
	{
		if ($this->socket !== null) {
			$this->disconnect();
		}

		$context = stream_context_create([
			'ssl' => [
				'verify_peer' => $this->verifySsl,
				'verify_peer_name' => $this->verifySsl,
				'allow_self_signed' => !$this->verifySsl,
			],
		]);

		$scheme = $tls ? 'ssl' : 'tcp';
		$address = "{$scheme}://{$host}:{$port}";

		$errno = 0;
		$errstr = '';
		$this->socket = @stream_socket_client(
			$address,
			$errno,
			$errstr,
			$this->timeout,
			STREAM_CLIENT_CONNECT,
			$context
		);

		if ($this->socket === false) {
			$this->socket = null;
			throw new \RuntimeException("SMTP connection failed to {$host}:{$port}: [{$errno}] {$errstr}");
		}

		stream_set_timeout($this->socket, $this->timeout);

		// Read server greeting
		$greeting = $this->readResponse();
		if ($greeting['code'] !== 220) {
			$this->disconnect();
			throw new \RuntimeException("SMTP server rejected connection: {$greeting['message']}");
		}

		// Send EHLO
		$this->ehlo();

		// STARTTLS if not using implicit TLS (unless plaintext mode)
		if (!$tls && $starttls) {
			$this->starttls($host);
		}
	}

	public function authenticate(string $username, string $password): void
	{
		$this->requireConnection();

		if (in_array('LOGIN', $this->getAuthMechanisms())) {
			$this->authLogin($username, $password);
		} elseif (in_array('PLAIN', $this->getAuthMechanisms())) {
			$this->authPlain($username, $password);
		} else {
			throw new \RuntimeException('Server does not support AUTH LOGIN or AUTH PLAIN');
		}

		$this->authenticated = true;
	}

	public function authenticateXOAuth2(string $username, string $accessToken): void
	{
		$this->requireConnection();

		$authString = "user={$username}\x01auth=Bearer {$accessToken}\x01\x01";
		$encoded = base64_encode($authString);

		$this->writeLine("AUTH XOAUTH2 {$encoded}");
		$response = $this->readResponse();

		if ($response['code'] === 334) {
			// Error challenge — send empty line to cancel and read final error
			$this->writeLine('');
			$response = $this->readResponse();
		}

		if ($response['code'] !== 235) {
			throw new \RuntimeException("SMTP XOAUTH2 authentication failed: {$response['message']}");
		}

		$this->authenticated = true;
	}

	public function send(string $from, array $recipients, string $rawMessage): void
	{
		$this->requireAuth();

		// MAIL FROM
		$this->writeLine("MAIL FROM:<{$from}>");
		$response = $this->readResponse();
		if ($response['code'] !== 250) {
			throw new \RuntimeException("MAIL FROM rejected: {$response['message']}");
		}

		// RCPT TO for each recipient
		foreach ($recipients as $recipient) {
			$recipient = trim($recipient);
			if (empty($recipient)) continue;

			$this->writeLine("RCPT TO:<{$recipient}>");
			$response = $this->readResponse();
			if ($response['code'] !== 250 && $response['code'] !== 251) {
				throw new \RuntimeException("RCPT TO <{$recipient}> rejected: {$response['message']}");
			}
		}

		// DATA
		$this->writeLine('DATA');
		$response = $this->readResponse();
		if ($response['code'] !== 354) {
			throw new \RuntimeException("DATA command rejected: {$response['message']}");
		}

		// Send the message body, dot-stuffing lines that start with '.'
		$lines = explode("\n", str_replace("\r\n", "\n", $rawMessage));
		foreach ($lines as $line) {
			$line = rtrim($line, "\r");
			if (str_starts_with($line, '.')) {
				$line = '.' . $line;
			}
			$this->writeLine($line);
		}

		// End with <CRLF>.<CRLF>
		$this->writeLine('.');
		$response = $this->readResponse();
		if ($response['code'] !== 250) {
			throw new \RuntimeException("Message delivery failed: {$response['message']}");
		}
	}

	public function disconnect(): void
	{
		if ($this->socket !== null) {
			try {
				$this->writeLine('QUIT');
				$this->readResponse();
			} catch (\Throwable $e) {
				// Ignore errors during quit
			}

			@fclose($this->socket);
			$this->socket = null;
			$this->authenticated = false;
			$this->capabilities = [];
		}
	}

	public function isConnected(): bool
	{
		return $this->socket !== null && $this->authenticated;
	}

	// ────────────────────────────────────────────────────────────
	//  Protocol Helpers
	// ────────────────────────────────────────────────────────────

	/**
	 * Send EHLO and capture server capabilities.
	 */
	private function ehlo(): void
	{
		$hostname = gethostname() ?: 'localhost';
		$this->writeLine("EHLO {$hostname}");
		$response = $this->readResponse();

		if ($response['code'] !== 250) {
			throw new \RuntimeException("EHLO rejected: {$response['message']}");
		}

		$this->capabilities = $response['lines'];
	}

	/**
	 * Upgrade to TLS via STARTTLS.
	 */
	private function starttls(string $host): void
	{
		$this->writeLine('STARTTLS');
		$response = $this->readResponse();

		if ($response['code'] !== 220) {
			throw new \RuntimeException("STARTTLS failed: {$response['message']}");
		}

		// Set SSL context options for the upgrade
		stream_context_set_option($this->socket, 'ssl', 'verify_peer', $this->verifySsl);
		stream_context_set_option($this->socket, 'ssl', 'verify_peer_name', $this->verifySsl);
		stream_context_set_option($this->socket, 'ssl', 'allow_self_signed', !$this->verifySsl);
		stream_context_set_option($this->socket, 'ssl', 'peer_name', $host);

		$result = stream_socket_enable_crypto(
			$this->socket,
			true,
			STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
		);

		if ($result !== true) {
			throw new \RuntimeException('TLS negotiation failed after STARTTLS');
		}

		// Re-send EHLO after TLS upgrade (RFC 3207)
		$this->ehlo();
	}

	/**
	 * AUTH LOGIN mechanism.
	 */
	private function authLogin(string $username, string $password): void
	{
		$this->writeLine('AUTH LOGIN');
		$response = $this->readResponse();

		if ($response['code'] !== 334) {
			throw new \RuntimeException("AUTH LOGIN rejected: {$response['message']}");
		}

		$this->writeLine(base64_encode($username));
		$response = $this->readResponse();

		if ($response['code'] !== 334) {
			throw new \RuntimeException("AUTH LOGIN username rejected: {$response['message']}");
		}

		$this->writeLine(base64_encode($password));
		$response = $this->readResponse();

		if ($response['code'] !== 235) {
			throw new \RuntimeException("AUTH LOGIN failed: {$response['message']}");
		}
	}

	/**
	 * AUTH PLAIN mechanism.
	 */
	private function authPlain(string $username, string $password): void
	{
		$authString = base64_encode("\x00{$username}\x00{$password}");
		$this->writeLine("AUTH PLAIN {$authString}");
		$response = $this->readResponse();

		if ($response['code'] !== 235) {
			throw new \RuntimeException("AUTH PLAIN failed: {$response['message']}");
		}
	}

	/**
	 * Extract supported AUTH mechanisms from capabilities.
	 *
	 * @return string[] e.g., ['LOGIN', 'PLAIN', 'XOAUTH2']
	 */
	private function getAuthMechanisms(): array
	{
		foreach ($this->capabilities as $cap) {
			if (preg_match('/^AUTH\s+(.+)$/i', $cap, $m)) {
				return preg_split('/\s+/', strtoupper(trim($m[1])));
			}
		}
		return [];
	}

	/**
	 * Write a line to the socket.
	 */
	private function writeLine(string $line): void
	{
		$this->requireConnection();
		$written = @fwrite($this->socket, $line . "\r\n");
		if ($written === false) {
			throw new \RuntimeException('Failed to write to SMTP socket');
		}
	}

	/**
	 * Read a multi-line SMTP response.
	 *
	 * @return array{code:int,message:string,lines:string[]}
	 */
	private function readResponse(): array
	{
		$lines = [];
		$code = 0;
		$message = '';

		while (true) {
			$line = @fgets($this->socket, 65536);
			if ($line === false) {
				if (feof($this->socket)) {
					throw new \RuntimeException('SMTP connection closed unexpectedly');
				}
				$info = stream_get_meta_data($this->socket);
				if ($info['timed_out']) {
					throw new \RuntimeException('SMTP socket read timed out');
				}
				throw new \RuntimeException('Failed to read from SMTP socket');
			}

			$line = rtrim($line, "\r\n");

			// SMTP responses: "NNN-text" for continuation, "NNN text" for final
			if (preg_match('/^(\d{3})([-\s])(.*)$/', $line, $m)) {
				$code = (int) $m[1];
				$separator = $m[2];
				$text = $m[3];

				$lines[] = $text;
				$message = $text;

				// Final line uses space, continuation uses dash
				if ($separator === ' ') {
					break;
				}
			} else {
				$lines[] = $line;
			}
		}

		return [
			'code' => $code,
			'message' => $message,
			'lines' => $lines,
		];
	}

	/**
	 * Assert that a socket connection exists.
	 */
	private function requireConnection(): void
	{
		if ($this->socket === null) {
			throw new \RuntimeException('Not connected to SMTP server');
		}
	}

	/**
	 * Assert that the client is authenticated.
	 */
	private function requireAuth(): void
	{
		$this->requireConnection();
		if (!$this->authenticated) {
			throw new \RuntimeException('Not authenticated to SMTP server');
		}
	}

	public function __destruct()
	{
		$this->disconnect();
	}
}
