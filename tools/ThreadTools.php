<?php
/**
 * Mail MCP Server — Thread Tools
 *
 * Retrieve conversation threads grouped by References/In-Reply-To.
 *
 * @package    MailMCP\Tools
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

use EnchiladaMCP\McpTool;
use Mail\InstanceManager;

class ThreadTools
{
	private InstanceManager $manager;

	public function __construct(InstanceManager $manager)
	{
		$this->manager = $manager;
	}

	/**
	 * Get a conversation thread containing a specific message.
	 */
	#[McpTool(
		name: 'mail_get_thread',
		description: 'Retrieve a full email conversation thread given any message UID within it. Finds all related messages by matching Message-ID, In-Reply-To, and References headers. Returns messages sorted oldest-first (chronological).',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'uid' => ['type' => 'integer', 'description' => 'UID of any message in the thread'],
				'instance' => ['type' => 'string', 'description' => 'Mail account name (optional, uses default)'],
			],
			'required' => ['uid'],
		]
	)]
	public function mail_get_thread(int $uid, string $instance = ''): array
	{
		$client = $this->manager->getImapClient($instance ?: null);

		// Fetch the anchor message to get its Message-ID and References
		$anchor = $client->fetchMessage($uid, false);

		// Collect all message-ids in this thread
		$threadIds = [];
		if (!empty($anchor->messageId)) {
			$threadIds[] = $this->cleanMessageId($anchor->messageId);
		}

		// Parse References header for thread chain
		$references = $anchor->headers['references'] ?? '';
		if (!empty($references)) {
			foreach ($this->parseMessageIds($references) as $id) {
				$threadIds[] = $id;
			}
		}

		// Also check In-Reply-To
		$inReplyTo = $anchor->headers['in-reply-to'] ?? '';
		if (!empty($inReplyTo)) {
			$threadIds[] = $this->cleanMessageId($inReplyTo);
		}

		$threadIds = array_unique($threadIds);

		if (empty($threadIds)) {
			// No threading headers — return just the single message
			return [
				'instance' => $instance ?: $this->manager->getDefault(),
				'thread_size' => 1,
				'messages' => [$anchor->toArray(true)],
			];
		}

		// Search for all messages referencing any of these message-ids
		// IMAP doesn't support OR across multiple HEADER searches directly,
		// so we search per message-id and union the results
		$threadUids = [$uid];

		foreach ($threadIds as $msgId) {
			// Search by Message-ID header
			try {
				$found = $client->search(['HEADER Message-ID' => "<{$msgId}>"]);
				$threadUids = array_merge($threadUids, $found);
			} catch (\Throwable $e) {
				// Some servers may not support HEADER search well
			}

			// Search by References header containing this message-id
			try {
				$found = $client->search(['HEADER References' => $msgId]);
				$threadUids = array_merge($threadUids, $found);
			} catch (\Throwable $e) {
				// Non-fatal
			}

			// Search by In-Reply-To
			try {
				$found = $client->search(['HEADER In-Reply-To' => "<{$msgId}>"]);
				$threadUids = array_merge($threadUids, $found);
			} catch (\Throwable $e) {
				// Non-fatal
			}
		}

		$threadUids = array_values(array_unique($threadUids));

		// Fetch full messages for the thread
		$messages = [];
		foreach ($threadUids as $threadUid) {
			try {
				$msg = $client->fetchMessage((int) $threadUid, false);
				$messages[] = $msg;
			} catch (\Throwable $e) {
				// Skip messages that can't be fetched
			}
		}

		// Sort chronologically (oldest first for a thread view)
		usort($messages, function ($a, $b) {
			$dateA = strtotime($a->date) ?: 0;
			$dateB = strtotime($b->date) ?: 0;
			return $dateA - $dateB;
		});

		return [
			'instance' => $instance ?: $this->manager->getDefault(),
			'thread_size' => count($messages),
			'subject' => $anchor->subject,
			'messages' => array_map(fn($m) => $m->toArray(true), $messages),
		];
	}

	/**
	 * Clean a message-id string (remove angle brackets and whitespace).
	 */
	private function cleanMessageId(string $id): string
	{
		return trim($id, " \t\n\r<>");
	}

	/**
	 * Parse a References or In-Reply-To header into individual message-ids.
	 */
	private function parseMessageIds(string $header): array
	{
		$ids = [];
		if (preg_match_all('/<([^>]+)>/', $header, $matches)) {
			$ids = $matches[1];
		}
		return $ids;
	}
}
