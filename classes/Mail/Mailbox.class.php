<?php
/**
 * Mail MCP Server — Mailbox Data Object
 *
 * @package    MailMCP\Mail
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace Mail;

class Mailbox
{
	public function __construct(
		public readonly string $name,
		public readonly int $totalMessages = 0,
		public readonly int $recentMessages = 0,
		public readonly int $unseenMessages = 0,
		public readonly int $uidValidity = 0,
		public readonly int $uidNext = 0,
		public readonly string $delimiter = '/',
		public readonly array $flags = [],
	) {}

	public function toArray(): array
	{
		return [
			'name' => $this->name,
			'total_messages' => $this->totalMessages,
			'recent_messages' => $this->recentMessages,
			'unseen_messages' => $this->unseenMessages,
			'uid_validity' => $this->uidValidity,
			'uid_next' => $this->uidNext,
			'delimiter' => $this->delimiter,
			'flags' => $this->flags,
		];
	}
}
