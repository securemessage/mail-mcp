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

	/** @var string MCP protocol version supported. */
	private string $protocolVersion = '2025-03-26';

	/** @var string Server instructions for AI agents (included in initialize response). */
	private string $instructions = '';

	/** @var bool Whether the server has been initialized at least once. */
	private bool $initialized = false;

	/** @var callable|null Callback invoked when a re-initialize is received (cleanup prior state). */
	private $onReinitialize = null;

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

		try {
			$result = match($method) {
				'initialize' => $this->handleInitialize($params),
				'notifications/initialized' => null,
				'tools/list' => $this->handleToolsList($params),
				'tools/call' => $this->handleToolsCall($params),
				'resources/templates/list' => $this->handleResourceTemplatesList($params),
				'resources/read' => $this->handleResourcesRead($params),
				'ping' => new \stdClass(),
				default => throw new \Exception("Method not found: {$method}", -32601),
			};

			// Notifications don't get responses
			if ($result === null) {
				return [];
			}

			return $this->successResponse($id, $result);

		} catch (\Throwable $e) {
			return $this->errorResponse($id, (int)($e->getCode()) ?: -32603, $e->getMessage());
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

		$result = [
			'protocolVersion' => $this->protocolVersion,
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
	 * @param  array<string,mixed> $params Request parameters
	 * @return array<string,mixed>         Tool call response
	 * @throws \Exception                  If tool not found
	 */
	private function handleToolsCall(array $params): array
	{
		$name = $params['name'] ?? '';
		$arguments = $params['arguments'] ?? [];

		if (!$this->registry->hasTool($name)) {
			throw new \Exception("Unknown tool: {$name}", -32602);
		}

		try {
			$result = $this->registry->callTool($name, $arguments);
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
