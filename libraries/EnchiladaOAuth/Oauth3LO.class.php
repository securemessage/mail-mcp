<?php

/* Enchilada Framework 3.0
 * OAuth2 Authorization Code + PKCE Flow (protocol logic, no transport)
 *
 * Pure OAuth 3LO logic: PKCE pair generation, authorization URL
 * building, token-request payload construction, token-response import,
 * expiry/refresh decisions, and token-file persistence. Performs no
 * HTTP — the caller owns the token-endpoint POST through whichever HTTP
 * client it has (the legacy \EnchiladaHTTP wrapper
 * EnchiladaOauth3LOClient, \Enchilada\Tortilla\Oauth3LOClient, or
 * anything equivalent).
 *
 * Software License Agreement (BSD License)
 *
 * Copyright (c) 2026, The Daniel Morante Company, Inc.
 * All rights reserved.
 */

namespace EnchiladaOAuth;

class Oauth3LO {

	/** @var string */
	protected $authorization_endpoint;

	/** @var string */
	protected $token_endpoint;

	/** @var string Token endpoint path relative to the HTTP client's base URL */
	protected $token_endpoint_path;

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
	 * Create a new OAuth 3LO state/logic holder.
	 *
	 * @param string      $authorizationEndpoint Full authorization endpoint URL.
	 * @param string      $tokenEndpoint         Full token endpoint URL (or a path relative
	 *                                           to the caller's HTTP client base URL).
	 * @param string      $clientId              OAuth2 client_id.
	 * @param string      $clientSecret          OAuth2 client_secret.
	 * @param string      $redirectUri           Registered redirect URI.
	 * @param string|null $scope                 Space-separated scope string.
	 * @param string      $tokenFile             Path to file for persisting tokens.
	 */
	public function __construct(
		$authorizationEndpoint,
		$tokenEndpoint,
		$clientId,
		$clientSecret,
		$redirectUri,
		$scope = null,
		$tokenFile = ''
	) {
		$this->authorization_endpoint = $authorizationEndpoint;

		// Extract relative path if full URL given (the HTTP client prepends its base URL)
		$parsed = parse_url($tokenEndpoint);
		$this->token_endpoint = $tokenEndpoint;
		$this->token_endpoint_path = isset($parsed['host']) ? ltrim($parsed['path'] ?? '', '/') : $tokenEndpoint;

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
	 * The token endpoint path as the HTTP layer should send it: relative
	 * when a full URL was given at construction, otherwise as-is.
	 *
	 * @return string
	 */
	public function getTokenEndpointPath(): string {
		return $this->token_endpoint_path;
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
	 * Build the form fields for the authorization_code token exchange.
	 *
	 * @param string $code         The authorization code from the callback.
	 * @param string $codeVerifier The original PKCE code verifier.
	 * @return array Fields to POST urlencoded to the token endpoint.
	 */
	public function buildExchangePayload(string $code, string $codeVerifier): array {
		$data = [
			'grant_type' => 'authorization_code',
			'client_id' => $this->client_id,
			'code' => $code,
			'redirect_uri' => $this->redirect_uri,
			'code_verifier' => $codeVerifier,
		];

		if (!empty($this->client_secret)) {
			$data['client_secret'] = $this->client_secret;
		}

		return $data;
	}

	/**
	 * Build the form fields for a refresh_token grant.
	 *
	 * @return array Fields to POST urlencoded to the token endpoint.
	 */
	public function buildRefreshPayload(): array {
		$data = [
			'grant_type' => 'refresh_token',
			'client_id' => $this->client_id,
			'refresh_token' => $this->refresh_token,
		];

		if (!empty($this->client_secret)) {
			$data['client_secret'] = $this->client_secret;
		}

		return $data;
	}

	/**
	 * Import a token-endpoint response for a code exchange.
	 *
	 * @param mixed $response Decoded JSON body (array), or null/false on transport failure.
	 * @return array The response, for callers that surface provider fields.
	 * @throws \Exception If the exchange failed.
	 */
	public function handleExchangeResponse($response): array {
		if (!is_array($response) || empty($response['access_token'])) {
			$error = is_array($response) ? ($response['error_description'] ?? $response['error'] ?? 'Unknown error') : 'No response';
			throw new \Exception('OAuth code exchange failed: ' . $error);
		}

		$this->access_token = $response['access_token'];
		$this->refresh_token = $response['refresh_token'] ?? null;
		$this->applyExpiry($response);

		if (!empty($response['scope'])) {
			$this->granted_scope = $response['scope'];
		} elseif (!empty($this->scope)) {
			$this->granted_scope = $this->scope;
		}

		$this->saveTokens();

		return $response;
	}

	/**
	 * Import a token-endpoint response for a refresh. A failed refresh
	 * invalidates local tokens (the refresh token may be revoked).
	 *
	 * @param mixed $response Decoded JSON body (array), or null/false on transport failure.
	 * @return string New access token.
	 * @throws \Exception If refresh fails.
	 */
	public function handleRefreshResponse($response): string {
		if (!is_array($response) || empty($response['access_token'])) {
			$this->invalidateTokens();

			$error = is_array($response) ? ($response['error_description'] ?? $response['error'] ?? 'Unknown error') : 'No response';
			throw new \Exception('OAuth token refresh failed: ' . $error . '. Re-authorize required.');
		}

		$this->access_token = $response['access_token'];

		// Some providers rotate refresh tokens
		if (!empty($response['refresh_token'])) {
			$this->refresh_token = $response['refresh_token'];
		}

		$this->applyExpiry($response);
		$this->saveTokens();

		return $this->access_token;
	}

	/**
	 * Clear all token state and persist the cleared file.
	 */
	public function invalidateTokens(): void {
		$this->access_token = null;
		$this->refresh_token = null;
		$this->expires_at = null;
		$this->saveTokens();
	}

	/**
	 * The in-memory access token if it is fresh (> 60s of validity left).
	 *
	 * @return string|null
	 */
	public function getFreshAccessToken(): ?string {
		if (!empty($this->access_token) && !empty($this->expires_at) && $this->expires_at > (time() + 60)) {
			return $this->access_token;
		}
		return null;
	}

	/**
	 * Whether a refresh grant can be attempted.
	 */
	public function hasRefreshToken(): bool {
		return !empty($this->refresh_token);
	}

	/**
	 * Re-read tokens from the persistence file (another process, e.g. a
	 * CLI auth tool, may have written newer tokens since construction).
	 */
	public function reloadTokens(): void {
		$this->loadTokens();
	}

	/**
	 * Whether a valid (non-expired) token or a refresh token exists.
	 * Re-reads from disk when no valid in-memory state exists.
	 *
	 * @return bool True if tokens are available for API use.
	 */
	public function isAuthenticated(): bool {
		if ($this->getFreshAccessToken() !== null) {
			return true;
		}
		if ($this->hasRefreshToken()) {
			return true;
		}

		// Try re-reading from disk
		$this->loadTokens();

		if ($this->getFreshAccessToken() !== null) {
			return true;
		}
		return $this->hasRefreshToken();
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
	 * Compute expires_at from a token response's expires_in.
	 */
	protected function applyExpiry($response): void {
		if (!empty($response['expires_in']) && is_numeric($response['expires_in'])) {
			$this->expires_at = time() + (int)$response['expires_in'];
		} else {
			$this->expires_at = null;
		}
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
