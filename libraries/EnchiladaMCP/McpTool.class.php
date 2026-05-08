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
	 * @param string|null $name        Tool name (defaults to method name if null)
	 * @param string|null $description Tool description for clients (defaults to docblock)
	 * @param array|null  $inputSchema JSON Schema for tool parameters (auto-generated if null)
	 */
	public function __construct(
		public ?string $name = null,
		public ?string $description = null,
		public ?array $inputSchema = null
	) {}
}
