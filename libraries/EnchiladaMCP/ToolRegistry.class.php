<?php

namespace EnchiladaMCP;

/* Enchilada Framework 3.0
 * MCP Tool & Resource Registry
 *
 * Reflection-based tool and resource discovery and invocation registry.
 * Automatically discovers methods marked with #[McpTool] or #[McpResource] attributes.
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
	 * @var array<string,array{name:string,description:string,inputSchema:array,annotations?:array}>
	 */
	private array $tools = [];

	/**
	 * Tool handlers indexed by name.
	 *
	 * @var array<string,array{0:object,1:string}>
	 */
	private array $handlers = [];

	/**
	 * Registered resource templates indexed by URI template.
	 *
	 * @var array<string,array{uriTemplate:string,name:string,description:string,mimeType:string}>
	 */
	private array $resourceTemplates = [];

	/**
	 * Resource handlers indexed by URI template.
	 *
	 * @var array<string,array{0:object,1:string}>
	 */
	private array $resourceHandlers = [];

	/**
	 * Register an object's methods marked with #[McpTool] or #[McpResource] attributes.
	 *
	 * @param object $handler Object containing tool/resource methods
	 */
	public function register(object $handler): void
	{
		$reflection = new \ReflectionClass($handler);

		foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
			// Check for resource attributes
			$resourceAttrs = $method->getAttributes(McpResource::class);
			if (!empty($resourceAttrs)) {
				$this->registerResource($handler, $method, $resourceAttrs[0]->newInstance());
			}

			// Check for tool attributes
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

			$tool = [
				'name' => $toolName,
				'description' => $description ?: "Tool: {$toolName}",
				'inputSchema' => $inputSchema,
			];

			$annotations = array_filter([
				'readOnlyHint' => $attr->readOnlyHint,
				'destructiveHint' => $attr->destructiveHint,
				'idempotentHint' => $attr->idempotentHint,
				'openWorldHint' => $attr->openWorldHint,
			], fn($v) => $v !== null);

			if (!empty($annotations)) {
				$tool['annotations'] = $annotations;
			}

			$this->tools[$toolName] = $tool;

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
	 * @return array<array{name:string,description:string,inputSchema:array,annotations?:array}>
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

	/**
	 * Register a single resource template from a method and attribute.
	 *
	 * @param object          $handler  Handler object
	 * @param \ReflectionMethod $method Method reflection
	 * @param McpResource     $attr     Resource attribute instance
	 */
	private function registerResource(object $handler, \ReflectionMethod $method, McpResource $attr): void
	{
		$uriTemplate = $attr->uriTemplate;
		$name = $attr->name ?? $method->getName();

		$description = $attr->description;
		if ($description === null) {
			$docComment = $method->getDocComment();
			if ($docComment) {
				preg_match('/\*\s+([^@\n]+)/', $docComment, $matches);
				$description = trim($matches[1] ?? '');
			}
		}

		$this->resourceTemplates[$uriTemplate] = [
			'uriTemplate' => $uriTemplate,
			'name' => $name,
			'description' => $description ?: "Resource: {$name}",
			'mimeType' => $attr->mimeType,
		];

		$this->resourceHandlers[$uriTemplate] = [$handler, $method->getName()];
	}

	/**
	 * List registered resource templates (URIs with {param} placeholders) in MCP protocol format.
	 *
	 * @return array<array{uriTemplate:string,name:string,description:string,mimeType:string}>
	 */
	public function listResourceTemplates(): array
	{
		return array_values(array_filter($this->resourceTemplates, function ($meta) {
			return str_contains($meta['uriTemplate'], '{');
		}));
	}

	/**
	 * List registered static resources (fixed URIs without placeholders) in MCP protocol format.
	 *
	 * @return array<array{uri:string,name:string,description:string,mimeType:string}>
	 */
	public function listResources(): array
	{
		$resources = [];
		foreach ($this->resourceTemplates as $meta) {
			if (!str_contains($meta['uriTemplate'], '{')) {
				$resources[] = [
					'uri' => $meta['uriTemplate'],
					'name' => $meta['name'],
					'description' => $meta['description'],
					'mimeType' => $meta['mimeType'],
				];
			}
		}
		return $resources;
	}

	/**
	 * Check if any resources (static or templated) are registered.
	 *
	 * @return bool True if at least one resource exists
	 */
	public function hasResources(): bool
	{
		return !empty($this->resourceTemplates);
	}

	/**
	 * Read a resource by URI, matching against registered templates.
	 *
	 * Extracts parameters from the URI by matching against templates
	 * and invokes the handler method with extracted values.
	 *
	 * @param  string $uri URI to resolve (e.g., "myapp://users/42")
	 * @return array       Resource content: {uri, mimeType, text}
	 * @throws \InvalidArgumentException If no template matches
	 */
	public function readResource(string $uri): array
	{
		foreach ($this->resourceTemplates as $template => $meta) {
			$params = $this->matchUriTemplate($template, $uri);
			if ($params !== null) {
				[$handler, $methodName] = $this->resourceHandlers[$template];
				$method = new \ReflectionMethod($handler, $methodName);

				// Map extracted params to method parameters by name
				$args = [];
				foreach ($method->getParameters() as $param) {
					$paramName = $param->getName();
					if (isset($params[$paramName])) {
						$args[] = $params[$paramName];
					} elseif ($param->isOptional()) {
						$args[] = $param->getDefaultValue();
					} else {
						throw new \InvalidArgumentException("Missing URI parameter: {$paramName}");
					}
				}

				$result = $method->invokeArgs($handler, $args);
				$text = is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_SLASHES);

				return [
					'uri' => $uri,
					'mimeType' => $meta['mimeType'],
					'text' => $text,
				];
			}
		}

		throw new \InvalidArgumentException("No resource template matches URI: {$uri}");
	}

	/**
	 * Match a URI against a template, extracting parameter values.
	 *
	 * Template: "myapp://users/{id}/repos/{repo}"
	 * URI:      "myapp://users/42/repos/hello"
	 * Returns:  ["id" => "42", "repo" => "hello"]
	 *
	 * @param  string     $template URI template with {param} placeholders
	 * @param  string     $uri      Actual URI to match
	 * @return array|null           Extracted params or null if no match
	 */
	private function matchUriTemplate(string $template, string $uri): ?array
	{
		// Build regex from template: quote literal parts, convert {param} to named groups
		$regex = '#^' . preg_replace_callback(
			'/\\\\\\{([^}]+)\\\\\\}/',
			function ($m) {
				return '(?P<' . $m[1] . '>[^/]+)';
			},
			preg_quote($template, '#')
		) . '$#';

		if (preg_match($regex, $uri, $matches)) {
			$params = [];
			foreach ($matches as $key => $value) {
				if (is_string($key)) {
					$params[$key] = $value;
				}
			}
			return $params;
		}

		return null;
	}
}
