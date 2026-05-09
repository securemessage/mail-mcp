<?php
/**
 * Mail MCP Server — Draft Tools
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

		// If replying to a message, set threading headers
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
			} catch (\Throwable $e) {
				// Non-fatal — continue without threading headers
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
	 */
	private function parseAddresses(string $addresses): array
	{
		if (empty($addresses)) {
			return [];
		}

		$result = [];
		foreach (explode(',', $addresses) as $addr) {
			$addr = trim($addr);
			if (preg_match('/<([^>]+)>/', $addr, $m)) {
				$addr = $m[1];
			}
			if (!empty($addr)) {
				$result[] = $addr;
			}
		}

		return $result;
	}
}
