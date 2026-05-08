<?php
/**
 * Mail MCP Server — Pure PHP Socket IMAP Client
 *
 * IMAP4rev1 client using native PHP sockets with TLS support.
 * Implements the minimal command set needed for MCP email tools.
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

class SocketImapClient implements ImapClientInterface
{
	/** @var resource|null */
	private $socket = null;

	/** @var int Command tag counter */
	private int $tagCounter = 0;

	/** @var bool */
	private bool $authenticated = false;

	/** @var string|null Currently selected mailbox */
	private ?string $currentMailbox = null;

	/** @var int Socket timeout in seconds */
	private int $timeout = 30;

	/**
	 * @param int $timeout Socket timeout in seconds
	 */
	public function __construct(int $timeout = 30)
	{
		$this->timeout = $timeout;
	}

	public function connect(string $host, int $port, bool $tls = true): void
	{
		if ($this->socket !== null) {
			$this->disconnect();
		}

		$context = stream_context_create([
			'ssl' => [
				'verify_peer' => true,
				'verify_peer_name' => true,
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
			throw new \RuntimeException("IMAP connection failed to {$host}:{$port}: [{$errno}] {$errstr}");
		}

		stream_set_timeout($this->socket, $this->timeout);

		// Read server greeting
		$greeting = $this->readLine();
		if (!str_starts_with($greeting, '* OK')) {
			$this->disconnect();
			throw new \RuntimeException("IMAP server rejected connection: {$greeting}");
		}

		// If not using implicit TLS, try STARTTLS
		if (!$tls) {
			$this->starttls();
		}
	}

	public function login(string $username, string $password): void
	{
		$this->requireConnection();

		// Quote username and password (handle special characters)
		$quotedUser = $this->quoteString($username);
		$quotedPass = $this->quoteString($password);

		$response = $this->command("LOGIN {$quotedUser} {$quotedPass}");

		if ($response['status'] !== 'OK') {
			throw new \RuntimeException("IMAP LOGIN failed: {$response['text']}");
		}

		$this->authenticated = true;
	}

	public function authenticateXOAuth2(string $username, string $accessToken): void
	{
		$this->requireConnection();

		// Build XOAUTH2 SASL token: user={email}\x01auth=Bearer {token}\x01\x01
		$authString = "user={$username}\x01auth=Bearer {$accessToken}\x01\x01";
		$encoded = base64_encode($authString);

		$tag = $this->nextTag();
		$this->writeLine("{$tag} AUTHENTICATE XOAUTH2 {$encoded}");

		// Read response — may get a continuation request (+) on failure with error details
		$line = $this->readLine();

		if (str_starts_with($line, '+')) {
			// Server sent a challenge with error JSON — send empty response to cancel
			$this->writeLine('');
			$line = $this->readLine();
		}

		if (!str_starts_with($line, "{$tag} OK")) {
			throw new \RuntimeException("IMAP XOAUTH2 authentication failed: {$line}");
		}

		$this->authenticated = true;
	}

	public function listMailboxes(string $reference = '', string $pattern = '*'): array
	{
		$this->requireAuth();

		$ref = $this->quoteString($reference);
		$pat = $this->quoteString($pattern);
		$response = $this->command("LIST {$ref} {$pat}");

		$mailboxes = [];
		foreach ($response['untagged'] as $line) {
			// * LIST (\HasNoChildren) "." "INBOX"
			if (preg_match('/^\* LIST \(([^)]*)\) "([^"]*)" (.+)$/i', $line, $m)) {
				$flags = !empty($m[1]) ? preg_split('/\s+/', $m[1]) : [];
				$delimiter = $m[2];
				$name = trim($m[3], '"');

				$mailboxes[] = new Mailbox(
					name: $name,
					delimiter: $delimiter,
					flags: $flags,
				);
			}
		}

		return $mailboxes;
	}

	public function selectMailbox(string $name, bool $readOnly = false): Mailbox
	{
		$this->requireAuth();

		$cmd = $readOnly ? 'EXAMINE' : 'SELECT';
		$response = $this->command("{$cmd} " . $this->quoteString($name));

		if ($response['status'] !== 'OK') {
			throw new \RuntimeException("IMAP {$cmd} failed for '{$name}': {$response['text']}");
		}

		$total = 0;
		$recent = 0;
		$unseen = 0;
		$uidValidity = 0;
		$uidNext = 0;
		$flags = [];

		foreach ($response['untagged'] as $line) {
			if (preg_match('/^\* (\d+) EXISTS$/i', $line, $m)) {
				$total = (int) $m[1];
			} elseif (preg_match('/^\* (\d+) RECENT$/i', $line, $m)) {
				$recent = (int) $m[1];
			} elseif (preg_match('/^\* OK \[UNSEEN (\d+)\]/i', $line, $m)) {
				$unseen = (int) $m[1];
			} elseif (preg_match('/^\* OK \[UIDVALIDITY (\d+)\]/i', $line, $m)) {
				$uidValidity = (int) $m[1];
			} elseif (preg_match('/^\* OK \[UIDNEXT (\d+)\]/i', $line, $m)) {
				$uidNext = (int) $m[1];
			} elseif (preg_match('/^\* FLAGS \(([^)]*)\)/i', $line, $m)) {
				$flags = !empty($m[1]) ? preg_split('/\s+/', $m[1]) : [];
			}
		}

		$this->currentMailbox = $name;

		return new Mailbox(
			name: $name,
			totalMessages: $total,
			recentMessages: $recent,
			unseenMessages: $unseen,
			uidValidity: $uidValidity,
			uidNext: $uidNext,
			flags: $flags,
		);
	}

	public function search(array $criteria = []): array
	{
		$this->requireAuth();

		if (empty($criteria)) {
			$searchStr = 'ALL';
		} else {
			$parts = [];
			foreach ($criteria as $key => $value) {
				if (is_int($key)) {
					// Bare keyword like 'UNSEEN', 'FLAGGED', 'ALL'
					$parts[] = $value;
				} else {
					// Key-value pair like 'FROM' => 'user@example.com'
					$parts[] = $key . ' ' . $this->quoteString($value);
				}
			}
			$searchStr = implode(' ', $parts);
		}

		$response = $this->command("UID SEARCH {$searchStr}");

		$uids = [];
		foreach ($response['untagged'] as $line) {
			// * SEARCH 1 2 3 4 5
			if (preg_match('/^\* SEARCH\s+(.+)$/i', $line, $m)) {
				$uids = array_merge($uids, array_map('intval', preg_split('/\s+/', trim($m[1]))));
			}
		}

		return $uids;
	}

	public function fetchHeaders(array $uids): array
	{
		$this->requireAuth();

		if (empty($uids)) {
			return [];
		}

		$uidSet = implode(',', $uids);
		$response = $this->command("UID FETCH {$uidSet} (UID FLAGS RFC822.SIZE BODY.PEEK[HEADER.FIELDS (FROM TO CC BCC SUBJECT DATE MESSAGE-ID REPLY-TO)])");

		return $this->parseFetchResponses($response['untagged']);
	}

	public function fetchMessage(int $uid, bool $markSeen = false): Message
	{
		$this->requireAuth();

		$peek = $markSeen ? 'BODY[]' : 'BODY.PEEK[]';
		$response = $this->command("UID FETCH {$uid} (UID FLAGS RFC822.SIZE BODYSTRUCTURE {$peek})");

		$messages = $this->parseFetchResponses($response['untagged'], true);

		if (empty($messages)) {
			throw new \RuntimeException("Message UID {$uid} not found");
		}

		return $messages[0];
	}

	public function fetchAttachment(int $uid, string $partNumber): string
	{
		$this->requireAuth();

		$response = $this->command("UID FETCH {$uid} (BODY.PEEK[{$partNumber}])");

		foreach ($response['untagged'] as $line) {
			// Look for the literal data
			if (preg_match('/\{(\d+)\}$/', $line, $m)) {
				$size = (int) $m[1];
				$data = $this->readBytes($size);
				$this->readLine(); // closing paren line
				return $data;
			}
		}

		throw new \RuntimeException("Attachment part {$partNumber} not found for UID {$uid}");
	}

	public function addFlags(int $uid, array $flags): void
	{
		$this->requireAuth();
		$flagStr = implode(' ', $flags);
		$response = $this->command("UID STORE {$uid} +FLAGS ({$flagStr})");

		if ($response['status'] !== 'OK') {
			throw new \RuntimeException("Failed to add flags: {$response['text']}");
		}
	}

	public function removeFlags(int $uid, array $flags): void
	{
		$this->requireAuth();
		$flagStr = implode(' ', $flags);
		$response = $this->command("UID STORE {$uid} -FLAGS ({$flagStr})");

		if ($response['status'] !== 'OK') {
			throw new \RuntimeException("Failed to remove flags: {$response['text']}");
		}
	}

	public function deleteMessage(int $uid): void
	{
		$this->addFlags($uid, ['\\Deleted']);

		$response = $this->command('EXPUNGE');
		if ($response['status'] !== 'OK') {
			throw new \RuntimeException("EXPUNGE failed: {$response['text']}");
		}
	}

	public function disconnect(): void
	{
		if ($this->socket !== null) {
			if ($this->authenticated) {
				try {
					$this->command('LOGOUT');
				} catch (\Throwable $e) {
					// Ignore errors during logout
				}
			}

			@fclose($this->socket);
			$this->socket = null;
			$this->authenticated = false;
			$this->currentMailbox = null;
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
	 * Upgrade a plaintext connection to TLS via STARTTLS.
	 */
	private function starttls(): void
	{
		$response = $this->command('STARTTLS');

		if ($response['status'] !== 'OK') {
			throw new \RuntimeException("STARTTLS failed: {$response['text']}");
		}

		$result = stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);

		if ($result !== true) {
			throw new \RuntimeException('TLS negotiation failed after STARTTLS');
		}
	}

	/**
	 * Send a tagged command and read the complete response.
	 *
	 * @param  string $command IMAP command (without tag)
	 * @return array{status:string,text:string,untagged:string[]}
	 */
	private function command(string $command): array
	{
		$tag = $this->nextTag();
		$this->writeLine("{$tag} {$command}");

		$untagged = [];

		while (true) {
			$line = $this->readLine();

			if ($line === null) {
				throw new \RuntimeException('IMAP connection lost');
			}

			// Check for literal continuation {N}
			if (preg_match('/\{(\d+)\}$/', $line, $m)) {
				$size = (int) $m[1];
				$literalData = $this->readBytes($size);
				$line .= "\r\n" . $literalData;
				// Read the rest of the line after the literal
				$continuation = $this->readLine();
				if ($continuation !== null && $continuation !== ')') {
					$line .= $continuation;
				}
			}

			// Tagged response — command complete
			if (str_starts_with($line, "{$tag} ")) {
				$parts = explode(' ', $line, 3);
				return [
					'status' => $parts[1] ?? 'NO',
					'text' => $parts[2] ?? '',
					'untagged' => $untagged,
				];
			}

			// Untagged response
			$untagged[] = $line;
		}
	}

	/**
	 * Generate the next command tag.
	 */
	private function nextTag(): string
	{
		return 'A' . str_pad(++$this->tagCounter, 4, '0', STR_PAD_LEFT);
	}

	/**
	 * Write a line to the socket.
	 */
	private function writeLine(string $line): void
	{
		$this->requireConnection();
		$written = @fwrite($this->socket, $line . "\r\n");
		if ($written === false) {
			throw new \RuntimeException('Failed to write to IMAP socket');
		}
	}

	/**
	 * Read a single line from the socket.
	 */
	private function readLine(): ?string
	{
		if ($this->socket === null) {
			return null;
		}

		$line = @fgets($this->socket, 65536);
		if ($line === false) {
			if (feof($this->socket)) {
				return null;
			}
			$info = stream_get_meta_data($this->socket);
			if ($info['timed_out']) {
				throw new \RuntimeException('IMAP socket read timed out');
			}
			return null;
		}

		return rtrim($line, "\r\n");
	}

	/**
	 * Read exactly N bytes from the socket.
	 */
	private function readBytes(int $count): string
	{
		$data = '';
		$remaining = $count;

		while ($remaining > 0) {
			$chunk = @fread($this->socket, min($remaining, 65536));
			if ($chunk === false || $chunk === '') {
				throw new \RuntimeException("IMAP read error: expected {$count} bytes, got " . strlen($data));
			}
			$data .= $chunk;
			$remaining -= strlen($chunk);
		}

		return $data;
	}

	/**
	 * Quote a string for IMAP protocol (handles special characters).
	 */
	private function quoteString(string $str): string
	{
		// If it contains special chars, use a literal
		if (preg_match('/[\x00-\x1f"\\\\]/', $str)) {
			return '{' . strlen($str) . "}\r\n" . $str;
		}
		return '"' . $str . '"';
	}

	/**
	 * Assert that a socket connection exists.
	 */
	private function requireConnection(): void
	{
		if ($this->socket === null) {
			throw new \RuntimeException('Not connected to IMAP server');
		}
	}

	/**
	 * Assert that the client is authenticated.
	 */
	private function requireAuth(): void
	{
		$this->requireConnection();
		if (!$this->authenticated) {
			throw new \RuntimeException('Not authenticated to IMAP server');
		}
	}

	/**
	 * Parse FETCH responses into Message objects.
	 *
	 * @param  string[] $untagged  Untagged response lines
	 * @param  bool     $fullBody  Whether to parse body content
	 * @return Message[]
	 */
	private function parseFetchResponses(array $untagged, bool $fullBody = false): array
	{
		$messages = [];
		$currentMsg = null;
		$headerBlock = '';
		$bodyData = '';
		$inHeaders = false;
		$inBody = false;

		foreach ($untagged as $line) {
			// * N FETCH (...)
			if (preg_match('/^\* \d+ FETCH \(/i', $line)) {
				// Save previous message
				if ($currentMsg !== null) {
					$this->applyHeaders($currentMsg, $headerBlock);
					if ($fullBody && !empty($bodyData)) {
						$this->parseBody($currentMsg, $bodyData);
					}
					$messages[] = $currentMsg;
				}

				$currentMsg = new Message();
				$currentMsg->mailbox = $this->currentMailbox ?? '';
				$headerBlock = '';
				$bodyData = '';

				// Extract UID
				if (preg_match('/UID (\d+)/i', $line, $m)) {
					$currentMsg->uid = (int) $m[1];
				}

				// Extract FLAGS
				if (preg_match('/FLAGS \(([^)]*)\)/i', $line, $m)) {
					$currentMsg->flags = !empty($m[1]) ? preg_split('/\s+/', $m[1]) : [];
				}

				// Extract RFC822.SIZE
				if (preg_match('/RFC822\.SIZE (\d+)/i', $line, $m)) {
					$currentMsg->size = (int) $m[1];
				}

				// Extract BODYSTRUCTURE for attachments
				if (preg_match('/BODYSTRUCTURE \((.+)\)/i', $line, $m)) {
					$currentMsg->attachments = $this->parseBodyStructure($m[1]);
				}

				// Check if literal follows for headers or body
				if (preg_match('/\{(\d+)\}$/', $line, $m)) {
					$size = (int) $m[1];
					$literalContent = $this->readBytes($size);
					$this->readLine(); // closing paren

					if ($fullBody) {
						$bodyData = $literalContent;
					} else {
						$headerBlock = $literalContent;
					}
				}

				continue;
			}

			// Continuation of header/body data in current fetch
			if ($currentMsg !== null) {
				if (preg_match('/\{(\d+)\}$/', $line, $m)) {
					$size = (int) $m[1];
					$data = $this->readBytes($size);
					$this->readLine(); // closing paren

					if ($fullBody && empty($headerBlock)) {
						$bodyData = $data;
					} else {
						$headerBlock .= $data;
					}
				}
			}
		}

		// Don't forget the last message
		if ($currentMsg !== null) {
			$this->applyHeaders($currentMsg, $headerBlock);
			if ($fullBody && !empty($bodyData)) {
				$this->parseBody($currentMsg, $bodyData);
			}
			$messages[] = $currentMsg;
		}

		return $messages;
	}

	/**
	 * Parse raw RFC 2822 headers and populate a Message object.
	 */
	private function applyHeaders(Message $msg, string $raw): void
	{
		if (empty($raw)) return;

		// Unfold continuation lines
		$raw = preg_replace('/\r?\n[ \t]+/', ' ', $raw);

		$headers = [];
		foreach (explode("\n", $raw) as $line) {
			$line = trim($line);
			if (empty($line)) continue;
			if (preg_match('/^([A-Za-z-]+):\s*(.*)$/', $line, $m)) {
				$headers[strtolower($m[1])] = $m[2];
			}
		}

		$msg->headers = $headers;
		$msg->subject = $this->decodeHeader($headers['subject'] ?? '');
		$msg->from = $this->decodeHeader($headers['from'] ?? '');
		$msg->to = $this->decodeHeader($headers['to'] ?? '');
		$msg->cc = $this->decodeHeader($headers['cc'] ?? '');
		$msg->bcc = $this->decodeHeader($headers['bcc'] ?? '');
		$msg->replyTo = $this->decodeHeader($headers['reply-to'] ?? '');
		$msg->date = $headers['date'] ?? '';
		$msg->messageId = $headers['message-id'] ?? '';
	}

	/**
	 * Decode RFC 2047 encoded header values.
	 */
	private function decodeHeader(string $value): string
	{
		if (empty($value)) return '';

		// Decode =?charset?encoding?text?= sequences
		return preg_replace_callback(
			'/=\?([^?]+)\?(Q|B)\?([^?]*)\?=/i',
			function ($matches) {
				$charset = $matches[1];
				$encoding = strtoupper($matches[2]);
				$text = $matches[3];

				if ($encoding === 'B') {
					$decoded = base64_decode($text);
				} else {
					$decoded = quoted_printable_decode(str_replace('_', ' ', $text));
				}

				if (strtoupper($charset) !== 'UTF-8') {
					$converted = @iconv($charset, 'UTF-8//IGNORE', $decoded);
					if ($converted !== false) {
						return $converted;
					}
				}

				return $decoded;
			},
			$value
		);
	}

	/**
	 * Parse a raw RFC 2822 message body into text/html parts.
	 */
	private function parseBody(Message $msg, string $raw): void
	{
		// Split headers from body
		$parts = preg_split('/\r?\n\r?\n/', $raw, 2);
		$headerPart = $parts[0] ?? '';
		$bodyPart = $parts[1] ?? '';

		// Unfold headers
		$headerPart = preg_replace('/\r?\n[ \t]+/', ' ', $headerPart);

		$contentType = 'text/plain';
		$charset = 'utf-8';
		$encoding = '7bit';
		$boundary = '';

		foreach (explode("\n", $headerPart) as $line) {
			$line = trim($line);
			if (preg_match('/^Content-Type:\s*([^;\s]+)/i', $line, $m)) {
				$contentType = strtolower($m[1]);
			}
			if (preg_match('/charset="?([^";\s]+)"?/i', $line, $m)) {
				$charset = $m[1];
			}
			if (preg_match('/boundary="?([^";\s]+)"?/i', $line, $m)) {
				$boundary = $m[1];
			}
			if (preg_match('/^Content-Transfer-Encoding:\s*(\S+)/i', $line, $m)) {
				$encoding = strtolower($m[1]);
			}
		}

		if (!empty($boundary) && str_starts_with($contentType, 'multipart/')) {
			$this->parseMultipart($msg, $bodyPart, $boundary);
		} else {
			$decoded = $this->decodeContent($bodyPart, $encoding, $charset);
			if ($contentType === 'text/html') {
				$msg->htmlBody = $decoded;
			} else {
				$msg->textBody = $decoded;
			}
		}
	}

	/**
	 * Parse multipart MIME body.
	 */
	private function parseMultipart(Message $msg, string $body, string $boundary): void
	{
		$parts = explode("--{$boundary}", $body);

		foreach ($parts as $part) {
			$part = trim($part);
			if (empty($part) || $part === '--') continue;

			$sections = preg_split('/\r?\n\r?\n/', $part, 2);
			$partHeaders = $sections[0] ?? '';
			$partBody = $sections[1] ?? '';

			$partHeaders = preg_replace('/\r?\n[ \t]+/', ' ', $partHeaders);

			$pContentType = 'text/plain';
			$pCharset = 'utf-8';
			$pEncoding = '7bit';
			$pBoundary = '';

			foreach (explode("\n", $partHeaders) as $line) {
				$line = trim($line);
				if (preg_match('/^Content-Type:\s*([^;\s]+)/i', $line, $m)) {
					$pContentType = strtolower($m[1]);
				}
				if (preg_match('/charset="?([^";\s]+)"?/i', $line, $m)) {
					$pCharset = $m[1];
				}
				if (preg_match('/boundary="?([^";\s]+)"?/i', $line, $m)) {
					$pBoundary = $m[1];
				}
				if (preg_match('/^Content-Transfer-Encoding:\s*(\S+)/i', $line, $m)) {
					$pEncoding = strtolower($m[1]);
				}
			}

			if (!empty($pBoundary) && str_starts_with($pContentType, 'multipart/')) {
				$this->parseMultipart($msg, $partBody, $pBoundary);
			} elseif ($pContentType === 'text/plain' && $msg->textBody === null) {
				$msg->textBody = $this->decodeContent($partBody, $pEncoding, $pCharset);
			} elseif ($pContentType === 'text/html' && $msg->htmlBody === null) {
				$msg->htmlBody = $this->decodeContent($partBody, $pEncoding, $pCharset);
			}
		}
	}

	/**
	 * Decode content based on transfer encoding and charset.
	 */
	private function decodeContent(string $data, string $encoding, string $charset = 'utf-8'): string
	{
		switch ($encoding) {
			case 'base64':
				$data = base64_decode($data);
				break;
			case 'quoted-printable':
				$data = quoted_printable_decode($data);
				break;
		}

		if (strtoupper($charset) !== 'UTF-8' && !empty($charset)) {
			$converted = @iconv($charset, 'UTF-8//IGNORE', $data);
			if ($converted !== false) {
				$data = $converted;
			}
		}

		return $data;
	}

	/**
	 * Parse BODYSTRUCTURE response into Attachment array.
	 * This is a simplified parser for the most common cases.
	 */
	private function parseBodyStructure(string $structure): array
	{
		$attachments = [];
		$partNum = 0;

		// Simple heuristic: look for filename parameters in the structure
		// Full BODYSTRUCTURE parsing is complex — this covers the common cases
		preg_match_all('/"([^"]+)"\s+"([^"]+)"\s+(?:\([^)]*"name"\s+"([^"]+)"[^)]*\)|NIL)/i', $structure, $matches, PREG_SET_ORDER);

		foreach ($matches as $match) {
			$type = strtolower($match[1] ?? '');
			$subtype = strtolower($match[2] ?? '');
			$filename = $match[3] ?? '';

			if (!empty($filename) && $type !== 'text') {
				$partNum++;
				$attachments[] = new Attachment(
					filename: $this->decodeHeader($filename),
					contentType: "{$type}/{$subtype}",
					size: 0,
					partNumber: (string) $partNum,
				);
			}
		}

		return $attachments;
	}

	public function __destruct()
	{
		$this->disconnect();
	}
}
