<?php
/**
 * SecureMessage Mail MCP Server — Mailbox Tools
 *
 * @package    MailMCP\Tools
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

use EnchiladaMCP\McpTool;
use Mail\InstanceManager;

class MailboxTools
{
	private InstanceManager $manager;

	public function __construct(InstanceManager $manager)
	{
		$this->manager = $manager;
	}

	/**
	 * List available mailboxes/folders.
	 */
	#[McpTool(
		name: 'mail_list_mailboxes',
		readOnlyHint: true,
		description: 'List mailboxes (folders). Requires an IMAP connection.',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'instance' => ['type' => 'string', 'description' => 'Mail account; omit for default'],
			],
		]
	)]
	public function mail_list_mailboxes(string $instance = ''): array
	{
		$client = $this->manager->getImapClient($instance ?: null);
		$mailboxes = $client->listMailboxes();

		return [
			'instance' => $instance ?: $this->manager->getDefault(),
			'mailboxes' => array_map(fn($m) => $m->toArray(), $mailboxes),
		];
	}

	/**
	 * Open/select a mailbox.
	 */
	#[McpTool(
		name: 'mail_open_mailbox',
		readOnlyHint: true,
		description: 'Select a mailbox for subsequent message operations; returns counts, status and available flags.',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'mailbox' => ['type' => 'string', 'description' => 'default INBOX'],
				'read_only' => ['type' => 'boolean', 'description' => 'default false'],
				'instance' => ['type' => 'string', 'description' => 'Mail account; omit for default'],
			],
		]
	)]
	public function mail_open_mailbox(string $mailbox = 'INBOX', bool $read_only = false, string $instance = ''): array
	{
		$client = $this->manager->getImapClient($instance ?: null);
		$result = $client->selectMailbox($mailbox, $read_only);

		return [
			'instance' => $instance ?: $this->manager->getDefault(),
			'mailbox' => $result->toArray(),
		];
	}
}
