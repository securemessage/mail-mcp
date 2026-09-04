<?php

namespace EnchiladaMCP;

/* Enchilada Framework 3.0
 * MCP Embedded HTTP Transport (Reactor-Driven)
 *
 * Runs an MCP Streamable HTTP endpoint inside a long-running process
 * using EnchiladaHttpServer and a Comal reactor. Handles MCP-specific
 * concerns: JSON-RPC routing, session management, bearer token auth,
 * CORS, and SSE/JSON content negotiation.
 *
 * Usage:
 *   $mcpServer = new McpServer('sonya', '1.0.0');
 *   $mcpServer->register($myTools);
 *
 *   $transport = new EmbeddedHttpTransport($mcpServer, $reactor, [
 *       'port' => 8808,
 *       'token' => 'my-secret',
 *   ]);
 *   $transport->listen();
 *   $reactor->run();
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
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
 * FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
 * DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
 * SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
 * OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

use Enchilada\Comal\ReactorInterface;

class EmbeddedHttpTransport
{
	/** @var McpServer */
	private McpServer $server;

	/** @var ReactorInterface */
	private ReactorInterface $reactor;

	/** @var \EnchiladaREST\EnchiladaHttpServer */
	private \EnchiladaREST\EnchiladaHttpServer $httpServer;

	/** @var string|null Bearer token for authentication (null = no auth) */
	private ?string $token;

	/** @var string Directory for session token files */
	private string $sessionDir;

	/** @var string[] Supported MCP protocol versions */
	private array $supportedVersions = ['2025-06-18', '2025-03-26', '2024-11-05'];

	/** @var callable|null */
	private $logger = null;

	/**
	 * Create a new embedded MCP HTTP transport.
	 *
	 * @param McpServer        $server  MCP protocol handler with tools registered
	 * @param ReactorInterface $reactor Event loop for I/O
	 * @param array            $options Configuration:
	 *   - host: string (default '0.0.0.0')
	 *   - port: int (default 8808)
	 *   - token: string|null (bearer token, null to disable auth)
	 *   - session_dir: string (default sys_get_temp_dir()/mcp-sessions)
	 */
	public function __construct(McpServer $server, ReactorInterface $reactor, array $options = [])
	{
		$this->server = $server;
		$this->reactor = $reactor;
		$this->token = $options['token'] ?? null;
		$this->sessionDir = $options['session_dir'] ?? sys_get_temp_dir() . '/mcp-sessions';

		// Create the underlying HTTP server with our MCP request handler
		$this->httpServer = new \EnchiladaREST\EnchiladaHttpServer($reactor, function (\EnchiladaREST\EnchiladaRequest $req, \EnchiladaREST\EnchiladaResponse $res) {
			$this->handleMcpRequest($req, $res);
		}, [
			'host' => $options['host'] ?? '0.0.0.0',
			'port' => $options['port'] ?? 8808,
		]);
	}

	/**
	 * Set a logger callback.
	 *
	 * @param callable $logger fn(string $message): void
	 */
	public function setLogger(callable $logger): void
	{
		$this->logger = $logger;
		$this->httpServer->setLogger($logger);
	}

	/**
	 * Start listening for MCP connections.
	 */
	public function listen(): void
	{
		$this->httpServer->listen();
		$this->log("MCP transport listening on port " . $this->httpServer->getPort());
	}

	/**
	 * Stop the transport.
	 */
	public function close(): void
	{
		$this->httpServer->close();
	}

	/**
	 * Handle an incoming HTTP request as MCP.
	 */
	private function handleMcpRequest(\EnchiladaREST\EnchiladaRequest $req, \EnchiladaREST\EnchiladaResponse $res): void
	{
		$method = $req->getMethod();

		// CORS headers on all responses
		$res->setHeader('Access-Control-Allow-Origin', '*');
		$res->setHeader('Access-Control-Allow-Methods', 'POST, GET, DELETE, OPTIONS');
		$res->setHeader('Access-Control-Allow-Headers', 'Content-Type, Accept, Authorization, MCP-Session-Id, MCP-Protocol-Version');

		// Preflight
		if ($method === 'OPTIONS') {
			$res->noContent();
			return;
		}

		// GET: not supported (no server-initiated SSE stream)
		if ($method === 'GET') {
			$res->setStatus(405);
			$res->json(['error' => 'Method not allowed']);
			return;
		}

		// DELETE: session termination
		if ($method === 'DELETE') {
			$this->handleSessionDelete($req, $res);
			return;
		}

		// Only POST beyond this point
		if ($method !== 'POST') {
			$res->setStatus(405);
			$res->json(['error' => 'Method not allowed']);
			return;
		}

		// Validate Content-Type
		$contentType = $req->getHeader('content-type', '');
		if (strpos($contentType, 'application/json') === false) {
			$res->setStatus(400);
			$res->json(['jsonrpc' => '2.0', 'error' => ['code' => -32600, 'message' => 'Content-Type must be application/json']]);
			return;
		}

		// Bearer token auth
		if ($this->token !== null) {
			$authHeader = $req->getHeader('authorization', '');
			$providedToken = '';
			if (str_starts_with($authHeader, 'Bearer ')) {
				$providedToken = substr($authHeader, 7);
			}
			if ($providedToken !== $this->token) {
				$res->unauthorized('Invalid or missing bearer token');
				return;
			}
		}

		// Parse JSON-RPC body
		$body = $req->getRawBody();
		$request = json_decode($body, true);

		if ($request === null) {
			$res->setStatus(400);
			$res->json(['jsonrpc' => '2.0', 'error' => ['code' => -32700, 'message' => 'Parse error']]);
			return;
		}

		$rpcMethod = $request['method'] ?? null;
		$hasId = array_key_exists('id', $request);
		$isInitialize = ($rpcMethod === 'initialize');

		// Session validation (non-initialize requests)
		if (!$isInitialize) {
			if (!$this->validateSession($req, $res)) {
				return;
			}
			if (!$this->validateProtocolVersion($req, $res)) {
				return;
			}
		}

		// Handle notifications (no id): return 202 Accepted
		if (!$hasId) {
			$this->server->handleRequest($request);
			$res->setStatus(202);
			$res->send();
			return;
		}

		// Dispatch tool calls inside a Fiber so tools that opt into
		// non-blocking I/O (via Async\read, Async\write, etc.) can
		// suspend without freezing the reactor. Tools that use plain
		// synchronous I/O continue to work unchanged.
		\Enchilada\Comal\Async\spawn($this->reactor, function () use ($request, $req, $res, $isInitialize) {
			$response = $this->server->handleRequest($request);

			// Create session on successful initialize
			if ($isInitialize && isset($response['result'])) {
				$sessionId = $this->createSession();
				$res->setHeader('MCP-Session-Id', $sessionId);
			}

			// Send response
			$this->sendMcpResponse($req, $res, $response);
		});
	}

	/**
	 * Handle DELETE request for session termination.
	 */
	private function handleSessionDelete(\EnchiladaREST\EnchiladaRequest $req, \EnchiladaREST\EnchiladaResponse $res): void
	{
		$sessionId = $req->getHeader('mcp-session-id');
		if ($sessionId !== null) {
			$sessionFile = $this->sessionDir . '/' . basename($sessionId);
			if (file_exists($sessionFile)) {
				@unlink($sessionFile);
			}
		}
		$res->setStatus(200);
		$res->send();
	}

	/**
	 * Validate session ID on non-initialize requests.
	 *
	 * @return bool True if valid, false if response was sent with error
	 */
	private function validateSession(\EnchiladaREST\EnchiladaRequest $req, \EnchiladaREST\EnchiladaResponse $res): bool
	{
		$sessionId = $req->getHeader('mcp-session-id');
		if ($sessionId === null) {
			return true; // No session header = OK (stateless mode)
		}

		$sessionFile = $this->sessionDir . '/' . basename($sessionId);
		if (!file_exists($sessionFile)) {
			$res->setStatus(404);
			$res->json([
				'jsonrpc' => '2.0',
				'error' => ['code' => -32600, 'message' => 'Session not found. Send a new InitializeRequest.'],
			]);
			return false;
		}

		return true;
	}

	/**
	 * Validate MCP-Protocol-Version header.
	 *
	 * @return bool True if valid or absent, false if error was sent
	 */
	private function validateProtocolVersion(\EnchiladaREST\EnchiladaRequest $req, \EnchiladaREST\EnchiladaResponse $res): bool
	{
		$version = $req->getHeader('mcp-protocol-version');
		if ($version === null) {
			return true;
		}

		if (!in_array($version, $this->supportedVersions, true)) {
			$res->setStatus(400);
			$res->json([
				'jsonrpc' => '2.0',
				'error' => ['code' => -32600, 'message' => 'Unsupported MCP-Protocol-Version'],
			]);
			return false;
		}

		return true;
	}

	/**
	 * Create a new session and return the session ID.
	 */
	private function createSession(): string
	{
		$sessionId = bin2hex(random_bytes(32));
		if (!is_dir($this->sessionDir)) {
			@mkdir($this->sessionDir, 0700, true);
		}
		file_put_contents($this->sessionDir . '/' . $sessionId, time());
		return $sessionId;
	}

	/**
	 * Send MCP response respecting Accept header (JSON vs SSE).
	 */
	private function sendMcpResponse(\EnchiladaREST\EnchiladaRequest $req, \EnchiladaREST\EnchiladaResponse $res, array $response): void
	{
		$accept = $req->getHeader('accept', '');
		$json = json_encode($response, JSON_UNESCAPED_SLASHES);

		if (str_contains($accept, 'text/event-stream')) {
			$res->setHeader('Content-Type', 'text/event-stream');
			$res->setHeader('Cache-Control', 'no-cache');
			$body = "event: message\ndata: {$json}\n\n";
			$res->setBody($body);
		} else {
			$res->setHeader('Content-Type', 'application/json');
			$res->setBody($json);
		}

		$res->send();
	}

	private function log(string $message): void
	{
		if ($this->logger) {
			($this->logger)("[McpTransport] " . $message);
		}
	}
}
