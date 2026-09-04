<?php

namespace EnchiladaMCP;

/* Enchilada Framework 3.0
 * MCP Protocol Server
 *
 * JSON-RPC 2.0 protocol handler for the Model Context Protocol.
 * Handles initialize, tools/list, tools/call, and ping methods.
 * Transport-agnostic: receives decoded requests, returns response arrays.
 *
 * Software License Agreement (BSD License)
 * 
 * Copyright (c) 2026, The Daniel Morante Company, Inc.
 * All rights reserved.
 */

class McpServer
{
	/** @var ToolRegistry */
	private ToolRegistry $registry;

	/** @var array{name:string,version:string} */
	private array $serverInfo;

	/**
	 * Latest MCP protocol revision this library implements. Exposed so
	 * downstream code can report or default to it without restating the
	 * literal and drifting from the server.
	 */
	public const DEFAULT_PROTOCOL_VERSION = '2025-06-18';

	/** @var string Preferred (latest) MCP protocol version supported. */
	private string $protocolVersion = self::DEFAULT_PROTOCOL_VERSION;

	/**
	 * @var string[] Every MCP protocol version this server can speak, newest
	 *               first. Used to answer `initialize` with the client's own
	 *               version when we support it.
	 */
	private array $supportedProtocolVersions = [self::DEFAULT_PROTOCOL_VERSION, '2025-03-26'];

	/** @var string Version agreed during the last `initialize`. */
	private string $negotiatedProtocolVersion = self::DEFAULT_PROTOCOL_VERSION;

	/** @var string Server instructions for AI agents (included in initialize response). */
	private string $instructions = '';

	/** @var bool Whether the server has been initialized at least once. */
	private bool $initialized = false;

	/** @var callable|null Callback invoked when a re-initialize is received (cleanup prior state). */
	private $onReinitialize = null;

	/** @var callable|null Optional logger: function(string $message): void */
	private $logger = null;

	/**
	 * Create a new MCP server instance.
	 *
	 * @param string $name    Server name for client identification
	 * @param string $version Server version string
	 */
	public function __construct(string $name = 'mcp-server', string $version = '1.0.0')
	{
		$this->registry = new ToolRegistry();
		$this->serverInfo = [
			'name' => $name,
			'version' => $version,
		];
	}

	/**
	 * Get the protocol version agreed during the last `initialize`.
	 *
	 * Useful for tools that want to withhold a newer-revision field from a
	 * client that negotiated an older one. Note that withholding is not
	 * required for additive result fields: MCP clients must ignore members
	 * they do not recognise.
	 */
	public function negotiatedProtocolVersion(): string
	{
		return $this->negotiatedProtocolVersion;
	}

	/**
	 * Set server instructions for AI agents.
	 *
	 * The instructions string is included in the initialize response
	 * to help AI agents understand the server's purpose and usage.
	 *
	 * @param  string $instructions Instructions text
	 * @return self                 Fluent interface
	 */
	public function setInstructions(string $instructions): self
	{
		$this->instructions = $instructions;
		return $this;
	}

	/**
	 * Set a callback invoked when the client sends a second initialize request.
	 *
	 * Stdio MCP servers are single-connection, but some IDE hosts will send
	 * a fresh initialize on the same pipe to "restart" the logical session.
	 * The callback should clean up any stateful resources (browser sessions,
	 * open connections, etc.) so the server can start fresh without a process
	 * restart.
	 *
	 * @param  callable $callback Invoked with no arguments before re-init response
	 * @return self               Fluent interface
	 */
	public function onReinitialize(callable $callback): self
	{
		$this->onReinitialize = $callback;
		return $this;
	}

	/**
	 * Set a logging callback for protocol-level diagnostics.
	 *
	 * Receives one line per request describing the method, tool name,
	 * argument digests, duration, and outcome. Argument VALUES are never
	 * logged — only lengths and SHA-256 digests — so secrets passed as
	 * tool arguments cannot leak into log files.
	 *
	 * @param  callable $logger Function accepting a string message
	 * @return self             Fluent interface
	 */
	public function setLogger(callable $logger): self
	{
		$this->logger = $logger;
		return $this;
	}

	/**
	 * Register an object's tools with the server.
	 *
	 * @param  object $handler Object containing McpTool-annotated methods
	 * @return self            Fluent interface
	 */
	public function register(object $handler): self
	{
		$this->registry->register($handler);
		return $this;
	}

