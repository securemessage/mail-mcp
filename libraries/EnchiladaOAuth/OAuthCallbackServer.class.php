<?php

/* Enchilada Framework 3.0
 * OAuth Callback Server
 *
 * Temporary HTTP listener for receiving OAuth 2.0 authorization callbacks.
 * Binds to a random available port on localhost, designed for integration
 * with an event loop (stream_select) or standalone blocking use.
 *
 * Usage:
 *   $server = new OAuthCallbackServer('/callback', $state);
 *   echo "Authorize at: " . $authUrl . "&redirect_uri=" . $server->getCallbackUrl();
 *   // In event loop: stream_select on $server->getSocket()
 *   // When readable: $code = $server->handleConnection();
 *
 * Software License Agreement (BSD License)
 * 
 * Copyright (c) 2026, The Daniel Morante Company, Inc.
 * All rights reserved.
 */

namespace EnchiladaOAuth;

class OAuthCallbackServer
{
	/** @var resource|null */
	private $socket = null;

	/** @var int */
	private int $port = 0;

	/** @var string */
	private string $path;

	/** @var string */
	private string $expectedState;

	/** @var string|null */
	private ?string $receivedCode = null;

	/** @var string|null */
	private ?string $error = null;

	/**
	 * Create and start the callback server.
	 *
	 * Binds to 127.0.0.1 on a random available port (port 0 = OS assigns).
	 *
	 * @param string $path  Expected callback path (e.g., '/callback')
	 * @param string $state Expected state parameter for CSRF validation
	 * @throws \RuntimeException If socket cannot be created
	 */
	public function __construct(string $path = '/callback', string $state = '')
	{
		$this->path = $path;
		$this->expectedState = $state;

		$this->socket = @stream_socket_server(
			"tcp://127.0.0.1:0",
			$errno,
			$errstr,
			STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
		);

		if (!$this->socket) {
			throw new \RuntimeException("OAuthCallbackServer: could not bind: [{$errno}] {$errstr}");
		}

		// Determine the assigned port
		$name = stream_socket_get_name($this->socket, false);
		$this->port = (int)substr($name, strrpos($name, ':') + 1);

		stream_set_blocking($this->socket, false);
	}

	/**
	 * Get the port the server is listening on.
	 *
	 * @return int
	 */
	public function getPort(): int
	{
		return $this->port;
	}

	/**
	 * Get the listening socket resource (for use with stream_select).
	 *
	 * @return resource
	 */
	public function getSocket()
	{
		return $this->socket;
	}

	/**
	 * Get the full callback URL to use as the OAuth redirect_uri.
	 *
	 * @return string e.g., "http://127.0.0.1:54321/callback"
	 */
	public function getCallbackUrl(): string
	{
		return "http://127.0.0.1:{$this->port}{$this->path}";
	}

	/**
	 * Handle an incoming connection on the socket.
	 *
	 * Call this when stream_select indicates the socket is readable.
	 * Accepts the connection, parses the HTTP request, validates state,
	 * sends an HTTP response to the browser, and returns the auth code.
	 *
	 * @return string|null Authorization code, or null if not received/invalid
	 */
	public function handleConnection(): ?string
	{
		$conn = @stream_socket_accept($this->socket, 5);
		if (!$conn) {
			return null;
		}

		// Ensure blocking read — SSH tunnels may have latency
		stream_set_blocking($conn, true);
		stream_set_timeout($conn, 5);
		$request = @fread($conn, 8192);
		if (empty($request)) {
			fclose($conn);
			return null;
		}

		// Parse the GET request line
		if (!preg_match('/GET\s+([^\s]+)/', $request, $matches)) {
			$this->sendResponse($conn, 400, "Invalid request");
			fclose($conn);
			return null;
		}

		$requestUri = $matches[1];
		$parts = parse_url($requestUri);
		parse_str($parts['query'] ?? '', $queryParams);

		// Check for error response from authorization server
		if (isset($queryParams['error'])) {
			$this->error = $queryParams['error_description'] ?? $queryParams['error'];
			$this->sendResponse($conn, 400, "Authorization failed: {$this->error}");
			fclose($conn);
			return null;
		}

		// Check for authorization code
		if (!isset($queryParams['code'])) {
			$this->sendResponse($conn, 400, "No authorization code received");
			fclose($conn);
			return null;
		}

		// Validate state if expected
		if (!empty($this->expectedState)) {
			$receivedState = $queryParams['state'] ?? '';
			if ($receivedState !== $this->expectedState) {
				$this->sendResponse($conn, 400, "State mismatch. Possible CSRF attack.");
				fclose($conn);
				return null;
			}
		}

		$this->receivedCode = $queryParams['code'];
		$this->sendResponse($conn, 200, "Authorization successful! You can close this window and return to your application.");
		fclose($conn);

		return $this->receivedCode;
	}

