<?php

/* Enchilada Framework 3.0
 * OAuth Callback Server
 *
 * Temporary HTTP listener for receiving OAuth 2.0 authorization callbacks.
 * Binds to a random available port on localhost. Fully non-blocking:
 * handleConnection() never blocks — accepted connections are buffered
 * incrementally until a complete HTTP request arrives, so it is safe to
 * call from inside a host event loop's read callback (Tortilla transports,
 * Comal reactor, stream_select drivers) without starving the channel.
 *
 * Usage:
 *   $server = new OAuthCallbackServer('/callback', $state);
 *   echo "Authorize at: " . $authUrl . "&redirect_uri=" . $server->getCallbackUrl();
 *   // In event loop: watch $server->getWatchSockets()
 *   // When readable: $code = $server->handleConnection();
 *   // Standalone CLI: $code = $server->waitForCallback($timeout);
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

	/** @var array<int,array{conn:resource,buf:string,since:float}> Pending accepted connections yet to deliver a complete request */
	private array $pending = [];

	/** Bytes of pipeline input per connection before it is answered 400 and dropped */
	private const MAX_REQUEST_BYTES = 65536;

	/** Seconds a connection may stay incomplete before reaping */
	private const IDLE_TIMEOUT = 30.0;

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
	 * Get the listening socket resource. Event-loop integrations should
	 * prefer getWatchSockets(), which also covers pending connections.
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
		return "http://localhost:{$this->port}{$this->path}";
	}

	/**
	 * Get every socket the server is waiting on (listener + accepted
	 * connections with incomplete requests).
	 *
	 * Event-loop integrations should watch ALL of these, not just
	 * getSocket(): a request split across packets (SSH-tunnel latency)
	 * makes the accepted connection readable again without the listener
	 * re-firing.
	 *
	 * @return array<int,resource>
	 */
	public function getWatchSockets(): array
	{
		$sockets = [];
		if ($this->socket) {
			$sockets[] = $this->socket;
		}
		foreach ($this->pending as $p) {
			$sockets[] = $p['conn'];
		}
		return $sockets;
	}

	/**
	 * Service the listener and any pending connections — never blocks.
	 *
	 * Call this whenever getWatchSockets() reports readability. Accepts
	 * queued connections, pumps each pending read buffer until a complete
	 * HTTP request arrives, then validates state, sends the browser
	 * response, and returns the auth code when one is received.
	 *
	 * @return string|null Authorization code, or null if not received/invalid
	 */
	public function handleConnection(): ?string
	{
		$code = null;

		// Accept everything pending on the listener
		while ($this->socket && ($conn = @stream_socket_accept($this->socket, 0)) !== false) {
			stream_set_blocking($conn, false);
			$this->pending[(int)$conn] = ['conn' => $conn, 'buf' => '', 'since' => microtime(true)];
		}

		// Pump each connection's buffer; process only complete requests
		foreach ($this->pending as $id => &$p) {
			$data = @fread($p['conn'], 8192);
			if ($data === '' || $data === false) {
				if (feof($p['conn'])) {
					$this->dropPending($p, $id);
					continue;
				}
				// No bytes — reap idle connections, keep waiting otherwise
				if (microtime(true) - $p['since'] > self::IDLE_TIMEOUT) {
					$this->sendResponse($p['conn'], 408, "Request timed out");
					$this->dropPending($p, $id);
				}
				continue;
			}

			$p['buf'] .= $data;
			if (strlen($p['buf']) > self::MAX_REQUEST_BYTES) {
				$this->sendResponse($p['conn'], 400, "Invalid request");
				$this->dropPending($p, $id);
				continue;
			}

			// A request is complete once the header block terminates;
			// OAuth callbacks are GETs, the query is all we consume
			$headerEnd = strpos($p['buf'], "\r\n\r\n");
			if ($headerEnd === false) {
				$headerEnd = strpos($p['buf'], "\n\n");
			}
			if ($headerEnd === false) {
				// Headers not all here yet — wait for more bytes
				continue;
			}

			$result = $this->processRequest(substr($p['buf'], 0, $headerEnd));
			$this->sendResponse($p['conn'], $result['status'], $result['message']);
			$this->dropPending($p, $id);

			if ($result['code'] !== null) {
				$code = $result['code'];
			}
		}
		unset($p);

		return $code;
	}

	/**
	 * Parse a complete request and decide the outcome.
	 *
	 * @return array{status:int, message:string, code:?string}
	 */
	private function processRequest(string $request): array
	{
		// Parse the GET request line
		if (!preg_match('/GET\s+([^\s]+)/', $request, $matches)) {
			return ['status' => 400, 'message' => "Invalid request", 'code' => null];
		}

		$requestUri = $matches[1];
		$parts = parse_url($requestUri);
		parse_str($parts['query'] ?? '', $queryParams);

		// Check for error response from authorization server
		if (isset($queryParams['error'])) {
			$this->error = $queryParams['error_description'] ?? $queryParams['error'];
			return ['status' => 400, 'message' => "Authorization failed: {$this->error}", 'code' => null];
		}

		// Check for authorization code
		if (!isset($queryParams['code'])) {
			return ['status' => 400, 'message' => "No authorization code received", 'code' => null];
		}

		// Validate state if expected
		if (!empty($this->expectedState)) {
			$receivedState = $queryParams['state'] ?? '';
			if ($receivedState !== $this->expectedState) {
				return ['status' => 400, 'message' => "State mismatch. Possible CSRF attack.", 'code' => null];
			}
		}

		$this->receivedCode = $queryParams['code'];
		return [
			'status' => 200,
			'message' => "Authorization successful! You can close this window and return to your application.",
			'code' => $this->receivedCode,
		];
	}

	/**
	 * Close and forget a pending connection.
	 *
	 * @param array{conn:resource} $p
	 */
	private function dropPending(array $p, int $id): void
	{
		@fclose($p['conn']);
		unset($this->pending[$id]);
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

		while (time() < $deadline && $this->socket) {
			$remaining = $deadline - time();
			if ($remaining <= 0) break;

			$read = $this->getWatchSockets();
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
	 * Attempt to open a URL in the user's default browser.
	 *
	 * @param  string $url URL to open
	 * @return bool        True if a browser command was executed
	 */
	public static function tryOpenUrl(string $url): bool
	{
		// VS Code / Windsurf Remote SSH sets $BROWSER to a helper that opens URLs on the local machine
		$browser = getenv('BROWSER');
		if (!empty($browser)) {
			@exec($browser . ' ' . escapeshellarg($url) . ' 2>/dev/null', $output, $ret);
			if ($ret === 0) return true;
		}

		if (PHP_OS_FAMILY === 'Darwin') {
			@exec('open ' . escapeshellarg($url) . ' 2>/dev/null', $output, $ret);
			return ($ret === 0);
		}

		if (PHP_OS_FAMILY === 'Windows') {
			@exec('start "" ' . escapeshellarg($url) . ' 2>NUL', $output, $ret);
			return ($ret === 0);
		}

		// Linux/BSD: try VS Code CLI first (works over Remote SSH), then xdg-open
		$codeBin = getenv('VSCODE_IPC_HOOK_CLI') ? 'code' : (getenv('WINDSURF_IPC_HOOK_CLI') ? 'windsurf' : '');
		if (!empty($codeBin)) {
			@exec($codeBin . ' --open-url ' . escapeshellarg($url) . ' 2>/dev/null', $output, $ret);
			if ($ret === 0) return true;
		}

		if (getenv('DISPLAY') || getenv('WAYLAND_DISPLAY')) {
			@exec('xdg-open ' . escapeshellarg($url) . ' 2>/dev/null', $output, $ret);
			return ($ret === 0);
		}

		return false;
	}

	/**
	 * Close the listener and every pending connection, freeing the port.
	 */
	public function close(): void
	{
		foreach ($this->pending as $id => $p) {
			$this->dropPending($p, $id);
		}
		if ($this->socket) {
			fclose($this->socket);
			$this->socket = null;
		}
	}

	public function __destruct()
	{
		$this->close();
	}

	/**
	 * Send a simple HTTP response to the browser.
	 *
	 * Non-blocking: the whole page is far below SO_SNDBUF on loopback, so
	 * a single non-blocking write is effectively atomic; anything left
	 * over is abandoned when the caller drops the connection (the code
	 * has already been captured by then).
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
			408 => 'Request Timeout',
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
}
