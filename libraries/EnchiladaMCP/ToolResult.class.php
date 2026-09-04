<?php

namespace EnchiladaMCP;

/* Enchilada Framework 3.0
 * MCP Tool Result Value Object
 *
 * Typed return value for MCP tool methods. Represents the content
 * array in a tools/call response per the MCP specification.
 *
 * Tools may return ToolResult for explicit content typing (image,
 * binary, mixed content) or continue returning plain values which
 * are auto-wrapped as text by the McpServer.
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

class ToolResult
{
	/** @var array<int, array<string, mixed>> Content blocks */
	private array $content;

	/** @var bool Whether this result represents an error */
	private bool $isError;

	/** @var array<string,mixed>|null Structured tool output (MCP 2025-06-18) */
	private ?array $structuredContent = null;

	/**
	 * @param array<int, array<string, mixed>> $content MCP content blocks
	 * @param bool $isError Whether this is an error result
	 */
	private function __construct(array $content, bool $isError = false)
	{
		$this->content = $content;
		$this->isError = $isError;
	}

	/**
	 * Create a text result.
	 *
	 * @param string $text Text content
	 */
	public static function text(string $text): self
	{
		return new self([['type' => 'text', 'text' => $text]]);
	}

	/**
	 * Create an image result.
	 *
	 * @param string $data Base64-encoded image data
	 * @param string $mimeType Image MIME type (default: image/png)
	 */
	public static function image(string $data, string $mimeType = 'image/png'): self
	{
		return new self([['type' => 'image', 'data' => $data, 'mimeType' => $mimeType]]);
	}

	/**
	 * Create an error result.
	 *
	 * @param string $message Error message
	 */
	public static function error(string $message): self
	{
		return new self([['type' => 'text', 'text' => $message]], true);
	}

	/**
	 * Create a text result that also carries a structured form of itself.
	 *
	 * Per the MCP specification a server returning `structuredContent` MUST
	 * also return backwards-compatible `content`, so the text rendering is a
	 * required argument rather than an optional one. Clients that predate
	 * structured output -- or that feed tool results straight to a model --
	 * keep working unchanged and never see the extra field.
	 *
	 * @param string              $text Human/LLM-readable rendering
	 * @param array<string,mixed> $data Structured form of the same result
	 */
	public static function structured(string $text, array $data): self
	{
		$result = new self([['type' => 'text', 'text' => $text]]);
		$result->structuredContent = $data;
		return $result;
	}

	/**
	 * Attach structured output to an existing result.
	 *
	 * @param  array<string,mixed> $data Structured form of this result
	 * @return self                      Fluent interface
	 */
	public function withStructuredContent(array $data): self
	{
		$this->structuredContent = $data;
		return $this;
	}

	/**
	 * Create a result with multiple content blocks (mixed types).
	 *
	 * @param array<int, array<string, mixed>> $contentBlocks Array of content blocks
	 * @param bool $isError Whether this is an error result
	 */
	public static function mixed(array $contentBlocks, bool $isError = false): self
	{
		return new self($contentBlocks, $isError);
	}

	/**
	 * Get the MCP-formatted response array for tools/call result.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		$result = ['content' => $this->content];

		if ($this->structuredContent !== null) {
			$result['structuredContent'] = $this->structuredContent;
		}

		if ($this->isError) {
			$result['isError'] = true;
		}

		return $result;
	}

	/**
	 * Get the content blocks.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getContent(): array
	{
		return $this->content;
	}

	/**
	 * Get the structured output, or null if this result carries none.
	 *
	 * @return array<string,mixed>|null
	 */
	public function getStructuredContent(): ?array
	{
		return $this->structuredContent;
	}

	/**
	 * Check if this is an error result.
	 */
	public function isError(): bool
	{
		return $this->isError;
	}
}
