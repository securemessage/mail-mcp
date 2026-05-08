<?php

namespace EnchiladaMCP;

/* Enchilada Framework 3.0
 * MCP Tool Registry
 *
 * Reflection-based tool discovery and invocation registry.
 * Automatically discovers methods marked with #[McpTool] attributes.
 *
 * Software License Agreement (BSD License)
 * 
 * Copyright (c) 2026, The Daniel Morante Company, Inc.
 * All rights reserved.
 */

class ToolRegistry
{
	/**
	 * Registered tools indexed by name.
	 *
	 * @var array<string,array{name:string,description:string,inputSchema:array}>
	 */
	private array $tools = [];

	/**
	 * Tool handlers indexed by name.
	 *
	 * @var array<string,array{0:object,1:string}>
	 */
	private array $handlers = [];

	/**
	 * Register an object's methods marked with #[McpTool] attribute.
	 *
	 * @param object $handler Object containing tool methods
	 */
	public function register(object $handler): void
	{
		$reflection = new \ReflectionClass($handler);

		foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
			$attributes = $method->getAttributes(McpTool::class);

			if (empty($attributes)) {
				continue;
			}

			$attr = $attributes[0]->newInstance();
			$toolName = $attr->name ?? $method->getName();

			// Get description from attribute or docblock
			$description = $attr->description;
			if ($description === null) {
				$docComment = $method->getDocComment();
				if ($docComment) {
					preg_match('/\*\s+([^@\n]+)/', $docComment, $matches);
					$description = trim($matches[1] ?? '');
				}
			}

			// Build input schema from attribute or method parameters
			$inputSchema = $attr->inputSchema;
			if ($inputSchema === null) {
				$inputSchema = $this->buildSchemaFromMethod($method);
			}

			$this->tools[$toolName] = [
				'name' => $toolName,
				'description' => $description ?: "Tool: {$toolName}",
				'inputSchema' => $inputSchema,
			];

			$this->handlers[$toolName] = [$handler, $method->getName()];
		}
	}

	/**
	 * Build JSON Schema from method parameters using reflection.
	 *
	 * @param  ReflectionMethod $method Method to analyze
	 * @return array                    JSON Schema object
	 */
	private function buildSchemaFromMethod(\ReflectionMethod $method): array
	{
		$properties = [];
		$required = [];

		foreach ($method->getParameters() as $param) {
			$name = $param->getName();
			$type = $param->getType();

			$propSchema = ['type' => 'string']; // default

			if ($type !== null) {
				$typeName = $type->getName();
				$propSchema = match($typeName) {
					'int', 'integer' => ['type' => 'integer'],
					'float', 'double' => ['type' => 'number'],
					'bool', 'boolean' => ['type' => 'boolean'],
					'array' => ['type' => 'array'],
					'string' => ['type' => 'string'],
					default => ['type' => 'object'],
				};
			}

			$properties[$name] = $propSchema;

			if (!$param->isOptional()) {
				$required[] = $name;
			}
		}

		$schema = [
			'type' => 'object',
			'properties' => empty($properties) ? new \stdClass() : $properties,
		];
		if (!empty($required)) {
			$schema['required'] = $required;
		}
		return $schema;
	}

	/**
	 * List all registered tools in MCP protocol format.
	 *
	 * @return array<array{name:string,description:string,inputSchema:array}>
	 */
	public function listTools(): array
	{
		return array_values($this->tools);
	}

	/**
	 * Call a tool by name with arguments.
	 *
	 * @param  string              $name      Tool name to call
	 * @param  array<string,mixed> $arguments Arguments for the tool
	 * @return mixed                          Tool execution result
	 * @throws \InvalidArgumentException      If tool not found or missing required argument
	 */
	public function callTool(string $name, array $arguments): mixed
	{
		if (!isset($this->handlers[$name])) {
			throw new \InvalidArgumentException("Unknown tool: {$name}");
		}

		[$handler, $methodName] = $this->handlers[$name];

		$method = new \ReflectionMethod($handler, $methodName);
		$params = [];

		foreach ($method->getParameters() as $param) {
			$paramName = $param->getName();
			if (isset($arguments[$paramName])) {
				$params[] = $arguments[$paramName];
			} elseif ($param->isOptional()) {
				$params[] = $param->getDefaultValue();
			} else {
				throw new \InvalidArgumentException("Missing required argument: {$paramName}");
			}
		}

		return $method->invokeArgs($handler, $params);
	}

	/**
	 * Check if a tool exists in the registry.
	 *
	 * @param  string $name Tool name to check
	 * @return bool         True if tool is registered
	 */
	public function hasTool(string $name): bool
	{
		return isset($this->handlers[$name]);
	}
}