	/**
	 * Wait for the callback with a timeout (blocking mode).
	 *
	 * Loops to handle spurious connections (favicon requests, SSH tunnel
	 * probes, browser preflights) until the actual auth code arrives.
	 *
	 * @param int $timeout Seconds to wait before giving up
	 * @return string|null Authorization code, or null on timeout/error
	 */
	public function waitForCallback(int $timeout = 120): ?string
	{
		$deadline = time() + $timeout;

		while (time() < $deadline) {
			$remaining = $deadline - time();
			if ($remaining <= 0) break;

			$read = [$this->socket];
			$write = $except = null;
			$ready = @stream_select($read, $write, $except, $remaining);

			if ($ready === false) {
				return null;
			}

			if ($ready === 0) {
				continue;
			}

			$code = $this->handleConnection();
			if ($code !== null) {
				return $code;
			}
			// Not the auth callback (favicon, probe, etc.) — keep waiting
		}

		return null;
	}

	/**
	 * Check if a code has been received.
	 *
	 * @return bool
	 */
	public function hasCode(): bool
	{
		return $this->receivedCode !== null;
	}

	/**
	 * Get the received authorization code.
	 *
	 * @return string|null
	 */
	public function getCode(): ?string
	{
		return $this->receivedCode;
	}

	/**
	 * Get the error message if authorization failed.
	 *
	 * @return string|null
	 */
	public function getError(): ?string
	{
		return $this->error;
	}

	/**
	 * Close the listener and free the port.
	 */
	public function close(): void
	{
		if ($this->socket) {
			fclose($this->socket);
			$this->socket = null;
		}
	}

	/**
	 * Attempt to open a URL in the user's default browser.
	 *
	 * @param  string $url URL to open
	 * @return bool        True if a browser command was executed
	 */
	public static function tryOpenUrl(string $url): bool
	{
		if (PHP_OS_FAMILY === 'Darwin') {
			@exec('open ' . escapeshellarg($url) . ' 2>/dev/null', $output, $ret);
			return ($ret === 0);
		}

		if (PHP_OS_FAMILY === 'Windows') {
			@exec('start "" ' . escapeshellarg($url) . ' 2>NUL', $output, $ret);
			return ($ret === 0);
		}

		// Linux/BSD with display server
		if (getenv('DISPLAY') || getenv('WAYLAND_DISPLAY')) {
			@exec('xdg-open ' . escapeshellarg($url) . ' 2>/dev/null', $output, $ret);
			return ($ret === 0);
		}

		return false;
	}

	/**
	 * Send a simple HTTP response to the browser.
	 *
	 * @param resource $conn       Client connection
	 * @param int      $statusCode HTTP status code
	 * @param string   $body       Response body text
	 */
	private function sendResponse($conn, int $statusCode, string $body): void
	{
		$statusText = match($statusCode) {
			200 => 'OK',
			400 => 'Bad Request',
			default => 'Error',
		};

		$html = "<!DOCTYPE html><html><head><title>OAuth Callback</title>"
			. "<style>body{font-family:system-ui,sans-serif;display:flex;justify-content:center;"
			. "align-items:center;min-height:100vh;margin:0;background:#f5f5f5}"
			. ".card{background:white;padding:2rem;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1);"
			. "max-width:400px;text-align:center}</style></head>"
			. "<body><div class=\"card\"><p>{$body}</p></div></body></html>";

		$response = "HTTP/1.1 {$statusCode} {$statusText}\r\n"
			. "Content-Type: text/html; charset=utf-8\r\n"
			. "Content-Length: " . strlen($html) . "\r\n"
			. "Connection: close\r\n"
			. "\r\n"
			. $html;

		@fwrite($conn, $response);
	}

	public function __destruct()
	{
		$this->close();
	}
}
