<?php

namespace EnchiladaMCP;

/* Enchilada Framework 3.0
 * MCP Tool Attribute
 *
 * PHP 8 attribute for marking methods as MCP tools.
 * Methods marked with this attribute are automatically discovered and
 * registered as callable tools in the MCP protocol.
 *
 * Software License Agreement (BSD License)
 * 
 * Copyright (c) 2026, The Daniel Morante Company, Inc.
 * All rights reserved.
 */

#[\Attribute(\Attribute::TARGET_METHOD)]
class McpTool
{
	/**
	 * Create a new McpTool attribute instance.
	 *
	 * @param string|null $name            Tool name (defaults to method name if null)
	 * @param string|null $description     Tool description for clients (defaults to docblock)
	 * @param array|null  $inputSchema     JSON Schema for tool parameters (auto-generated if null)
	 * @param bool|null   $readOnlyHint    If true, tool does not modify its environment
	 * @param bool|null   $destructiveHint If true, tool may perform destructive updates (only meaningful when readOnlyHint is not true)
	 * @param bool|null   $idempotentHint  If true, repeated calls with the same arguments have no additional effect
	 * @param bool|null   $openWorldHint   If true, tool interacts with external/unbounded entities outside a closed system
	 *
	 * Annotation hints follow the MCP specification's tool annotations. They are
	 * advisory only — clients may use them to inform UX decisions (e.g. confirmation
	 * prompts, tool filtering) but must not treat them as a security boundary.
	 */
	public function __construct(
		public ?string $name = null,
		public ?string $description = null,
		public ?array $inputSchema = null,
		public ?bool $readOnlyHint = null,
		public ?bool $destructiveHint = null,
		public ?bool $idempotentHint = null,
		public ?bool $openWorldHint = null
	) {}
}
