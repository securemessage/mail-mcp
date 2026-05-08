<?php
/**
 * Mail MCP Server — Attachment Data Object
 *
 * @package    MailMCP\Mail
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace Mail;

class Attachment
{
	public function __construct(
		public readonly string $filename,
		public readonly string $contentType,
		public readonly int $size,
		public readonly string $partNumber,
		public readonly string $encoding = '',
		public readonly string $contentId = '',
		public readonly bool $isInline = false,
	) {}

	public function toArray(): array
	{
		return [
			'filename' => $this->filename,
			'content_type' => $this->contentType,
			'size' => $this->size,
			'part_number' => $this->partNumber,
			'encoding' => $this->encoding,
			'content_id' => $this->contentId,
			'is_inline' => $this->isInline,
		];
	}
}
