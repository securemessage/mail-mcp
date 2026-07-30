<?php
/**
 * SecureMessage Mail MCP Server — Draft Tools
 *
 * @package    MailMCP\Tools
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

use EnchiladaMCP\McpTool;
use Mail\InstanceManager;
use Mail\MessageBuilder;

class DraftTools
{
	private InstanceManager $manager;

	public function __construct(InstanceManager $manager)
	{
		$this->manager = $manager;
	}

	/** Common drafts folder names to try, in priority order. */
	private const DRAFTS_FOLDER_NAMES = ['Drafts', 'Draft', 'INBOX.Drafts'];

	/**
	 * Create a draft email for later review and sending.
	 */
	#[McpTool(
		name: 'mail_create_draft',
		description: 'Create a draft email that can be reviewed before sending. The draft is saved to the Drafts folder via IMAP and can be edited in any mail client. Supports text, HTML, CC/BCC, and file attachments.',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'to' => ['type' => 'string', 'description' => 'Recipient email address(es), comma-separated'],
				'subject' => ['type' => 'string', 'description' => 'Email subject'],
				'text' => ['type' => 'string', 'description' => 'Plain text email body'],
				'html' => ['type' => 'string', 'description' => 'HTML email body (optional, creates multipart if both text and html provided)'],
				'cc' => ['type' => 'string', 'description' => 'CC recipients, comma-separated (optional)'],
				'bcc' => ['type' => 'string', 'description' => 'BCC recipients, comma-separated (optional)'],
				'in_reply_to' => ['type' => 'integer', 'description' => 'UID of message this is a reply to (optional, sets In-Reply-To and Re: subject)'],
				'attachments' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Absolute file paths to attach (optional)'],
				'instance' => ['type' => 'string', 'description' => 'Mail account name (optional, uses default)'],
			],
			'required' => ['to', 'subject'],
		]
	)]
	public function mail_create_draft(
		string $to,
		string $subject,
		string $text = '',
		string $html = '',
		string $cc = '',
		string $bcc = '',
		int $in_reply_to = 0,
		array $attachments = [],
		string $instance = ''
	): array {
		$name = $instance ?: null;
		$config = $this->manager->getConfig($name);
		$imap = $this->manager->getImapClient($name);

		if (!$imap->isConnected()) {
			return ['error' => 'IMAP not connected. Use mail_connect first.'];
		}

		$builder = new MessageBuilder();
		$builder->setFrom($config['username']);
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

		// If replying to a message, set threading headers and quote original
		if ($in_reply_to > 0) {
			try {
				$original = $imap->fetchMessage($in_reply_to, false);
				if (!empty($original->messageId)) {
					$builder->setInReplyTo($original->messageId);
					$builder->setReferences($original->messageId);
				}
				if (!preg_match('/^Re:/i', $subject)) {
					$builder->setSubject('Re: ' . $subject);
				}

				// Append quoted original body (same as mail_reply with include_original)
				$attribution = $this->buildAttribution($original->date, $original->from);

				if (!empty($text) && $original->textBody !== null) {
					$quotedText = $this->quoteTextBody($original->textBody);
					$text = $text . "\n\n" . $attribution . "\n" . $quotedText;
				}

				if (!empty($html) || $original->htmlBody !== null) {
					$origHtmlContent = $original->htmlBody ?? nl2br(htmlspecialchars($original->textBody ?? '', ENT_QUOTES, 'UTF-8'));
					$htmlAttribution = htmlspecialchars($attribution, ENT_QUOTES, 'UTF-8');
					$replyHtmlContent = !empty($html) ? $html : nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
					$html = $replyHtmlContent
						. '<br><br><blockquote style="margin:0 0 0 0.8ex;border-left:1px solid #ccc;padding-left:1ex">'
						. '<p>' . $htmlAttribution . '</p>'
						. $origHtmlContent
						. '</blockquote>';
				}
			} catch (\Throwable $e) {
				// Non-fatal — continue without threading headers or quoted body
			}
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

		// Find Drafts folder
		$draftsFolder = $this->findDraftsMailbox($imap);
		if ($draftsFolder === null) {
			return ['error' => 'Could not find Drafts folder on the mail server'];
		}

		// APPEND to Drafts with \Draft flag
		$imap->appendMessage($draftsFolder, $rawMessage, ['\\Draft']);

		$result = [
			'instance' => $instance ?: $this->manager->getDefault(),
			'draft_created' => true,
			'drafts_folder' => $draftsFolder,
			'to' => $to,
			'subject' => $subject,
		];

		if (!empty($attachedFiles)) {
			$result['attachments'] = $attachedFiles;
		}

		return $result;
	}

	/**
	 * Find the Drafts mailbox by checking \Drafts attribute or common names.
	 */
	private function findDraftsMailbox(\Mail\ImapClientInterface $imap): ?string
	{
		$mailboxes = $imap->listMailboxes();

		// Check for \Drafts attribute (RFC 6154)
		foreach ($mailboxes as $mb) {
			if (in_array('\\Drafts', $mb->flags)) {
				return $mb->name;
			}
		}

		// Fall back to common names
		$names = array_map(fn($mb) => $mb->name, $mailboxes);
		foreach (self::DRAFTS_FOLDER_NAMES as $candidate) {
			if (in_array($candidate, $names)) {
				return $candidate;
			}
		}

		return null;
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
}
