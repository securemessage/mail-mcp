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
		description: 'Retrieve a specific email message by UID. Returns full content including text body, HTML body, and attachment metadata.',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'uid' => ['type' => 'integer', 'description' => 'Message UID to retrieve'],
				'mark_read' => ['type' => 'boolean', 'description' => 'Mark message as read when retrieving (default: false)'],
				'instance' => ['type' => 'string', 'description' => 'Mail account name (optional, uses default)'],
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
		description: 'Retrieve the raw RFC 5322 header block of a message, exactly as transmitted — nothing decoded, unfolded, or deduplicated. Use this instead of mail_get_message when the exact octets matter: inspecting DKIM-Signature, Authentication-Results, or the Received chain. Optionally filter to specific header names, which returns every instance of each in the order it appears.',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'uid' => ['type' => 'integer', 'description' => 'Message UID'],
				'names' => [
					'type' => 'array',
					'items' => ['type' => 'string'],
					'description' => 'Header names to return (case-insensitive, e.g. ["DKIM-Signature", "Received"]). Omit for the whole block.',
				],
				'instance' => ['type' => 'string', 'description' => 'Mail account name (optional, uses default)'],
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
		description: 'Retrieve multiple email messages by their UIDs. Returns headers and metadata (not full body). Use mail_get_message for full content of a specific message.',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'uids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Array of message UIDs to retrieve'],
				'instance' => ['type' => 'string', 'description' => 'Mail account name (optional, uses default)'],
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
		description: 'Mark one or more messages as read (sets \\Seen flag).',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'uids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Message UIDs to mark as read'],
				'instance' => ['type' => 'string', 'description' => 'Mail account name (optional, uses default)'],
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
		description: 'Mark one or more messages as unread (removes \\Seen flag).',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'uids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Message UIDs to mark as unread'],
				'instance' => ['type' => 'string', 'description' => 'Mail account name (optional, uses default)'],
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
		description: 'Delete a message by UID. Sets \\Deleted flag and expunges.',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'uid' => ['type' => 'integer', 'description' => 'Message UID to delete'],
				'instance' => ['type' => 'string', 'description' => 'Mail account name (optional, uses default)'],
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
