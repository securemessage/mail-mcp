<?php
/**
 * SecureMessage Mail MCP Server — Search Tools
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
		description: 'Search for emails using flexible filters. Combine any filters: from, to, cc, subject, body text, date range, read/unread, answered/unanswered, flagged, keyword. All filters are ANDed together. Returns message headers (not full body). Use mail_get_message for full content.',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'from' => ['type' => 'string', 'description' => 'Filter by sender email address or name'],
				'to' => ['type' => 'string', 'description' => 'Filter by recipient email address'],
				'cc' => ['type' => 'string', 'description' => 'Filter by CC recipient email address'],
				'subject' => ['type' => 'string', 'description' => 'Filter by subject keywords'],
				'body' => ['type' => 'string', 'description' => 'Filter by body text content'],
				'since' => ['type' => 'string', 'description' => 'Messages since date (e.g., "2026-01-01", "01-Jan-2026")'],
				'before' => ['type' => 'string', 'description' => 'Messages before date'],
				'unread' => ['type' => 'boolean', 'description' => 'Only unread messages (default: false)'],
				'answered' => ['type' => 'boolean', 'description' => 'Only messages that have been replied to (default: false)'],
				'unanswered' => ['type' => 'boolean', 'description' => 'Only messages that have NOT been replied to (default: false)'],
				'flagged' => ['type' => 'boolean', 'description' => 'Only flagged/starred messages (default: false)'],
				'keyword' => ['type' => 'string', 'description' => 'Filter by IMAP keyword (user-defined flag)'],
				'limit' => ['type' => 'integer', 'description' => 'Maximum number of results (default: 50)'],
				'mailbox' => ['type' => 'string', 'description' => 'Mailbox to search (default: current or INBOX)'],
				'instance' => ['type' => 'string', 'description' => 'Mail account name (optional, uses default)'],
			],
		]
	)]
	public function mail_search(
		string $from = '',
		string $to = '',
		string $cc = '',
		string $subject = '',
		string $body = '',
		string $since = '',
		string $before = '',
		bool $unread = false,
		bool $answered = false,
		bool $unanswered = false,
		bool $flagged = false,
		string $keyword = '',
		int $limit = 50,
		string $mailbox = '',
		string $instance = ''
	): array {
		$client = $this->manager->getImapClient($instance ?: null);

		// Build IMAP search criteria
		$criteria = $this->buildCriteria(
			$from, $to, $cc, $subject, $body,
			$since, $before, $unread, $answered, $unanswered,
			$flagged, $keyword
		);

		// Single-mailbox search when explicitly requested
		if (!empty($mailbox)) {
			$client->selectMailbox($mailbox, true);
			return $this->searchInMailbox($client, $criteria, $limit, $instance);
		}

		// Multi-mailbox search: search across all available mailboxes
		return $this->searchMultipleMailboxes($client, $criteria, $limit, $instance);
	}

	/**
	 * Build IMAP search criteria from parameters.
	 */
	private function buildCriteria(
		string $from, string $to, string $cc, string $subject, string $body,
		string $since, string $before, bool $unread, bool $answered, bool $unanswered,
		bool $flagged, string $keyword
	): array {
		$criteria = [];

		if (!empty($from)) {
			$criteria['FROM'] = $from;
		}
		if (!empty($to)) {
			$criteria['TO'] = $to;
		}
		if (!empty($cc)) {
			$criteria['CC'] = $cc;
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
		if ($answered) {
			$criteria[] = 'ANSWERED';
		}
		if ($unanswered) {
			$criteria[] = 'UNANSWERED';
		}
		if ($flagged) {
			$criteria[] = 'FLAGGED';
		}
		if (!empty($keyword)) {
			$criteria['KEYWORD'] = $keyword;
		}

		return $criteria;
	}

	/**
	 * Search a single (already-selected) mailbox and return results.
	 */
	private function searchInMailbox(\Mail\ImapClientInterface $client, array $criteria, int $limit, string $instance): array
	{
		$uids = $client->search($criteria);

		$totalMatches = count($uids);
		if ($totalMatches > $limit) {
			$uids = array_slice($uids, -$limit);
		}

		$messages = [];
		if (!empty($uids)) {
			$messages = $client->fetchHeaders($uids);
		}

		usort($messages, fn($a, $b) => $b->uid - $a->uid);

		return [
			'instance' => $instance ?: $this->manager->getDefault(),
			'total_matches' => $totalMatches,
			'returned' => count($messages),
			'messages' => array_map(fn($m) => $m->toArray(), $messages),
		];
	}

	/**
	 * Search across all available mailboxes and merge results.
	 */
	private function searchMultipleMailboxes(\Mail\ImapClientInterface $client, array $criteria, int $limit, string $instance): array
	{
		$allMailboxes = $client->listMailboxes();
		$allMessages = [];
		$totalMatches = 0;
		$searchedMailboxes = [];

		foreach ($allMailboxes as $mb) {
			// Skip non-selectable mailboxes
			if (in_array('\\Noselect', $mb->flags)) {
				continue;
			}

			try {
				$client->selectMailbox($mb->name, true);
				$uids = $client->search($criteria);
				$totalMatches += count($uids);

				if (!empty($uids)) {
					$searchedMailboxes[] = $mb->name;
					// Fetch headers for this mailbox's results
					$messages = $client->fetchHeaders($uids);
					$allMessages = array_merge($allMessages, $messages);
				}
			} catch (\Throwable $e) {
				// Skip mailboxes that fail (e.g., permission issues)
				continue;
			}
		}

		// Sort all results by date descending (newest first)
		usort($allMessages, function ($a, $b) {
			$dateA = strtotime($a->date) ?: 0;
			$dateB = strtotime($b->date) ?: 0;
			return $dateB - $dateA;
		});

		// Apply limit
		if (count($allMessages) > $limit) {
			$allMessages = array_slice($allMessages, 0, $limit);
		}

		$result = [
			'instance' => $instance ?: $this->manager->getDefault(),
			'total_matches' => $totalMatches,
			'returned' => count($allMessages),
			'mailboxes_searched' => $searchedMailboxes,
			'messages' => array_map(fn($m) => $m->toArray(), $allMessages),
		];

		// Re-select INBOX so subsequent operations work on the default mailbox
		try {
			$client->selectMailbox('INBOX', true);
		} catch (\Throwable $e) {
			// Non-fatal
		}

		return $result;
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
