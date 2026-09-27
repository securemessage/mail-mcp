<?php
/**
 * SecureMessage Mail MCP Server — Message Tools
 *
 * @package    MailMCP\Tools
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

use EnchiladaMCP\McpTool;
use Mail\HeaderBlock;
use Mail\InstanceManager;

class MessageTools
{
	private InstanceManager $manager;

	public function __construct(InstanceManager $manager)
	{
		$this->manager = $manager;
	}

	/**
	 * Get a single message with full content.
	 */
	#[McpTool(
		name: 'mail_get_message',
		description: 'Get a message by UID: text and HTML bodies plus attachment metadata. Requires mail_connect.',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'uid' => ['type' => 'integer', 'description' => 'UID in the selected mailbox (INBOX unless changed with mail_open_mailbox)'],
				'mark_read' => ['type' => 'boolean', 'description' => 'Set \\Seen (default false)'],
				'instance' => ['type' => 'string', 'description' => 'Mail account; omit for default'],
			],
			'required' => ['uid'],
		]
	)]
	public function mail_get_message(int $uid, bool $mark_read = false, string $instance = ''): array
	{
		$client = $this->manager->getImapClient($instance ?: null);
		$message = $client->fetchMessage($uid, $mark_read);

		return [
			'instance' => $instance ?: $this->manager->getDefault(),
			'message' => $message->toArray(true),
		];
	}

	/**
	 * Get the raw header block of a message, exactly as transmitted.
	 */
	#[McpTool(
		name: 'mail_get_headers',
		readOnlyHint: true,
		description: 'Get a message\'s raw RFC 5322 header block as transmitted (not decoded, unfolded or deduplicated). Use instead of mail_get_message when exact octets matter (DKIM-Signature, Authentication-Results, Received chain). Filtering by names returns every instance of each, in order. Requires mail_connect.',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'uid' => ['type' => 'integer', 'description' => 'UID in the selected mailbox (INBOX unless changed with mail_open_mailbox)'],
				'names' => [
					'type' => 'array',
					'items' => ['type' => 'string'],
					'description' => 'Case-insensitive, e.g. ["DKIM-Signature", "Received"]; omit for the whole block',
				],
				'instance' => ['type' => 'string', 'description' => 'Mail account; omit for default'],
			],
			'required' => ['uid'],
		]
	)]
	public function mail_get_headers(int $uid, array $names = [], string $instance = ''): array
	{
		$client = $this->manager->getImapClient($instance ?: null);
		$raw = $client->fetchRawHeaders($uid);

		$result = [
			'instance' => $instance ?: $this->manager->getDefault(),
			'uid' => $uid,
		];

		if (empty($names)) {
			$result['raw'] = $raw;
			return $result;
		}

		// Repeated fields are all kept, in order: a Received chain or a series of
		// Authentication-Results is meaningless collapsed to one entry.
		$matched = HeaderBlock::select($raw, $names);

		$result['count'] = count($matched);
		$result['headers'] = $matched;

		return $result;
	}

	/**
	 * Get multiple messages by UIDs (headers only for efficiency).
	 */
	#[McpTool(
		name: 'mail_get_messages',
		readOnlyHint: true,
		description: 'Get headers and metadata (no body) for several messages; use mail_get_message for full content. Requires mail_connect.',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'uids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'UIDs in the selected mailbox (INBOX unless changed with mail_open_mailbox)'],
				'instance' => ['type' => 'string', 'description' => 'Mail account; omit for default'],
			],
			'required' => ['uids'],
		]
	)]
	public function mail_get_messages(array $uids, string $instance = ''): array
	{
		$client = $this->manager->getImapClient($instance ?: null);
		$messages = $client->fetchHeaders($uids);

		return [
			'instance' => $instance ?: $this->manager->getDefault(),
			'count' => count($messages),
			'messages' => array_map(fn($m) => $m->toArray(), $messages),
		];
	}

	/**
	 * Mark messages as read.
	 */
	#[McpTool(
		name: 'mail_mark_read',
		description: 'Mark messages read (sets \\Seen). Requires mail_connect.',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'uids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'UIDs in the selected mailbox (INBOX unless changed with mail_open_mailbox)'],
				'instance' => ['type' => 'string', 'description' => 'Mail account; omit for default'],
			],
			'required' => ['uids'],
		]
	)]
	public function mail_mark_read(array $uids, string $instance = ''): array
	{
		$client = $this->manager->getImapClient($instance ?: null);

		foreach ($uids as $uid) {
			$client->addFlags((int) $uid, ['\\Seen']);
		}

		return [
			'instance' => $instance ?: $this->manager->getDefault(),
			'marked_read' => count($uids),
		];
	}

	/**
	 * Mark messages as unread.
	 */
	#[McpTool(
		name: 'mail_mark_unread',
		description: 'Mark messages unread (clears \\Seen). Requires mail_connect.',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'uids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'UIDs in the selected mailbox (INBOX unless changed with mail_open_mailbox)'],
				'instance' => ['type' => 'string', 'description' => 'Mail account; omit for default'],
			],
			'required' => ['uids'],
		]
	)]
	public function mail_mark_unread(array $uids, string $instance = ''): array
	{
		$client = $this->manager->getImapClient($instance ?: null);

		foreach ($uids as $uid) {
			$client->removeFlags((int) $uid, ['\\Seen']);
		}

		return [
			'instance' => $instance ?: $this->manager->getDefault(),
			'marked_unread' => count($uids),
		];
	}

	/**
	 * Delete a message.
	 */
	#[McpTool(
		name: 'mail_delete_message',
		description: 'Permanently delete a message (sets \\Deleted and expunges). Requires mail_connect.',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'uid' => ['type' => 'integer', 'description' => 'UID in the selected mailbox (INBOX unless changed with mail_open_mailbox)'],
				'instance' => ['type' => 'string', 'description' => 'Mail account; omit for default'],
			],
			'required' => ['uid'],
		]
	)]
	public function mail_delete_message(int $uid, string $instance = ''): array
	{
		$client = $this->manager->getImapClient($instance ?: null);
		$client->deleteMessage($uid);

		return [
			'instance' => $instance ?: $this->manager->getDefault(),
			'deleted_uid' => $uid,
		];
	}
}
