<?php

/* Enchilada Framework 3.0 
 * OAuth2 Client (Authorization Code + PKCE Flow)
 *
 * Helper for obtaining and caching OAuth2 access tokens using the
 * authorization_code grant type with PKCE (RFC 7636).
 *
 * Software License Agreement (BSD License)
 * 
 * Copyright (c) 2026, The Daniel Morante Company, Inc.
 * All rights reserved.
 */

namespace EnchiladaOAuth;

class EnchiladaOauth3LOClient {

	/** @var \EnchiladaHTTP */
	protected $http;

	/** @var string */
	protected $authorization_endpoint;

	/** @var string */
	protected $token_endpoint;

	/** @var string */
	protected $client_id;

	/** @var string */
	protected $client_secret;

	/** @var string */
	protected $redirect_uri;

	/** @var string|null */
	protected $scope;

	/** @var string|null */
	protected $access_token;

	/** @var string|null */
	protected $refresh_token;

	/** @var int|null */
	protected $expires_at;

	/** @var string|null Scope string granted by the authorization server. */
	protected $granted_scope;

	/** @var string */
	protected $token_file;

	/**
	 * Create a new OAuth 3LO client.
	 *
	 * @param \EnchiladaHTTP $http                  HTTP client for token requests.
	 * @param string        $authorizationEndpoint Full authorization endpoint URL.
	 * @param string        $tokenEndpoint         Full token endpoint URL (or relative to HTTP base).
	 * @param string        $clientId              OAuth2 client_id.
	 * @param string        $clientSecret          OAuth2 client_secret.
	 * @param string        $redirectUri           Registered redirect URI.
	 * @param string|null   $scope                 Space-separated scope string.
	 * @param string        $tokenFile             Path to file for persisting tokens.
	 */
	public function __construct(
		\EnchiladaHTTP $http,
		$authorizationEndpoint,
		$tokenEndpoint,
		$clientId,
		$clientSecret,
		$redirectUri,
		$scope = null,
		$tokenFile = ''
	) {
		$this->http = $http;
		$this->authorization_endpoint = $authorizationEndpoint;

		// Extract relative path if full URL given (EnchiladaHTTP prepends its base URL)
		$parsed = parse_url($tokenEndpoint);
		$this->token_endpoint = isset($parsed['host']) ? ltrim($parsed['path'] ?? '', '/') : $tokenEndpoint;

		$this->client_id = $clientId;
		$this->client_secret = $clientSecret;
		$this->redirect_uri = $redirectUri;
		$this->scope = $scope;

		if (empty($tokenFile)) {
			$tokenFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'enchilada_oauth3lo_tokens.json';
		}
		$this->token_file = $tokenFile;

		$this->loadTokens();
	}

	/**
	 * Update the redirect URI (e.g., when using a dynamic port from OAuthCallbackServer).
	 *
	 * @param string $redirectUri New redirect URI
	 */
	public function setRedirectUri(string $redirectUri): void {
		$this->redirect_uri = $redirectUri;
	}

	/**
	 * Generate a cryptographically random code verifier (RFC 7636).
	 *
	 * @return string Base64url-encoded random string (43-128 chars).
	 */
	public static function generateCodeVerifier(): string {
		$bytes = random_bytes(32);
		return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
	}

	/**
	 * Generate a code challenge from a code verifier using S256.
	 *
	 * @param string $verifier The code verifier.
	 * @return string Base64url-encoded SHA-256 hash.
	 */
	public static function generateCodeChallenge(string $verifier): string {
		$hash = hash('sha256', $verifier, true);
		return rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
	}

	/**
	 * Build the authorization URL for the consent flow.
	 *
	 * @param string      $codeChallenge The PKCE code challenge (S256).
	 * @param string|null $state         Optional state parameter for CSRF protection.
	 * @return string Full authorization URL to redirect the user to.
	 */
	public function buildAuthorizationUrl(string $codeChallenge, ?string $state = null): string {
		$params = [
			'client_id' => $this->client_id,
			'redirect_uri' => $this->redirect_uri,
			'response_type' => 'code',
			'code_challenge' => $codeChallenge,
			'code_challenge_method' => 'S256',
			'prompt' => 'consent',
		];

		if (!empty($this->scope)) {
			$params['scope'] = $this->scope;
		}

		if ($state !== null) {
			$params['state'] = $state;
		}

		return $this->authorization_endpoint . '?' . http_build_query($params);
	}

	/**
	 * Exchange an authorization code for tokens.
	 *
	 * @param string $code         The authorization code from the callback.
	 * @param string $codeVerifier The original PKCE code verifier.
	 * @return array Token response (access_token, refresh_token, expires_in, scope).
	 * @throws Exception If the exchange fails.
	 */
	public function exchangeCode(string $code, string $codeVerifier): array {
		$data = [
			'grant_type' => 'authorization_code',
			'client_id' => $this->client_id,
			'client_secret' => $this->client_secret,
			'code' => $code,
			'redirect_uri' => $this->redirect_uri,
			'code_verifier' => $codeVerifier,
		];

		$response = $this->http->call($this->token_endpoint, http_build_query($data), 'POST',
			['Content-Type: application/x-www-form-urlencoded'], null, 'json');

		if (!is_array($response) || empty($response['access_token'])) {
			$error = is_array($response) ? ($response['error_description'] ?? $response['error'] ?? 'Unknown error') : 'No response';
			throw new \Exception('OAuth code exchange failed: ' . $error);
		}

		$this->access_token = $response['access_token'];
		$this->refresh_token = $response['refresh_token'] ?? null;

		if (!empty($response['expires_in']) && is_numeric($response['expires_in'])) {
			$this->expires_at = time() + (int)$response['expires_in'];
		} else {
			$this->expires_at = null;
		}

		if (!empty($response['scope'])) {
			$this->granted_scope = $response['scope'];
		} elseif (!empty($this->scope)) {
			$this->granted_scope = $this->scope;
		}

		$this->saveTokens();

		return $response;
	}

