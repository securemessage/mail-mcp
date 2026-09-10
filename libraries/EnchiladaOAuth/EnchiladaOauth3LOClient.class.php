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

	/** @var Oauth3LO */
	protected $oauth;

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
		$this->oauth = new Oauth3LO(
			$authorizationEndpoint,
			$tokenEndpoint,
			$clientId,
			$clientSecret,
			$redirectUri,
			$scope,
			$tokenFile
		);
	}

	/**
	 * Update the redirect URI (e.g., when using a dynamic port from OAuthCallbackServer).
	 *
	 * @param string $redirectUri New redirect URI
	 */
	public function setRedirectUri(string $redirectUri): void {
		$this->oauth->setRedirectUri($redirectUri);
	}

	/**
	 * Generate a cryptographically random code verifier (RFC 7636).
	 *
	 * @return string Base64url-encoded random string (43-128 chars).
	 */
	public static function generateCodeVerifier(): string {
		return Oauth3LO::generateCodeVerifier();
	}

	/**
	 * Generate a code challenge from a code verifier using S256.
	 *
	 * @param string $verifier The code verifier.
	 * @return string Base64url-encoded SHA-256 hash.
	 */
	public static function generateCodeChallenge(string $verifier): string {
		return Oauth3LO::generateCodeChallenge($verifier);
	}

	/**
	 * The raw authorization-endpoint URL, for consumers that assemble the
	 * full URL themselves from getAuthorizationParams().
	 */
	public function getAuthorizationEndpoint(): string {
		return $this->oauth->getAuthorizationEndpoint();
	}

	/**
	 * The authorization-flow query parameters.
	 */
	public function getAuthorizationParams(string $codeChallenge, ?string $state = null): array {
		return $this->oauth->getAuthorizationParams($codeChallenge, $state);
	}

	/**
	 * Build the authorization URL for the consent flow.
	 *
	 * @param string      $codeChallenge The PKCE code challenge (S256).
	 * @param string|null $state         Optional state parameter for CSRF protection.
	 * @return string Full authorization URL to redirect the user to.
	 */
	public function buildAuthorizationUrl(string $codeChallenge, ?string $state = null): string {
		return $this->oauth->buildAuthorizationUrl($codeChallenge, $state);
	}

	/**
	 * Exchange an authorization code for tokens.
	 *
	 * @param string $code         The authorization code from the callback.
	 * @param string $codeVerifier The original PKCE code verifier.
	 * @return array Token response (access_token, refresh_token, expires_in, scope).
	 * @throws \Exception If the exchange fails.
	 */
	public function exchangeCode(string $code, string $codeVerifier): array {
		$response = $this->http->call($this->oauth->getTokenEndpointPath(),
			http_build_query($this->oauth->buildExchangePayload($code, $codeVerifier)), 'POST',
			['Content-Type: application/x-www-form-urlencoded'], null, 'json');

		return $this->oauth->handleExchangeResponse($response);
	}

	/**
	 * Returns a valid access token, refreshing if necessary.
	 *
	 * Re-reads the token file from disk if no valid in-memory token exists.
	 * This handles the case where tokens were obtained by an external process
	 * (e.g., CLI auth tool) after this instance was constructed.
	 *
	 * @return string
	 * @throws \Exception When no valid token is available and refresh fails.
	 */
	public function getAccessToken(): string {
		$token = $this->oauth->getFreshAccessToken();
		if ($token !== null) {
			return $token;
		}

		if ($this->oauth->hasRefreshToken()) {
			return $this->refreshAccessToken();
		}

		// Try re-reading from disk — tokens may have been written by another process
		$this->oauth->reloadTokens();

		$token = $this->oauth->getFreshAccessToken();
		if ($token !== null) {
			return $token;
		}

		if ($this->oauth->hasRefreshToken()) {
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
		$headers[] = 'Authorization: Bearer ' . $this->getAccessToken();
		return $headers;
	}

	/**
	 * Check if we have a valid (non-expired) token or a refresh token.
	 *
	 * @return bool True if tokens are available for API use.
	 */
	public function isAuthenticated(): bool {
		return $this->oauth->isAuthenticated();
	}

	/**
	 * Get the current refresh token (e.g., for inspection or manual persistence).
	 *
	 * @return string|null
	 */
	public function getRefreshToken(): ?string {
		return $this->oauth->getRefreshToken();
	}

	/**
	 * Get the scope string that was granted during the last authorization.
	 *
	 * @return string|null
	 */
	public function getGrantedScope(): ?string {
		return $this->oauth->getGrantedScope();
	}

	/**
	 * Refresh the access token using the stored refresh token.
	 *
	 * @return string New access token.
	 * @throws \Exception If refresh fails.
	 */
	protected function refreshAccessToken(): string {
		$response = $this->http->call($this->oauth->getTokenEndpointPath(),
			http_build_query($this->oauth->buildRefreshPayload()), 'POST',
			['Content-Type: application/x-www-form-urlencoded'], null, 'json');

		return $this->oauth->handleRefreshResponse($response);
	}
}
