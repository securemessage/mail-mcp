<?php
/**
 * SecureMessage Mail MCP Server — Connection Management Tools
 *
 * @package    MailMCP\Tools
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

use EnchiladaMCP\McpTool;
use Enchilada\Tortilla\StdioTransport;
use EnchiladaOAuth\OAuthCallbackServer;
use Mail\InstanceManager;
use Mail\OAuthManager;

class ConnectionTools
{
	private InstanceManager $manager;
	private OAuthManager $oauth;
	private ?StdioTransport $transport = null;

    /** @var array<string,array{authUrl:string,callbackUrl:string,server:OAuthCallbackServer,started:int}> One pending OAuth flow per instance (single-flight) */
    private static array $pendingFlows = [];

    /** Seconds a pending flow is resumed before being replaced with a fresh one */
    private const FLOW_TTL = 900;

	public function __construct(InstanceManager $manager)
	{
		$this->manager = $manager;
		$this->oauth = new OAuthManager(null, function($msg) {
			fwrite(STDERR, "[mail-mcp] {$msg}\n");
		});

		// IMAP auto-reconnects must go through OAuthManager so a dropped
		// session gets a silently refreshed access token — never the stale
		// one cached at the last mail_connect (Google tokens live ~1 hour;
		// reusing them surfaces as a misleading AUTHENTICATIONFAILED).
		$this->manager->setImapTokenRefresher(function (string $instance): ?string {
			$config = $this->manager->getConfig($instance);
			return $this->oauth->getAccessToken($instance, $config);
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
	 * Propagate the HTTP transport context (event loop + progress
	 * emitter) to the OAuth clients the OAuthManager builds, so token
	 * exchange/refresh HTTP waits inherit the transport's wait regime.
	 */
	public function setHttpTransport(?\Enchilada\Tortilla\EventLoop $loop, ?callable $progress): void
	{
		$this->oauth->setHttpTransport($loop, $progress);
	}

	/**
	 * Connect to IMAP and SMTP servers for a mail account.
	 */
	#[McpTool(
		name: 'mail_connect',
		description: 'Connect an account\'s IMAP and/or SMTP session; required before other tools on that account. OAuth tokens are cached and refreshed automatically. Do NOT pass force_reauth for dropped connections or AUTHENTICATIONFAILED errors - those are handled by an automatic silent refresh; just call mail_connect again.',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'instance' => ['type' => 'string', 'description' => 'Mail account; omit for default'],
				'imap' => ['type' => 'boolean', 'description' => 'default true'],
				'smtp' => ['type' => 'boolean', 'description' => 'default true'],
				'force_reauth' => ['type' => 'boolean', 'description' => 'OAuth only. Silent token refresh is always tried first and usually suffices. Only set true when refresh keeps failing (revoked grant) - re-authorization then needs a browser.'],
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

			// Always try the silent path first (cached token or refresh-token
			// exchange), even when force_reauth is requested. Clearing valid
			// credentials before confirming they are broken destroys the
			// refresh token and forces an unnecessary browser round-trip.
			$token = $this->oauth->getAccessToken($resolvedName, $config);

			if ($token === null && $force_reauth) {
				// Silent refresh genuinely failed (revoked/expired grant) —
				// only now is it safe to clear and start over. The token
				// file is preserved as <file>.bak just in case.
				$backup = $this->oauth->clearTokens($resolvedName);
				if ($backup !== null) {
					$result['oauth_backup'] = $backup;
				}
			}

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
				if ($force_reauth) {
					$result['oauth_note'] = 'force_reauth requested but silent refresh succeeded; existing tokens were kept (no re-authorization needed)';
				}
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
		description: 'Close IMAP and SMTP sessions for one account or all.',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'instance' => ['type' => 'string', 'description' => 'Mail account; omit to disconnect all'],
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
		readOnlyHint: true,
		description: 'IMAP/SMTP connection and OAuth token state of every account.'
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
		// Single-flight per instance: a pending flow is resumed, never
		// duplicated. Previously every mail_connect minted a new listener
		// and a new browser tab; stale tabs then redirected to orphaned or
		// dead callbacks, which surfaced as hang/timeout at localhost:<port>.
		$pending = self::$pendingFlows[$instanceName] ?? null;
		if ($pending !== null) {
			if (time() - $pending['started'] <= self::FLOW_TTL && $pending['server']->getSocket() !== null) {
				return [
					'instance' => $instanceName,
					'oauth' => 'authorization_pending',
					'authorize_url' => $pending['authUrl'],
					'callback_url' => $pending['callbackUrl'],
					'message' => 'An authorization flow is already in progress. Complete it in the browser tab that was already opened (or open the URL above), then run mail_connect again.',
				];
			}
			// Stale or dead: drop the old listener before starting over
			$pending['server']->close();
			unset(self::$pendingFlows[$instanceName]);
		}

		$oauthClient = $this->oauth->getOAuthClient($instanceName, $config);

		// Generate PKCE pair
		$codeVerifier = \EnchiladaOAuth\Oauth3LO::generateCodeVerifier();
		$codeChallenge = \EnchiladaOAuth\Oauth3LO::generateCodeChallenge($codeVerifier);

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

		self::$pendingFlows[$instanceName] = [
			'authUrl' => $authUrl,
			'callbackUrl' => $callbackUrl,
			'server' => $callbackServer,
			'started' => time(),
		];

		// Register callback listener in the transport event loop, plus a
		// periodic drain: addStream only sees the LISTENER, so a request
		// trickling across SSH/TLS in fragments leaves the accepted
		// connection sitting in the server's pending buffer until another
		// connection happens to arrive. The repeat pumps handleConnection()
		// directly and cancels itself on completion.
		$repeatId = null;
		$cleanup = function () use ($callbackServer, $instanceName, &$repeatId) {
			$callbackServer->close();
			unset(self::$pendingFlows[$instanceName]);
			if ($repeatId !== null) {
				$this->transport?->loop()?->cancel($repeatId);
				$repeatId = null;
			}
		};
		$finish = function ($stream) use ($callbackServer, $oauthClient, $codeVerifier, $instanceName, $cleanup) {
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
				$cleanup();
				if ($stream !== null) {
					$this->transport?->removeStream($stream);
				}
				return false; // Remove from event loop
			}
			if ($callbackServer->getError()) {
				fwrite(STDERR, "[mail-mcp] OAuth error: " . $callbackServer->getError() . "\n");
				$cleanup();
				if ($stream !== null) {
					$this->transport?->removeStream($stream);
				}
				return false; // Remove from event loop
			}
			return true; // Keep listening
		};
		$this->transport->addStream(
			$callbackServer->getSocket(),
			function ($stream) use ($finish) {
				return $finish($stream);
			}
		);
		$eventLoop = $this->transport->loop();
		if ($eventLoop !== null) {
			$repeatId = $eventLoop->repeat(0.25, function () use ($callbackServer, $finish, &$repeatId) {
				if ($callbackServer->getSocket() === null) {
					$this->transport?->loop()?->cancel($repeatId);
					$repeatId = null;
					return;
				}
				$finish($callbackServer->getSocket());
			});
		}

		return [
			'instance' => $instanceName,
			'oauth' => 'authorization_required',
			'authorize_url' => $authUrl,
			'message' => 'Open the URL above to authorize. Once approved, run mail_connect again to complete the connection.',
		];
	}
}