	/**
	 * Returns a valid access token, refreshing if necessary.
	 *
	 * Re-reads the token file from disk if no valid in-memory token exists.
	 * This handles the case where tokens were obtained by an external process
	 * (e.g., CLI auth tool) after this instance was constructed.
	 *
	 * @return string
	 * @throws Exception When no valid token is available and refresh fails.
	 */
	public function getAccessToken(): string {
		if (!empty($this->access_token) && !empty($this->expires_at) && $this->expires_at > (time() + 60)) {
			return $this->access_token;
		}

		if (!empty($this->refresh_token)) {
			return $this->refreshAccessToken();
		}

		// Try re-reading from disk — tokens may have been written by another process
		$this->loadTokens();

		if (!empty($this->access_token) && !empty($this->expires_at) && $this->expires_at > (time() + 60)) {
			return $this->access_token;
		}

		if (!empty($this->refresh_token)) {
			return $this->refreshAccessToken();
		}

		throw new \Exception('No valid access token available. Run the authorization flow first.');
	}

	/**
	 * Apply the Authorization header to the provided headers array.
	 *
	 * @param array $headers Existing headers (array of "Header: value" strings).
	 * @return array Updated headers including Authorization.
	 */
	public function applyAuthorizationHeader(array $headers = []): array {
		$token = $this->getAccessToken();
		$headers[] = 'Authorization: Bearer ' . $token;
		return $headers;
	}

	/**
	 * Check if we have a valid (non-expired) token or a refresh token.
	 *
	 * Re-reads from disk if no valid in-memory state exists.
	 *
	 * @return bool True if tokens are available for API use.
	 */
	public function isAuthenticated(): bool {
		if (!empty($this->access_token) && !empty($this->expires_at) && $this->expires_at > (time() + 60)) {
			return true;
		}
		if (!empty($this->refresh_token)) {
			return true;
		}

		// Try re-reading from disk
		$this->loadTokens();

		if (!empty($this->access_token) && !empty($this->expires_at) && $this->expires_at > (time() + 60)) {
			return true;
		}
		return !empty($this->refresh_token);
	}

	/**
	 * Get the current refresh token (e.g., for inspection or manual persistence).
	 *
	 * @return string|null
	 */
	public function getRefreshToken(): ?string {
		return $this->refresh_token;
	}

	/**
	 * Get the scope string that was granted during the last authorization.
	 *
	 * @return string|null
	 */
	public function getGrantedScope(): ?string {
		return $this->granted_scope;
	}

	/**
	 * Refresh the access token using the stored refresh token.
	 *
	 * @return string New access token.
	 * @throws Exception If refresh fails.
	 */
	protected function refreshAccessToken(): string {
		$data = [
			'grant_type' => 'refresh_token',
			'client_id' => $this->client_id,
			'client_secret' => $this->client_secret,
			'refresh_token' => $this->refresh_token,
		];

		$response = $this->http->call($this->token_endpoint, http_build_query($data), 'POST',
			['Content-Type: application/x-www-form-urlencoded'], null, 'json');

		if (!is_array($response) || empty($response['access_token'])) {
			// Refresh token may have been revoked
			$this->access_token = null;
			$this->refresh_token = null;
			$this->expires_at = null;
			$this->saveTokens();

			$error = is_array($response) ? ($response['error_description'] ?? $response['error'] ?? 'Unknown error') : 'No response';
			throw new \Exception('OAuth token refresh failed: ' . $error . '. Re-authorize required.');
		}

		$this->access_token = $response['access_token'];

		// Some providers rotate refresh tokens
		if (!empty($response['refresh_token'])) {
			$this->refresh_token = $response['refresh_token'];
		}

		if (!empty($response['expires_in']) && is_numeric($response['expires_in'])) {
			$this->expires_at = time() + (int)$response['expires_in'];
		} else {
			$this->expires_at = null;
		}

		$this->saveTokens();

		return $this->access_token;
	}

	/**
	 * Persist tokens to a JSON file.
	 */
	protected function saveTokens(): void {
		$dir = dirname($this->token_file);
		if (!is_dir($dir)) {
			mkdir($dir, 0700, true);
		}

		$data = [
			'access_token' => $this->access_token,
			'refresh_token' => $this->refresh_token,
			'expires_at' => $this->expires_at,
			'granted_scope' => $this->granted_scope,
		];

		file_put_contents($this->token_file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
		chmod($this->token_file, 0600);
	}

	/**
	 * Load tokens from the persistence file.
	 */
	protected function loadTokens(): void {
		if (!file_exists($this->token_file)) {
			return;
		}

		$contents = file_get_contents($this->token_file);
		$data = json_decode($contents, true);

		if (!is_array($data)) {
			return;
		}

		$this->access_token = $data['access_token'] ?? null;
		$this->refresh_token = $data['refresh_token'] ?? null;
		$this->expires_at = $data['expires_at'] ?? null;
		$this->granted_scope = $data['granted_scope'] ?? null;
	}
}
