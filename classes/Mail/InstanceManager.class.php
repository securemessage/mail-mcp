<?php
/**
 * SecureMessage Mail MCP Server — Instance Manager
 *
 * Multi-account configuration registry. Each instance represents a
 * mail account with IMAP + SMTP settings and optional OAuth config.
 *
 * @package    MailMCP\Mail
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace Mail;

class InstanceManager
{
	/** @var array<string,array> Instance configurations indexed by name */
	private array $instances;

	/** @var string Name of the current default instance */
	private string $default;

	/** @var array<string,ImapClientInterface> Cached IMAP clients */
	private array $imapClients = [];

	/** @var array<string,SmtpClientInterface> Cached SMTP clients */
	private array $smtpClients = [];

	/** @var array<string,string|null> Stored OAuth tokens for auto-reconnect */
	private array $imapTokens = [];

	/** @var array<string,bool> Tracks which instances have been successfully connected */
	private array $imapConnected = [];

	public function __construct(array $instances, string $default)
	{
		if (empty($instances)) {
			throw new \InvalidArgumentException('At least one mail instance must be configured.');
		}

		if (!isset($instances[$default])) {
			throw new \InvalidArgumentException("Default instance '{$default}' not found in configuration.");
		}

		$this->instances = $instances;
		$this->default = $default;
	}

	/**
	 * Create an InstanceManager from a JSON configuration file.
	 */
	public static function fromFile(string $path): self
	{
		if (!file_exists($path)) {
			throw new \RuntimeException("Configuration file not found: {$path}");
		}

		$json = file_get_contents($path);
		if ($json === false) {
			throw new \RuntimeException("Failed to read configuration file: {$path}");
		}

		$config = json_decode($json, true);
		if ($config === null && json_last_error() !== JSON_ERROR_NONE) {
			throw new \RuntimeException(
				"Invalid JSON in configuration file {$path}: " . json_last_error_msg()
			);
		}

		$instances = $config['instances'] ?? [];
		$default = $config['default'] ?? '';

		if (empty($default) && !empty($instances)) {
			$default = array_key_first($instances);
		}

		return new self($instances, $default);
	}

	/**
	 * Get the configuration for an instance.
	 *
	 * @param  string|null $name Instance name (null = default)
	 * @return array               Instance configuration
	 */
	public function getConfig(?string $name = null): array
	{
		$name = $name ?: $this->default;

		if (!isset($this->instances[$name])) {
			$available = implode(', ', array_keys($this->instances));
			throw new \InvalidArgumentException(
				"Unknown mail instance '{$name}'. Available: {$available}"
			);
		}

		return $this->instances[$name];
	}

	/**
	 * Get or create an IMAP client for the named instance.
	 *
	 * @param  string|null $name Instance name (null = default)
	 * @return ImapClientInterface
	 */
	public function getImapClient(?string $name = null): ImapClientInterface
	{
		$name = $name ?: $this->default;

		if (!isset($this->imapClients[$name])) {
			$config = $this->getConfig($name);
			$verifySsl = $config['verify_ssl'] ?? true;
			$client = new SocketImapClient($config['timeout'] ?? 30, $verifySsl);
			$this->imapClients[$name] = $client;
			return $client;
		}

		$client = $this->imapClients[$name];

		// Auto-reconnect if previously connected but connection dropped
		if (isset($this->imapConnected[$name]) && !$client->isConnected()) {
			$this->reconnectImap($name);
		}

		return $this->imapClients[$name];
	}

	/**
	 * Get or create an SMTP client for the named instance.
	 *
	 * @param  string|null $name Instance name (null = default)
	 * @return SmtpClientInterface
	 */
	public function getSmtpClient(?string $name = null): SmtpClientInterface
	{
		$name = $name ?: $this->default;

		if (!isset($this->smtpClients[$name])) {
			$config = $this->getConfig($name);
			$verifySsl = $config['verify_ssl'] ?? true;
			$client = new SocketSmtpClient($config['timeout'] ?? 30, $verifySsl);
			$this->smtpClients[$name] = $client;
		}

		return $this->smtpClients[$name];
	}

	/**
	 * Connect IMAP client for an instance (handles auth type dispatch).
	 *
	 * @param  string|null $name        Instance name
	 * @param  string|null $accessToken OAuth2 access token (if auth_type is xoauth2)
	 */
	public function connectImap(?string $name = null, ?string $accessToken = null): void
	{
		$name = $name ?: $this->default;
		$config = $this->getConfig($name);
		$client = $this->getImapClient($name);

		$client->connect(
			$config['imap_host'],
			$config['imap_port'] ?? 993,
			$config['tls'] ?? true,
			$config['starttls'] ?? true
		);

		$authType = $config['auth_type'] ?? 'basic';

		if ($authType === 'xoauth2') {
			if (empty($accessToken)) {
				throw new \RuntimeException("OAuth2 access token required for instance '{$name}'");
			}
			$client->authenticateXOAuth2($config['username'], $accessToken);
		} else {
			$client->login($config['username'], $config['password'] ?? '');
		}

		// Store credentials for auto-reconnect
		$this->imapTokens[$name] = $accessToken;
		$this->imapConnected[$name] = true;
	}

	/**
	 * Connect SMTP client for an instance (handles auth type dispatch).
	 *
	 * @param  string|null $name        Instance name
	 * @param  string|null $accessToken OAuth2 access token (if auth_type is xoauth2)
	 */
	public function connectSmtp(?string $name = null, ?string $accessToken = null): void
	{
		$name = $name ?: $this->default;
		$config = $this->getConfig($name);
		$client = $this->getSmtpClient($name);

		// smtp_tls overrides tls for SMTP (587=STARTTLS needs tls=false, 465=implicit needs tls=true)
		$smtpTls = $config['smtp_tls'] ?? $config['tls'] ?? true;
		$smtpStarttls = $config['smtp_starttls'] ?? $config['starttls'] ?? true;

		$client->connect(
			$config['smtp_host'],
			$config['smtp_port'] ?? 465,
			$smtpTls,
			$smtpStarttls
		);

		$authType = $config['auth_type'] ?? 'basic';

		if ($authType === 'xoauth2') {
			if (empty($accessToken)) {
				throw new \RuntimeException("OAuth2 access token required for instance '{$name}'");
			}
			$client->authenticateXOAuth2($config['username'], $accessToken);
		} else {
			$client->authenticate($config['username'], $config['password'] ?? '');
		}
	}

	/**
	 * Check if an instance uses OAuth authentication.
	 */
	public function isOAuth(?string $name = null): bool
	{
		$config = $this->getConfig($name ?: $this->default);
		return ($config['auth_type'] ?? 'basic') === 'xoauth2';
	}

	/**
	 * List all configured instances.
	 */
	public function listInstances(): array
	{
		$result = [];
		foreach ($this->instances as $name => $config) {
			$imapClient = $this->imapClients[$name] ?? null;
			$smtpClient = $this->smtpClients[$name] ?? null;

			$result[$name] = [
				'description' => $config['description'] ?? '',
				'username' => $config['username'] ?? '',
				'imap_host' => $config['imap_host'] ?? '',
				'smtp_host' => $config['smtp_host'] ?? '',
				'auth_type' => $config['auth_type'] ?? 'basic',
				'is_default' => ($name === $this->default),
				'imap_connected' => $imapClient !== null && $imapClient->isConnected(),
				'smtp_connected' => $smtpClient !== null && $smtpClient->isConnected(),
			];
		}
		return $result;
	}

	public function getDefault(): string
	{
		return $this->default;
	}

	public function setDefault(string $name): void
	{
		if (!isset($this->instances[$name])) {
			$available = implode(', ', array_keys($this->instances));
			throw new \InvalidArgumentException(
				"Unknown instance '{$name}'. Available: {$available}"
			);
		}

		$this->default = $name;
	}

	public function hasInstance(string $name): bool
	{
		return isset($this->instances[$name]);
	}

	public function count(): int
	{
		return count($this->instances);
	}

	/**
	 * Disconnect all clients.
	 */
	public function disconnectAll(): void
	{
		foreach ($this->imapClients as $name => $client) {
			$client->disconnect();
			unset($this->imapConnected[$name]);
		}
		foreach ($this->smtpClients as $client) {
			$client->disconnect();
		}
	}

	/**
	 * Transparently reconnect a dropped IMAP connection.
	 *
	 * Uses stored credentials from the last successful connectImap() call.
	 * Restores the previously selected mailbox if any.
	 *
	 * @param string $name Instance name
	 * @throws \RuntimeException If reconnection or re-authentication fails
	 */
	private function reconnectImap(string $name): void
	{
		$config = $this->getConfig($name);
		$client = $this->imapClients[$name];

		// Remember current mailbox for restoration
		$lastMailbox = $client->getCurrentMailbox();

		// Clean up the dead socket
		try {
			$client->disconnect();
		} catch (\Throwable $e) {
			// Ignore — socket is already dead
		}

		// Reconnect
		$client->connect(
			$config['imap_host'],
			$config['imap_port'] ?? 993,
			$config['tls'] ?? true,
			$config['starttls'] ?? true
		);

		// Re-authenticate with stored credentials
		$authType = $config['auth_type'] ?? 'basic';

		if ($authType === 'xoauth2') {
			$token = $this->imapTokens[$name] ?? null;
			if (empty($token)) {
				unset($this->imapConnected[$name]);
				throw new \RuntimeException(
					"IMAP connection dropped for '{$name}' and no OAuth token available for reconnect. Run mail_connect to re-authorize."
				);
			}
			$client->authenticateXOAuth2($config['username'], $token);
		} else {
			$client->login($config['username'], $config['password'] ?? '');
		}

		// Restore previously selected mailbox
		if ($lastMailbox !== null) {
			try {
				$client->selectMailbox($lastMailbox);
			} catch (\Throwable $e) {
				// Non-fatal — mailbox may have been deleted
			}
		}
	}
}
