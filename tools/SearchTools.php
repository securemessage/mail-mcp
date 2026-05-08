<?php
/**
 * Mail MCP Server — Search Tools
 *
 * @package    MailMCP\Tools
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

use EnchiladaMCP\McpTool;
use Mail\InstanceManager;

class SearchTools
{
	private InstanceManager $manager;

	public function __construct(InstanceManager $manager)
	{
		$this->manager = $manager;
	}

	/**
	 * Search for emails with flexible filters.
	 */
	#[McpTool(
		name: 'mail_search',
		description: 'Search for emails using flexible filters. Combine any filters: from, to, subject, body text, date range, read/unread status. Returns message headers (not full body). Use mail_get_message for full content.',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'from' => ['type' => 'string', 'description' => 'Filter by sender email address or name'],
				'to' => ['type' => 'string', 'description' => 'Filter by recipient email address'],
				'subject' => ['type' => 'string', 'description' => 'Filter by subject keywords'],
				'body' => ['type' => 'string', 'description' => 'Filter by body text content'],
				'since' => ['type' => 'string', 'description' => 'Messages since date (e.g., "2026-01-01", "01-Jan-2026")'],
				'before' => ['type' => 'string', 'description' => 'Messages before date'],
				'unread' => ['type' => 'boolean', 'description' => 'Only unread messages (default: false)'],
				'flagged' => ['type' => 'boolean', 'description' => 'Only flagged/starred messages (default: false)'],
				'limit' => ['type' => 'integer', 'description' => 'Maximum number of results (default: 50)'],
				'mailbox' => ['type' => 'string', 'description' => 'Mailbox to search (default: current or INBOX)'],
				'instance' => ['type' => 'string', 'description' => 'Mail account name (optional, uses default)'],
			],
		]
	)]
	public function mail_search(
		string $from = '',
		string $to = '',
		string $subject = '',
		string $body = '',
		string $since = '',
		string $before = '',
		bool $unread = false,
		bool $flagged = false,
		int $limit = 50,
		string $mailbox = '',
		string $instance = ''
	): array {
		$client = $this->manager->getImapClient($instance ?: null);

		// Select mailbox if specified
		if (!empty($mailbox)) {
			$client->selectMailbox($mailbox, true);
		}

		// Build IMAP search criteria
		$criteria = [];

		if (!empty($from)) {
			$criteria['FROM'] = $from;
		}
		if (!empty($to)) {
			$criteria['TO'] = $to;
		}
		if (!empty($subject)) {
			$criteria['SUBJECT'] = $subject;
		}
		if (!empty($body)) {
			$criteria['BODY'] = $body;
		}
		if (!empty($since)) {
			$criteria['SINCE'] = $this->formatDate($since);
		}
		if (!empty($before)) {
			$criteria['BEFORE'] = $this->formatDate($before);
		}
		if ($unread) {
			$criteria[] = 'UNSEEN';
		}
		if ($flagged) {
			$criteria[] = 'FLAGGED';
		}

		$uids = $client->search($criteria);

		// Apply limit (take the most recent UIDs — highest numbers)
		$totalMatches = count($uids);
		if ($totalMatches > $limit) {
			$uids = array_slice($uids, -$limit);
		}

		// Fetch headers for matching UIDs
		$messages = [];
		if (!empty($uids)) {
			$messages = $client->fetchHeaders($uids);
		}

		// Sort by UID descending (newest first)
		usort($messages, fn($a, $b) => $b->uid - $a->uid);

		return [
			'instance' => $instance ?: $this->manager->getDefault(),
			'total_matches' => $totalMatches,
			'returned' => count($messages),
			'messages' => array_map(fn($m) => $m->toArray(), $messages),
		];
	}

	/**
	 * Format a date string for IMAP SEARCH (DD-Mon-YYYY format).
	 */
	private function formatDate(string $date): string
	{
		$timestamp = strtotime($date);
		if ($timestamp === false) {
			return $date;
		}
		return date('d-M-Y', $timestamp);
	}
}
