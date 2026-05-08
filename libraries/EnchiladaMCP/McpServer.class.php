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
			return [
				'content' => [
					[
						'type' => 'text',
						'text' => json_encode(['error' => $e->getMessage()]),
					],
				],
				'isError' => true,
			];
		}

		return [
			'content' => [
				[
					'type' => 'text',
					'text' => is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_SLASHES),
				],
			],
		];
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
