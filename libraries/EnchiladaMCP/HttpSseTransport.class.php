<?php

namespace EnchiladaMCP;

/* Enchilada Framework 3.0
 * MCP Streamable HTTP Transport
 *
 * Request handler for MCP servers communicating over Streamable HTTP
 * with optional SSE framing. Implements the MCP specification (2025-03-26)
 * for HTTP-based communication.
 *
 * Handles CORS, session management, content negotiation (JSON vs SSE),
 * protocol version validation, and request routing to the McpServer.
 *
 * Usage:
 *   $server = new McpServer('my-server', '1.0.0');
 *   $server->register($myTools);
 *   $transport = new HttpSseTransport($server);
 *   $transport->handle();
 *
 * @see https://modelcontextprotocol.io/specification/2025-03-26/basic/transports#streamable-http
 *
 * Software License Agreement (BSD License)
 *
 * Copyright (c) 2026, The Daniel Morante Company, Inc.
 * All rights reserved.
 *
 * Redistribution and use of this software in source and binary forms,
 * with or without modification, are permitted provided that the following
 * conditions are met:
 *
 *   Redistributions of source code must retain the above copyright notice,
 *   this list of conditions and the following disclaimer.
 *
 *   Redistributions in binary form must reproduce the above copyright notice,
 *   this list of conditions and the following disclaimer in the documentation
 *   and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
 * ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE
 * LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
 * CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

class HttpSseTransport
{
	/** @var McpServer */
	private McpServer $server;

	/** @var string Directory for session token files. */
	private string $sessionDir;

	/** @var string[] Supported MCP protocol versions. */
	private array $supportedVersions = ['2025-06-18', '2025-03-26', '2024-11-05'];

	/** @var callable|null Optional callback invoked after each tools/call request. */
	private $afterToolsCall = null;

	/**
	 * Create a new HTTP SSE transport.
	 *
	 * @param McpServer   $server     Protocol handler with tools registered
	 * @param string|null $sessionDir Session token directory (default: sys_get_temp_dir()/mcp-sessions)
	 */
	public function __construct(McpServer $server, ?string $sessionDir = null)
	{
		$this->server = $server;
		$this->sessionDir = $sessionDir ?? sys_get_temp_dir() . '/mcp-sessions';
	}

	/**
	 * Register a callback invoked after each tools/call request.
	 *
	 * Use this for domain-specific post-processing such as heartbeat
	 * writes or metrics collection. The callback receives the decoded
	 * request array and the response array.
	 *
	 * @param callable $callback function(array $request, array $response): void
	 */
	public function onAfterToolsCall(callable $callback): void
	{
		$this->afterToolsCall = $callback;
	}

	/**
	 * Handle the current HTTP request.
	 *
	 * Reads the request method, validates headers, dispatches to the
	 * McpServer, and writes the response as JSON or SSE depending on
	 * the client's Accept header. Terminates the PHP process via exit.
	 */
	public function handle(): void
	{
		$method = $_SERVER['REQUEST_METHOD'];

		// CORS headers
		header('Access-Control-Allow-Origin: *');
		header('Access-Control-Allow-Methods: POST, GET, DELETE, OPTIONS');
		header('Access-Control-Allow-Headers: Content-Type, Accept, MCP-Session-Id, MCP-Protocol-Version');

		// Handle preflight
		if ($method === 'OPTIONS') {
			http_response_code(204);
			exit;
		}

		// GET: Server does not offer a server-initiated SSE stream.
		// 405 is spec-allowed, but it must not fall through to PHP's default
		// text/html — strict clients surface 'unexpected content type'
		// instead of 'no SSE stream' (heliofane issue #7).
		if ($method === 'GET') {
			header('Allow: POST, DELETE, OPTIONS');
			$this->sendJsonError(-32000, 'Method Not Allowed: server does not offer a server-initiated SSE stream', 405);
			exit;
		}

		// DELETE: Client-initiated session termination
		if ($method === 'DELETE') {
			$this->handleSessionDelete();
			exit;
		}

		// Only POST beyond this point
		if ($method !== 'POST') {
			header('Allow: POST, DELETE, OPTIONS');
			$this->sendJsonError(-32000, 'Method Not Allowed: only POST is used by this transport', 405);
			exit;
		}

		// Validate Content-Type
		$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
		if (strpos($contentType, 'application/json') === false) {
			$this->sendJsonError(-32600, 'Content-Type must be application/json', 400);
			exit;
		}

		// Read and parse request body
		$body = file_get_contents('php://input');
		$request = json_decode($body, true);

		if ($request === null) {
			$this->sendJsonError(-32700, 'Parse error', 400);
			exit;
		}

		$rpcMethod = $request['method'] ?? null;
		$hasId = array_key_exists('id', $request);
		$isInitialize = ($rpcMethod === 'initialize');

		// Session validation
		if (!$isInitialize) {
			if (!$this->validateSession()) {
				exit;
			}
			if (!$this->validateProtocolVersion()) {
				exit;
			}
		}

		// Handle notifications (no id): return 202 Accepted
		if (!$hasId) {
			$this->server->handleRequest($request);
			http_response_code(202);
			exit;
		}

		// Handle JSON-RPC request
		$response = $this->server->handleRequest($request);

		// Post-processing hook for tools/call
		if ($rpcMethod === 'tools/call' && $this->afterToolsCall !== null) {
			try {
				($this->afterToolsCall)($request, $response);
			} catch (\Throwable $e) {
				// Hook failure is non-fatal
			}
		}

		// Create session on initialize
		if ($isInitialize && isset($response['result'])) {
			$this->createSession();
		}

		// Send response (SSE or JSON)
		$this->sendResponse($response);
	}

	/**
	 * Handle DELETE request for session termination.
	 */
	private function handleSessionDelete(): void
	{
		$sessionId = $_SERVER['HTTP_MCP_SESSION_ID'] ?? null;
		if ($sessionId !== null) {
			$sessionFile = $this->sessionDir . '/' . basename($sessionId);
			if (file_exists($sessionFile)) {
				unlink($sessionFile);
			}
		}
		// JSON content-type so strict clients do not trip on PHP's default
		// text/html for a body-less 200 (heliofane issue #7).
		http_response_code(200);
		header('Content-Type: application/json');
	}

	/**
	 * Validate session ID on non-initialize requests.
	 *
	 * @return bool True if valid (or no session header sent), false if invalid session
	 */
	private function validateSession(): bool
	{
		$sessionId = $_SERVER['HTTP_MCP_SESSION_ID'] ?? null;
		if ($sessionId === null) {
			return true;
		}

		$sessionFile = $this->sessionDir . '/' . basename($sessionId);
		if (!file_exists($sessionFile)) {
			http_response_code(404);
			header('Content-Type: application/json');
			echo json_encode([
				'jsonrpc' => '2.0',
				'error' => [
					'code' => -32600,
					'message' => 'Session not found. Send a new InitializeRequest.',
				],
			]);
			return false;
		}

		return true;
	}

	/**
	 * Validate MCP-Protocol-Version header on non-initialize requests.
	 *
	 * @return bool True if valid or absent, false if unsupported version
	 */
	private function validateProtocolVersion(): bool
	{
		$protoVersion = $_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? null;
		if ($protoVersion === null) {
			return true;
		}

		if (!in_array($protoVersion, $this->supportedVersions, true)) {
			$this->sendJsonError(-32600, 'Unsupported MCP-Protocol-Version', 400);
			return false;
		}

		return true;
	}

	/**
	 * Create a new session and send the session ID header.
	 */
	private function createSession(): void
	{
		$newSessionId = bin2hex(random_bytes(32));
		if (!is_dir($this->sessionDir)) {
			mkdir($this->sessionDir, 0700, true);
		}
		file_put_contents($this->sessionDir . '/' . $newSessionId, time());
		header('MCP-Session-Id: ' . $newSessionId);
	}

	/**
	 * Send response as SSE or JSON based on client Accept header.
	 *
	 * @param array<string,mixed> $response JSON-RPC response array
	 */
	private function sendResponse(array $response): void
	{
		$accept = $_SERVER['HTTP_ACCEPT'] ?? '';
		$wantsSSE = str_contains($accept, 'text/event-stream');

		$json = json_encode($response, JSON_UNESCAPED_SLASHES);

		if ($wantsSSE) {
			header('Content-Type: text/event-stream');
			header('Cache-Control: no-cache');
			header('Connection: keep-alive');

			echo "event: message\n";
			echo "data: {$json}\n\n";

			if (function_exists('ob_end_flush')) {
				@ob_end_flush();
			}
			flush();
		} else {
			header('Content-Type: application/json');
			echo $json;
		}
	}

	/**
	 * Send a JSON-RPC error response and set HTTP status code.
	 *
	 * @param int    $code    JSON-RPC error code
	 * @param string $message Error message
	 * @param int    $status  HTTP status code (default: 400)
	 */
	private function sendJsonError(int $code, string $message, int $status = 400): void
	{
		http_response_code($status);
		header('Content-Type: application/json');
		echo json_encode([
			'jsonrpc' => '2.0',
			'error' => ['code' => $code, 'message' => $message],
		]);
	}
}