	/**
	 * Handle a JSON-RPC request and return a response.
	 *
	 * @param  array<string,mixed> $request JSON-RPC request object
	 * @return array<string,mixed>          JSON-RPC response (empty array for notifications)
	 */
	public function handleRequest(array $request): array
	{
		$id = $request['id'] ?? null;
		$method = $request['method'] ?? '';
		$params = $request['params'] ?? [];

		$started = microtime(true);
		if ($method === 'tools/call') {
			$toolName = $params['name'] ?? '';
			$this->log("Request tools/call '{$toolName}' (id=" . json_encode($id) . ') ' . $this->summarizeArguments($params['arguments'] ?? []));
		} else {
			$this->log("Request {$method} (id=" . json_encode($id) . ')');
		}

		try {
			$result = match($method) {
				'initialize' => $this->handleInitialize($params),
				'notifications/initialized' => null,
				'tools/list' => $this->handleToolsList($params),
				'tools/call' => $this->handleToolsCall($params),
				'resources/list' => $this->handleResourcesList($params),
				'resources/templates/list' => $this->handleResourceTemplatesList($params),
				'resources/read' => $this->handleResourcesRead($params),
				'ping' => new \stdClass(),
				default => throw new \Exception("Method not found: {$method}", -32601),
			};

			// Notifications don't get responses
			if ($result === null) {
				return [];
			}

			$elapsed = round((microtime(true) - $started) * 1000, 1);
			if (is_array($result) && !empty($result['isError'])) {
				$errorText = $result['content'][0]['text'] ?? '(no detail)';
				$this->log("Error {$method}" . ($method === 'tools/call' ? " '{$toolName}'" : '') . " (id=" . json_encode($id) . ") tool reported failure after {$elapsed}ms: " . Logger::truncate($errorText));
			} else {
				$this->log("OK {$method}" . ($method === 'tools/call' ? " '{$toolName}'" : '') . " (id=" . json_encode($id) . ") {$elapsed}ms");
			}

			return $this->successResponse($id, $result);

		} catch (\Throwable $e) {
			$elapsed = round((microtime(true) - $started) * 1000, 1);
			$this->log("Error {$method}" . ($method === 'tools/call' ? " '{$toolName}'" : '') . " (id=" . json_encode($id) . ") {$elapsed}ms: {$e->getMessage()}");
			return $this->errorResponse($id, (int)($e->getCode()) ?: -32603, $e->getMessage());
		}
	}

	/**
	 * Build a secret-safe summary of tool call arguments for logging.
	 *
	 * Scalars (int, float, bool, null) are logged as-is; strings are
	 * reduced to length + SHA-256 digest so values (which may be tokens
	 * or secrets) never appear in logs; arrays are summarized by their
	 * JSON encoding length + digest.
	 *
	 * @param  array<string,mixed> $arguments Tool call arguments
	 * @return string                         e.g. "args: owner=5 page=1 data(len=10308 sha256=9f2c…)"
	 */
	private function summarizeArguments(array $arguments): string
	{
		if (empty($arguments)) {
			return 'args: (none)';
		}

		$parts = [];
		foreach ($arguments as $key => $value) {
			if (is_string($value)) {
				$parts[] = $key . '(' . Logger::digest($value) . ')';
			} elseif (is_scalar($value) || $value === null) {
				$parts[] = $key . '=' . json_encode($value);
			} else {
				$json = json_encode($value);
				$parts[] = $key . '(' . Logger::digest($json === false ? '' : $json) . ')';
			}
		}
		return 'args: ' . implode(' ', $parts);
	}

	/**
	 * Log a message via the configured logger.
	 *
	 * @param string $message
	 */
	private function log(string $message): void
	{
		if ($this->logger !== null) {
			try {
				($this->logger)($message);
			} catch (\Throwable $e) {
				// Logging must never break protocol handling
			}
		}
	}

	/**
	 * Handle initialize request.
	 *
	 * @param  array<string,mixed> $params Request parameters
	 * @return array<string,mixed>         Initialize response
	 */
	private function handleInitialize(array $params): array
	{
		// If already initialized, invoke the cleanup callback so callers can
		// reset stateful resources (browser sessions, etc.) before the client
		// treats this as a fresh connection.
		if ($this->initialized && $this->onReinitialize !== null) {
			try {
				($this->onReinitialize)();
			} catch (\Throwable $e) {
				// Non-fatal — best-effort cleanup
				fwrite(STDERR, "[mcp] Re-initialize cleanup error: {$e->getMessage()}\n");
			}
		}

		$this->initialized = true;

		// Version negotiation. Previously the requested version was ignored
		// and the server always answered with its own, which meant a client
		// asking for a revision we support could still be told otherwise.
		// Per spec: echo the client's version when supported, else answer
		// with our latest and let the client decide whether to continue.
		$requested = $params['protocolVersion'] ?? null;
		$this->negotiatedProtocolVersion =
			(is_string($requested) && in_array($requested, $this->supportedProtocolVersions, true))
				? $requested
				: $this->protocolVersion;

		$result = [
			'protocolVersion' => $this->negotiatedProtocolVersion,
			'capabilities' => [
				'tools' => new \stdClass(),
				'logging' => new \stdClass(),
			],
			'serverInfo' => $this->serverInfo,
		];

		if (!empty($this->instructions)) {
			$result['instructions'] = $this->instructions;
		}

		if ($this->registry->hasResources()) {
			$result['capabilities']['resources'] = new \stdClass();
		}

		return $result;
	}

