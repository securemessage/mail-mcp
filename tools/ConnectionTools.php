<?php
/**
 * Mail MCP Server — Connection Management Tools
 *
 * @package    MailMCP\Tools
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

use EnchiladaMCP\McpTool;
use EnchiladaMCP\StdioTransport;
use EnchiladaOAuth\EnchiladaOauth3LOClient;
use EnchiladaOAuth\OAuthCallbackServer;
use Mail\InstanceManager;
use Mail\OAuthManager;

class ConnectionTools
{
	private InstanceManager $manager;
	private OAuthManager $oauth;
	private ?StdioTransport $transport = null;

	public function __construct(InstanceManager $manager)
	{
		$this->manager = $manager;
		$this->oauth = new OAuthManager(null, function($msg) {
			fwrite(STDERR, "[mail-mcp] {$msg}\n");
		});
	}

	/**
	 * Inject the transport for non-blocking OAuth and notifications.
	 */
	public function setTransport(StdioTransport $transport): void
	{
		$this->transport = $transport;
	}

	/**
	 * Connect to IMAP and SMTP servers for a mail account.
	 */
	#[McpTool(
		name: 'mail_connect',
		description: 'Connect to IMAP and SMTP servers for a mail account. For OAuth accounts, authentication is handled automatically (tokens are cached and refreshed silently). Use force_reauth=true to re-authorize if tokens are invalid.',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'instance' => ['type' => 'string', 'description' => 'Mail account name (optional, uses default)'],
				'imap' => ['type' => 'boolean', 'description' => 'Connect IMAP (default: true)'],
				'smtp' => ['type' => 'boolean', 'description' => 'Connect SMTP (default: true)'],
				'force_reauth' => ['type' => 'boolean', 'description' => 'Force re-authorization for OAuth accounts (clears cached tokens)'],
			],
		]
	)]
	public function mail_connect(string $instance = '', bool $imap = true, bool $smtp = true, bool $force_reauth = false): array
	{
		$name = $instance ?: null;
		$resolvedName = $instance ?: $this->manager->getDefault();
		$result = ['instance' => $resolvedName];
		$token = null;

		// Handle OAuth token acquisition
		if ($this->manager->isOAuth($name)) {
			$config = $this->manager->getConfig($name);

			if ($force_reauth) {
				$this->oauth->clearTokens($resolvedName);
			}

			// Try silent token retrieval (cached or refresh)
			$token = $this->oauth->getAccessToken($resolvedName, $config);

			if ($token === null) {
				// Need interactive authorization — use non-blocking flow if transport available
				if ($this->transport !== null) {
					return $this->startNonBlockingOAuth($resolvedName, $config);
				}

				// Fallback: blocking flow (standalone/test mode)
				try {
					$token = $this->oauth->authorize($resolvedName, $config);
					$result['oauth'] = 'authorized (new tokens obtained)';
				} catch (\Throwable $e) {
					$result['oauth'] = 'failed: ' . $e->getMessage();
					$result['hint'] = 'Ensure oauth_client_id, oauth_client_secret, oauth_authorize_url, and oauth_token_url are correctly configured in instances.json.';
					return $result;
				}
			} else {
				$result['oauth'] = 'authenticated (cached token)';
			}
		}

		try {
			if ($imap) {
				$this->manager->connectImap($name, $token);
				$result['imap'] = 'connected';
			}
		} catch (\Throwable $e) {
			$result['imap'] = 'failed: ' . $e->getMessage();
		}

		try {
			if ($smtp) {
				$this->manager->connectSmtp($name, $token);
				$result['smtp'] = 'connected';
			}
		} catch (\Throwable $e) {
			$result['smtp'] = 'failed: ' . $e->getMessage();
		}

		return $result;
	}

	/**
	 * Disconnect from mail servers.
	 */
	#[McpTool(
		name: 'mail_disconnect',
		description: 'Disconnect from IMAP and SMTP servers for a mail account, or all accounts.',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'instance' => ['type' => 'string', 'description' => 'Mail account name (optional, empty = disconnect all)'],
			],
		]
	)]
	public function mail_disconnect(string $instance = ''): array
	{
		if (empty($instance)) {
			$this->manager->disconnectAll();
			return ['disconnected' => 'all'];
		}

		$imap = $this->manager->getImapClient($instance ?: null);
		$smtp = $this->manager->getSmtpClient($instance ?: null);
		$imap->disconnect();
		$smtp->disconnect();

		return ['disconnected' => $instance ?: $this->manager->getDefault()];
	}

	/**
	 * Show connection status for all accounts.
	 */
	#[McpTool(
		name: 'mail_connection_status',
		description: 'Show IMAP and SMTP connection status for all configured mail accounts, including OAuth token state.'
	)]
	public function mail_connection_status(): array
	{
		$instances = $this->manager->listInstances();

		// Enrich OAuth instances with token state
		foreach ($instances as $name => &$info) {
			if ($info['auth_type'] === 'xoauth2') {
				$config = $this->manager->getConfig($name);
				$info['oauth_has_tokens'] = $this->oauth->hasTokens($name, $config);
			}
		}

		return $instances;
	}

	/**
	 * Start non-blocking OAuth flow: returns URL immediately, handles callback in event loop.
	 */
	private function startNonBlockingOAuth(string $instanceName, array $config): array
	{
		$oauthClient = $this->oauth->getOAuthClient($instanceName, $config);

		// Generate PKCE pair
		$codeVerifier = EnchiladaOauth3LOClient::generateCodeVerifier();
		$codeChallenge = EnchiladaOauth3LOClient::generateCodeChallenge($codeVerifier);

		// Generate state for CSRF protection
		$state = bin2hex(random_bytes(16));

		// Start callback server on random port
		$callbackServer = new OAuthCallbackServer('', $state);
		$callbackUrl = $callbackServer->getCallbackUrl();

		// Update redirect URI on the OAuth client
		$oauthClient->setRedirectUri($callbackUrl);

		// Build authorization URL
		$authUrl = $oauthClient->buildAuthorizationUrl($codeChallenge, $state);

		// Send notification to IDE (shows as clickable link)
		$this->transport->sendLogMessage(
			'warning',
			"Mail authorization required: {$authUrl}",
			'mail-mcp'
		);

		// Try to open browser (works locally or via VS Code Remote SSH)
		OAuthCallbackServer::tryOpenUrl($authUrl);

		// Register callback listener in the transport event loop
		$this->transport->addStream(
			$callbackServer->getSocket(),
			function ($stream) use ($callbackServer, $oauthClient, $codeVerifier, $instanceName) {
				$code = $callbackServer->handleConnection();
				if ($code !== null) {
					try {
						$oauthClient->exchangeCode($code, $codeVerifier);
						fwrite(STDERR, "[mail-mcp] Authorization successful for '{$instanceName}'.\n");

						if ($this->transport !== null) {
							$this->transport->sendLogMessage(
								'info',
								"Authorization successful for '{$instanceName}'. Tokens saved. Run mail_connect again to connect.",
								'mail-mcp'
							);
						}
					} catch (\Throwable $e) {
						fwrite(STDERR, "[mail-mcp] Token exchange failed: " . $e->getMessage() . "\n");
						@file_put_contents('/tmp/mail-mcp-oauth-debug.log', date('c') . " Token exchange failed for '{$instanceName}': " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n", FILE_APPEND);
					}
					$callbackServer->close();
					return false; // Remove from event loop
				}
				return true; // Keep listening
			}
		);

		return [
			'instance' => $instanceName,
			'oauth' => 'authorization_required',
			'authorize_url' => $authUrl,
			'message' => 'Open the URL above to authorize. Once approved, run mail_connect again to complete the connection.',
		];
	}
}
