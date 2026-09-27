<?php
/**
 * SecureMessage Mail MCP Server — Organization Tools
 *
 * Move messages between folders and manage flags/labels.
 *
 * @package    MailMCP\Tools
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

use EnchiladaMCP\McpTool;
use Mail\InstanceManager;

class OrganizationTools
{
	private InstanceManager $manager;

	public function __construct(InstanceManager $manager)
	{
		$this->manager = $manager;
	}

	/**
	 * Move a message to a different folder.
	 */
	#[McpTool(
		name: 'mail_move_message',
		description: 'Move a message to another folder (IMAP COPY + delete). Folder names come from mail_list_mailboxes.',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'uid' => ['type' => 'integer'],
				'target_folder' => ['type' => 'string', 'description' => 'e.g. "Archive", "Trash"'],
				'source_folder' => ['type' => 'string', 'description' => 'default INBOX'],
				'instance' => ['type' => 'string', 'description' => 'Mail account; omit for default'],
			],
			'required' => ['uid', 'target_folder'],
		]
	)]
	public function mail_move_message(int $uid, string $target_folder, string $source_folder = 'INBOX', string $instance = ''): array
	{
		$client = $this->manager->getImapClient($instance ?: null);

		// Select the source folder in read-write mode
		$client->selectMailbox($source_folder, false);

		// Copy to target folder
		$client->copyMessage($uid, $target_folder);

		// Delete from source (sets \Deleted + EXPUNGE)
		$client->deleteMessage($uid);

		return [
			'instance' => $instance ?: $this->manager->getDefault(),
			'moved' => true,
			'uid' => $uid,
			'from' => $source_folder,
			'to' => $target_folder,
		];
	}

	/**
	 * Set or remove flags/labels on messages.
	 */
	#[McpTool(
		name: 'mail_set_flags',
		description: 'Add/remove flags on messages: \Seen, \Flagged, \Answered, \Draft, \Deleted, or keywords like $Important (mail_open_mailbox lists those available).',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'uids' => ['type' => 'array', 'items' => ['type' => 'integer']],
				'add_flags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'e.g. ["\\Flagged", "$Important"]'],
				'remove_flags' => ['type' => 'array', 'items' => ['type' => 'string']],
				'mailbox' => ['type' => 'string', 'description' => 'Mailbox holding the messages (default INBOX)'],
				'instance' => ['type' => 'string', 'description' => 'Mail account; omit for default'],
			],
			'required' => ['uids'],
		]
	)]
	public function mail_set_flags(array $uids, array $add_flags = [], array $remove_flags = [], string $mailbox = 'INBOX', string $instance = ''): array
	{
		if (empty($add_flags) && empty($remove_flags)) {
			return ['error' => 'At least one of add_flags or remove_flags must be specified'];
		}

		$client = $this->manager->getImapClient($instance ?: null);

		// Must select in read-write mode for STORE operations
		$client->selectMailbox($mailbox, false);

		$modified = 0;
		foreach ($uids as $uid) {
			$uid = (int) $uid;
			if (!empty($add_flags)) {
				$client->addFlags($uid, $add_flags);
			}
			if (!empty($remove_flags)) {
				$client->removeFlags($uid, $remove_flags);
			}
			$modified++;
		}

		$result = [
			'instance' => $instance ?: $this->manager->getDefault(),
			'modified' => $modified,
		];

		if (!empty($add_flags)) {
			$result['added'] = $add_flags;
		}
		if (!empty($remove_flags)) {
			$result['removed'] = $remove_flags;
		}

		return $result;
	}

	/**
	 * Create a new mailbox/folder.
	 */
	#[McpTool(
		name: 'mail_create_mailbox',
		description: 'Create a mailbox (folder).',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'name' => ['type' => 'string', 'description' => 'e.g. "Archive" or "Work/Projects"'],
				'instance' => ['type' => 'string', 'description' => 'Mail account; omit for default'],
			],
			'required' => ['name'],
		]
	)]
	public function mail_create_mailbox(string $name, string $instance = ''): array
	{
		$client = $this->manager->getImapClient($instance ?: null);
		$client->createMailbox($name);

		return [
			'instance' => $instance ?: $this->manager->getDefault(),
			'created' => true,
			'mailbox' => $name,
		];
	}
}
