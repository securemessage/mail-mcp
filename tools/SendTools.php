<?php
/**
 * SecureMessage Mail MCP Server — Send/Reply Tools
 *
 * @package    MailMCP\Tools
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

use EnchiladaMCP\McpTool;
use Mail\InstanceManager;
use Mail\MessageBuilder;

class SendTools
{
	private InstanceManager $manager;

	public function __construct(InstanceManager $manager)
	{
		$this->manager = $manager;
	}

	/**
	 * Send a new email.
	 */
	#[McpTool(
		name: 'mail_send',
		description: 'Send a new email via SMTP. Requires an active SMTP connection. Provide at least text or html body. Supports file attachments via absolute paths.',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'to' => ['type' => 'string', 'description' => 'Recipient email address(es), comma-separated'],
				'subject' => ['type' => 'string', 'description' => 'Email subject'],
				'text' => ['type' => 'string', 'description' => 'Plain text email body'],
				'html' => ['type' => 'string', 'description' => 'HTML email body (optional, creates multipart if both text and html provided)'],
				'cc' => ['type' => 'string', 'description' => 'CC recipients, comma-separated (optional)'],
				'bcc' => ['type' => 'string', 'description' => 'BCC recipients, comma-separated (optional)'],
				'attachments' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Absolute file paths to attach (optional)'],
				'instance' => ['type' => 'string', 'description' => 'Mail account name (optional, uses default)'],
			],
			'required' => ['to', 'subject'],
		]
	)]
	public function mail_send(
		string $to,
		string $subject,
		string $text = '',
		string $html = '',
		string $cc = '',
		string $bcc = '',
		array $attachments = [],
		string $instance = ''
	): array {
		$name = $instance ?: null;
		$config = $this->manager->getConfig($name);
		$smtp = $this->manager->getSmtpClient($name);

		$builder = new MessageBuilder();
		$builder->setFrom($this->resolveFrom($config));
		$builder->setSubject($subject);

		foreach ($this->parseAddresses($to) as $addr) {
			$builder->addTo($addr);
		}
		foreach ($this->parseAddresses($cc) as $addr) {
			$builder->addCc($addr);
		}
		foreach ($this->parseAddresses($bcc) as $addr) {
			$builder->addBcc($addr);
		}

		if (!empty($text)) {
			$builder->setTextBody($text);
		}
		if (!empty($html)) {
			$builder->setHtmlBody($html);
		}
		if (empty($text) && empty($html)) {
			$builder->setTextBody('');
		}

		// Attach files from disk
		$attachedFiles = [];
		foreach ($attachments as $filePath) {
			if (!file_exists($filePath)) {
				return ['error' => "Attachment file not found: {$filePath}"];
			}
			if (!is_readable($filePath)) {
				return ['error' => "Attachment file not readable: {$filePath}"];
			}
			$content = file_get_contents($filePath);
			if ($content === false) {
				return ['error' => "Failed to read attachment: {$filePath}"];
			}
			$filename = basename($filePath);
			$mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
			$builder->addAttachment($filename, $content, $mimeType);
			$attachedFiles[] = $filename;
		}

		$rawMessage = $builder->build();
		$recipients = $builder->getAllRecipients();
		$envelopeSender = $this->extractAddrSpec($this->resolveFrom($config));

		$smtp->send($envelopeSender, $recipients, $rawMessage);

		$result = [
			'instance' => $instance ?: $this->manager->getDefault(),
			'sent' => true,
			'to' => $to,
			'subject' => $subject,
			'recipients' => count($recipients),
		];

		if (!empty($attachedFiles)) {
			$result['attachments'] = $attachedFiles;
		}

		// Save to Sent folder (best-effort)
		$result['saved_to_sent'] = $this->saveToSentFolder($name, $rawMessage);

		return $result;
	}

	/**
	 * Reply to an existing email.
	 */
	#[McpTool(
		name: 'mail_reply',
		description: 'Reply to an existing email. Fetches the original message to set proper In-Reply-To and References headers. Creates Re: subject prefix if not already present. By default includes the quoted original message body below the reply text.',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'uid' => ['type' => 'integer', 'description' => 'UID of the message to reply to'],
				'text' => ['type' => 'string', 'description' => 'Reply text body'],
				'html' => ['type' => 'string', 'description' => 'Reply HTML body (optional)'],
				'reply_all' => ['type' => 'boolean', 'description' => 'Reply to all recipients (default: false)'],
				'cc' => ['type' => 'string', 'description' => 'CC recipients, comma-separated (optional, overrides original CC list)'],
				'bcc' => ['type' => 'string', 'description' => 'BCC recipients, comma-separated (optional)'],
				'draft' => ['type' => 'boolean', 'description' => 'Save as draft instead of sending (default: false)'],
				'include_original' => ['type' => 'boolean', 'description' => 'Include quoted original message in reply (default: true)'],
				'instance' => ['type' => 'string', 'description' => 'Mail account name (optional, uses default)'],
			],
			'required' => ['uid', 'text'],
		]
	)]
	public function mail_reply(
		int $uid,
		string $text,
		string $html = '',
		bool $reply_all = false,
		string $cc = '',
		string $bcc = '',
		bool $draft = false,
		bool $include_original = true,
		string $instance = ''
	): array {
		$name = $instance ?: null;
		$config = $this->manager->getConfig($name);
		$imap = $this->manager->getImapClient($name);

		if (!$draft) {
			$smtp = $this->manager->getSmtpClient($name);
		}

		// Fetch original message (full body needed for quoting)
		$original = $imap->fetchMessage($uid, false);

		$builder = new MessageBuilder();
		$builder->setFrom($this->resolveFrom($config));

		// Set reply subject
		$subject = $original->subject;
		if (!preg_match('/^Re:/i', $subject)) {
			$subject = 'Re: ' . $subject;
		}
		$builder->setSubject($subject);

		// Set In-Reply-To and References
		if (!empty($original->messageId)) {
			$builder->setInReplyTo($original->messageId);
			$builder->setReferences($original->messageId);
		}

		// Reply-to address (use Reply-To header if present, otherwise From)
		$replyToAddr = !empty($original->replyTo) ? $original->replyTo : $original->from;
		foreach ($this->parseAddresses($replyToAddr) as $addr) {
			$builder->addTo($addr);
		}

		// CC/BCC handling: explicit params override, then reply_all, then nothing
		$excludedCc = [];
		$hasCcOverride = !empty($cc);
		$hasBccOverride = !empty($bcc);

		if ($hasCcOverride) {
			foreach ($this->parseAddresses($cc) as $addr) {
				$builder->addCc($addr);
			}
		}
		if ($hasBccOverride) {
			foreach ($this->parseAddresses($bcc) as $addr) {
				$builder->addBcc($addr);
			}
		}

		$resolvedFrom = $this->resolveFrom($config);
		// Extract bare addr-spec for self-exclusion comparison
		$myAddress = strtolower($resolvedFrom);
		if (preg_match('/<([^>]+)>/', $myAddress, $fm)) {
			$myAddress = strtolower($fm[1]);
		}

		if ($reply_all) {
			foreach ($this->parseAddresses($original->to) as $addr) {
				if (strtolower($addr) !== $myAddress) {
					$builder->addTo($addr);
				}
			}
			if (!$hasCcOverride) {
				foreach ($this->parseAddresses($original->cc) as $addr) {
					if (strtolower($addr) !== $myAddress) {
						$builder->addCc($addr);
					}
				}
			}
		} elseif (!$hasCcOverride) {
			// Track excluded CC recipients for the response
			foreach ($this->parseAddresses($original->cc) as $addr) {
				if (strtolower($addr) !== $myAddress) {
					$excludedCc[] = $addr;
				}
			}
		}

		// Build reply body with quoted original
		$replyText = $text;
		$replyHtml = $html;

		if ($include_original) {
			$attribution = $this->buildAttribution($original->date, $original->from);

			// Text body with quoted original
			if ($original->textBody !== null) {
				$quotedText = $this->quoteTextBody($original->textBody);
				$replyText = $text . "\n\n" . $attribution . "\n" . $quotedText;
			}

			// HTML body with quoted original
			if (!empty($html) || $original->htmlBody !== null) {
				$origHtmlContent = $original->htmlBody ?? nl2br(htmlspecialchars($original->textBody ?? '', ENT_QUOTES, 'UTF-8'));
				$htmlAttribution = htmlspecialchars($attribution, ENT_QUOTES, 'UTF-8');
				$replyHtmlContent = !empty($html) ? $html : nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
				$replyHtml = $replyHtmlContent
					. '<br><br><blockquote style="margin:0 0 0 0.8ex;border-left:1px solid #ccc;padding-left:1ex">'
					. '<p>' . $htmlAttribution . '</p>'
					. $origHtmlContent
					. '</blockquote>';
			}
		}

		$builder->setTextBody($replyText);
		if (!empty($replyHtml)) {
			$builder->setHtmlBody($replyHtml);
		}

		$rawMessage = $builder->build();
		$recipients = $builder->getAllRecipients();

		if ($draft) {
			$draftsFolder = $this->findDraftsMailbox($imap);
			if ($draftsFolder === null) {
				return ['error' => 'Could not find Drafts folder on the mail server'];
			}
			$imap->appendMessage($draftsFolder, $rawMessage, ['\\Draft']);

			$result = [
				'instance' => $instance ?: $this->manager->getDefault(),
				'draft_created' => true,
				'drafts_folder' => $draftsFolder,
				'reply_to_uid' => $uid,
				'subject' => $subject,
				'recipients' => count($recipients),
			];
		} else {
			$envelopeSender = $this->extractAddrSpec($this->resolveFrom($config));
			$smtp->send($envelopeSender, $recipients, $rawMessage);

			$result = [
				'instance' => $instance ?: $this->manager->getDefault(),
				'sent' => true,
				'reply_to_uid' => $uid,
				'subject' => $subject,
				'recipients' => count($recipients),
				'reply_all' => $reply_all,
				'include_original' => $include_original,
			];

			// Save to Sent folder (best-effort)
			$result['saved_to_sent'] = $this->saveToSentFolder($name, $rawMessage);
		}

		if (!empty($excludedCc)) {
			$result['excluded_cc'] = $excludedCc;
			$result['note'] = sprintf(
				'%d CC recipient(s) from original message not included: %s. Use reply_all: true or cc parameter to include them.',
				count($excludedCc),
				implode(', ', $excludedCc)
			);
		}

		return $result;
	}

	/** Common sent folder names to try, in priority order. */
	private const SENT_FOLDER_NAMES = ['Sent', 'Sent Items', 'Sent Messages', 'INBOX.Sent'];

	/**
	 * Save a sent message to the Sent folder (best-effort).
	 *
	 * Respects the "save_to_sent" config option (default: true).
	 * Exchange/O365 auto-saves sent messages server-side, so users
	 * on those servers should set save_to_sent: false to avoid duplicates.
	 *
	 * @param  string|null $instance    Instance name
	 * @param  string      $rawMessage  Raw RFC 2822 message
	 * @return bool                     True if saved successfully
	 */
	private function saveToSentFolder(?string $instance, string $rawMessage): bool
	{
		try {
			$config = $this->manager->getConfig($instance);

			// Respect save_to_sent config (default: true)
			if (($config['save_to_sent'] ?? true) === false) {
				return false;
			}

			$imap = $this->manager->getImapClient($instance);
			if (!$imap->isConnected()) {
				return false;
			}

			$sentFolder = $this->findSentMailbox($imap);
			if ($sentFolder === null) {
				return false;
			}

			$imap->appendMessage($sentFolder, $rawMessage, ['\\Seen']);
			return true;
		} catch (\Throwable $e) {
			// Non-fatal — email was already sent via SMTP
			return false;
		}
	}

	/**
	 * Find the Sent mailbox by checking \Sent attribute or common names.
	 */
	private function findSentMailbox(\Mail\ImapClientInterface $imap): ?string
	{
		// Check all mailboxes for \Sent attribute (RFC 6154)
		$mailboxes = $imap->listMailboxes();
		foreach ($mailboxes as $mb) {
			if (in_array('\\Sent', $mb->flags)) {
				return $mb->name;
			}
		}

		// Fall back to common names
		$names = array_map(fn($mb) => $mb->name, $mailboxes);
		foreach (self::SENT_FOLDER_NAMES as $candidate) {
			if (in_array($candidate, $names)) {
				return $candidate;
			}
		}

		return null;
	}

	/** Common drafts folder names to try, in priority order. */
	private const DRAFTS_FOLDER_NAMES = ['Drafts', 'Draft', 'INBOX.Drafts'];

	/**
	 * Find the Drafts mailbox by checking \Drafts attribute or common names.
	 */
	private function findDraftsMailbox(\Mail\ImapClientInterface $imap): ?string
	{
		$mailboxes = $imap->listMailboxes();

		foreach ($mailboxes as $mb) {
			if (in_array('\\Drafts', $mb->flags)) {
				return $mb->name;
			}
		}

		$names = array_map(fn($mb) => $mb->name, $mailboxes);
		foreach (self::DRAFTS_FOLDER_NAMES as $candidate) {
			if (in_array($candidate, $names)) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Build attribution line for quoted replies.
	 */
	private function buildAttribution(string $date, string $from): string
	{
		$formattedDate = $date;
		$timestamp = strtotime($date);
		if ($timestamp !== false) {
			$formattedDate = date('D, j M Y', $timestamp);
		}

		// Extract display name or email from "Name <email>" format
		$sender = $from;
		if (preg_match('/^"?([^"<]+)"?\s*</', $from, $m)) {
			$sender = trim($m[1]);
		}

		return "On {$formattedDate}, {$sender} wrote:";
	}

	/**
	 * Quote a plain text body for reply (prefix each line with >).
	 */
	private function quoteTextBody(string $body): string
	{
		$lines = explode("\n", str_replace("\r\n", "\n", $body));
		$quoted = array_map(function ($line) {
			return '> ' . $line;
		}, $lines);
		return implode("\n", $quoted);
	}

	/**
	 * Resolve the From address from instance config.
	 *
	 * Prefers the explicit 'from' key (which may include a display name).
	 * Falls back to 'username' only if it contains an @.
	 */
	private function resolveFrom(array $config): string
	{
		if (!empty($config['from'])) {
			return $config['from'];
		}
		return $config['username'];
	}

	/**
	 * Extract the bare addr-spec from a From value.
	 *
	 * "Display Name <user@domain>" → "user@domain"
	 * "user@domain" → "user@domain"
	 */
	private function extractAddrSpec(string $from): string
	{
		if (preg_match('/<([^>]+)>/', $from, $m)) {
			return $m[1];
		}
		return $from;
	}

	/**
	 * Parse comma-separated address string into array.
	 *
	 * Respects RFC 5322 quoted display names so that commas inside
	 * quotes (e.g. "Last, First" <user@example.com>) are not treated
	 * as address separators.
	 */
	private function parseAddresses(string $addresses): array
	{
		if (empty($addresses)) {
			return [];
		}

		$result = [];
		$current = '';
		$inQuotes = false;
		$len = strlen($addresses);

		for ($i = 0; $i < $len; $i++) {
			$ch = $addresses[$i];
			if ($ch === '"') {
				$inQuotes = !$inQuotes;
				$current .= $ch;
			} elseif ($ch === ',' && !$inQuotes) {
				$addr = trim($current);
				if (preg_match('/<([^>]+)>/', $addr, $m)) {
					$addr = $m[1];
				}
				if (!empty($addr)) {
					$result[] = $addr;
				}
				$current = '';
			} else {
				$current .= $ch;
			}
		}

		// Last address
		$addr = trim($current);
		if (preg_match('/<([^>]+)>/', $addr, $m)) {
			$addr = $m[1];
		}
		if (!empty($addr)) {
			$result[] = $addr;
		}

		return $result;
	}
}
