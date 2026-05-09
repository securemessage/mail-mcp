<?php
/**
 * Mail MCP Server — OAuth Manager
 *
 * Manages OAuth2 authentication flows for mail instances.
 * Handles token persistence, silent refresh, and browser-based authorization.
 *
 * Token files are stored at ~/.config/mail-mcp/tokens/{instance}.json
 *
 * @package    MailMCP\Mail
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace Mail;

use EnchiladaOAuth\EnchiladaOauth3LOClient;
use EnchiladaOAuth\OAuthCallbackServer;

class OAuthManager
{
	/** @var string Directory where token files are stored */
	private string $tokenDir;

	/** @var array<string,EnchiladaOauth3LOClient> Cached OAuth clients per instance */
	private array $oauthClients = [];

	/** @var callable|null Logger function */
	private $logger;

	public function __construct(?string $tokenDir = null, ?callable $logger = null)
	{
		if ($tokenDir === null) {
			$home = getenv('HOME') ?: (getenv('USERPROFILE') ?: sys_get_temp_dir());
			$tokenDir = $home . '/.config/mail-mcp/tokens';
		}

		$this->tokenDir = $tokenDir;
		$this->logger = $logger;

		if (!is_dir($this->tokenDir)) {
			@mkdir($this->tokenDir, 0700, true);
		}
	}

	/**
	 * Get the token file path for an instance.
	 */
	public function getTokenFile(string $instanceName): string
	{
		return $this->tokenDir . DIRECTORY_SEPARATOR . $instanceName . '.json';
	}

	/**
	 * Get or create the OAuth client for an instance.
	 *
	 * @param string $instanceName Instance name
	 * @param array  $config       Instance configuration (must have oauth_* keys)
	 * @return EnchiladaOauth3LOClient
	 */
	public function getOAuthClient(string $instanceName, array $config): EnchiladaOauth3LOClient
	{
		if (!isset($this->oauthClients[$instanceName])) {
			$tokenUrl = $config['oauth_token_url']
				?? throw new \RuntimeException("Missing oauth_token_url for instance '{$instanceName}'");

			// EnchiladaHTTP needs a base URL — use the token endpoint's origin
			$parsed = parse_url($tokenUrl);
			$baseUrl = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '') .
				(isset($parsed['port']) ? ':' . $parsed['port'] : '');

			$http = new \EnchiladaHTTP($baseUrl);

			$client = new EnchiladaOauth3LOClient(
				$http,
				$config['oauth_authorize_url'] ?? '',
				$tokenUrl,
				$config['oauth_client_id'] ?? '',
				$config['oauth_client_secret'] ?? '',
				'', // redirect_uri set dynamically during auth flow
				$config['oauth_scopes'] ?? null,
				$this->getTokenFile($instanceName)
			);

			$this->oauthClients[$instanceName] = $client;
		}

		return $this->oauthClients[$instanceName];
	}

	/**
	 * Get a valid access token for an instance — silently refreshes if possible.
	 *
	 * @param string $instanceName Instance name
	 * @param array  $config       Instance configuration
	 * @return string|null Access token, or null if authorization is required
	 */
	public function getAccessToken(string $instanceName, array $config): ?string
	{
		$client = $this->getOAuthClient($instanceName, $config);

		if ($client->isAuthenticated()) {
			try {
				$token = $client->getAccessToken();
				$this->log("OAuth token obtained for '{$instanceName}' (from cache/refresh)");
				return $token;
			} catch (\Exception $e) {
				$this->log("OAuth token refresh failed for '{$instanceName}': " . $e->getMessage());
				return null;
			}
		}

		return null;
	}

	/**
	 * Run the interactive browser-based OAuth authorization flow.
	 *
	 * Opens a browser for user consent, waits for the callback,
	 * exchanges the code for tokens, and persists them.
	 *
	 * @param string $instanceName Instance name
	 * @param array  $config       Instance configuration
	 * @param int    $timeout      Seconds to wait for browser callback
	 * @return string Access token on success
	 * @throws \RuntimeException On failure
	 */
	public function authorize(string $instanceName, array $config, int $timeout = 120): string
	{
		$client = $this->getOAuthClient($instanceName, $config);

		// Generate PKCE pair
		$codeVerifier = EnchiladaOauth3LOClient::generateCodeVerifier();
		$codeChallenge = EnchiladaOauth3LOClient::generateCodeChallenge($codeVerifier);

		// Generate state for CSRF protection
		$state = bin2hex(random_bytes(16));

		// Start callback server on random port
		$callbackServer = new OAuthCallbackServer('', $state);
		$callbackUrl = $callbackServer->getCallbackUrl();

		// Update redirect URI on the OAuth client
		$client->setRedirectUri($callbackUrl);

		// Build authorization URL
		$authUrl = $client->buildAuthorizationUrl($codeChallenge, $state);

		$this->log("Starting OAuth flow for '{$instanceName}'");
		$this->log("Callback URL: {$callbackUrl}");
		$this->log("Authorize: {$authUrl}");

		// Attempt to open browser (works on local or via VS Code Remote SSH link forwarding)
		OAuthCallbackServer::tryOpenUrl($authUrl);

		// Wait for callback (port is auto-forwarded by VS Code Remote SSH)
		$this->log("Waiting for authorization (timeout: {$timeout}s)...");
		$code = $callbackServer->waitForCallback($timeout);
		$callbackServer->close();

		if ($code === null) {
			$error = $callbackServer->getError();
			throw new \RuntimeException(
				$error
					? "OAuth authorization failed: {$error}"
					: "OAuth authorization timed out after {$timeout}s. No callback received."
			);
		}

		// Exchange code for tokens
		$this->log("Authorization code received, exchanging for tokens...");
		$response = $client->exchangeCode($code, $codeVerifier);

		$this->log("OAuth authorization successful for '{$instanceName}'");
		return $response['access_token'];
	}

	/**
	 * Check if an instance has stored tokens (may still need refresh).
	 */
	public function hasTokens(string $instanceName, array $config): bool
	{
		$client = $this->getOAuthClient($instanceName, $config);
		return $client->isAuthenticated();
	}

	/**
	 * Clear stored tokens for an instance (force re-authorization).
	 */
	public function clearTokens(string $instanceName): void
	{
		$tokenFile = $this->getTokenFile($instanceName);
		if (file_exists($tokenFile)) {
			unlink($tokenFile);
		}
		unset($this->oauthClients[$instanceName]);
	}

	/**
	 * Get the authorization URL without starting the callback server.
	 * Useful when the AI agent wants to present the URL to the user.
	 */
	public function getAuthorizationUrl(string $instanceName, array $config): array
	{
		$client = $this->getOAuthClient($instanceName, $config);

		$codeVerifier = EnchiladaOauth3LOClient::generateCodeVerifier();
		$codeChallenge = EnchiladaOauth3LOClient::generateCodeChallenge($codeVerifier);
		$state = bin2hex(random_bytes(16));

		$callbackServer = new OAuthCallbackServer('', $state);
		$callbackUrl = $callbackServer->getCallbackUrl();
		$client->setRedirectUri($callbackUrl);

		$authUrl = $client->buildAuthorizationUrl($codeChallenge, $state);

		return [
			'url' => $authUrl,
			'state' => $state,
			'code_verifier' => $codeVerifier,
			'callback_server' => $callbackServer,
		];
	}

	private function log(string $message): void
	{
		if ($this->logger) {
			($this->logger)($message);
		}
	}
}