	/**
	 * Handle tools/list request.
	 *
	 * @param  array<string,mixed> $params Request parameters
	 * @return array<string,mixed>         Tools list response
	 */
	private function handleToolsList(array $params): array
	{
		return [
			'tools' => $this->registry->listTools(),
		];
	}

	/**
	 * Handle tools/call request.
	 *
	 * Unknown tool names are returned as tool-level error results (with
	 * name suggestions), not protocol-level errors.
	 *
	 * @param  array<string,mixed> $params Request parameters
	 * @return array<string,mixed>         Tool call response
	 */
	private function handleToolsCall(array $params): array
	{
		$name = $params['name'] ?? '';
		$arguments = $params['arguments'] ?? [];

		if (!$this->registry->hasTool($name)) {
			// Return as a tool-level error result rather than a protocol-level
			// -32602: several MCP clients treat protocol errors as connection
			// failures (tearing down and restarting the server), while an
			// isError result is shown to the agent so it can self-correct.
			$suggestions = implode(', ', $this->registry->suggestTools($name));
			$this->log("Unknown tool '{$name}' (closest matches: {$suggestions})");
			return ToolResult::error("Unknown tool: '{$name}'. Closest matches: {$suggestions}")->toArray();
		}

		try {
			$result = $this->registry->callTool($name, $arguments);
		} catch (ToolWarningInterface $e) {
			// Uncertain-but-not-failed outcome (e.g. upstream timeout where
			// the server may still have completed the operation). Return as
			// a normal result so the agent can read the explanation and
			// verify state instead of treating the call as failed.
			$this->log("Warning tools/call '{$name}': " . Logger::truncate($e->getMessage()));
			return ToolResult::text($e->getMessage())->toArray();
		} catch (\Throwable $e) {
			return ToolResult::error($e->getMessage())->toArray();
		}

		// Typed return: tools that return ToolResult get pass-through
		if ($result instanceof ToolResult) {
			return $result->toArray();
		}

		// Backward compat: plain values auto-wrap as text
		$text = is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_SLASHES);
		return ToolResult::text($text)->toArray();
	}

	/**
	 * Build a JSON-RPC success response.
	 *
	 * @param  mixed                       $id     Request ID
	 * @param  array<string,mixed>|object  $result Result data
	 * @return array<string,mixed>
	 */
	private function successResponse($id, array|object $result): array
	{
		return [
			'jsonrpc' => '2.0',
			'id' => $id,
			'result' => $result,
		];
	}

	/**
	 * Handle resources/list request.
	 *
	 * @param  array<string,mixed> $params Request parameters
	 * @return array<string,mixed>         Resources list response
	 */
	private function handleResourcesList(array $params): array
	{
		return [
			'resources' => $this->registry->listResources(),
		];
	}

	/**
	 * Handle resources/templates/list request.
	 *
	 * @param  array<string,mixed> $params Request parameters
	 * @return array<string,mixed>         Resource templates list response
	 */
	private function handleResourceTemplatesList(array $params): array
	{
		return [
			'resourceTemplates' => $this->registry->listResourceTemplates(),
		];
	}

	/**
	 * Handle resources/read request.
	 *
	 * @param  array<string,mixed> $params Request parameters (must include 'uri')
	 * @return array<string,mixed>         Resource read response
	 * @throws \Exception                  If URI not provided or no match
	 */
	private function handleResourcesRead(array $params): array
	{
		$uri = $params['uri'] ?? '';

		if (empty($uri)) {
			throw new \Exception("Missing required parameter: uri", -32602);
		}

		try {
			$content = $this->registry->readResource($uri);
		} catch (\Throwable $e) {
			throw new \Exception("Resource not found: {$e->getMessage()}", -32602);
		}

		return [
			'contents' => [$content],
		];
	}

	/**
	 * Build a JSON-RPC error response.
	 *
	 * @param  mixed  $id      Request ID
	 * @param  int    $code    Error code
	 * @param  string $message Error message
	 * @return array<string,mixed>
	 */
	private function errorResponse($id, int $code, string $message): array
	{
		return [
			'jsonrpc' => '2.0',
			'id' => $id,
			'error' => [
				'code' => $code,
				'message' => $message,
			],
		];
	}
}
