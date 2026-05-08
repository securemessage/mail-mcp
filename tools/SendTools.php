<?php
/**
 * Mail MCP Server — Send/Reply Tools
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
		description: 'Send a new email via SMTP. Requires an active SMTP connection. Provide at least text or html body.',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'to' => ['type' => 'string', 'description' => 'Recipient email address(es), comma-separated'],
				'subject' => ['type' => 'string', 'description' => 'Email subject'],
				'text' => ['type' => 'string', 'description' => 'Plain text email body'],
				'html' => ['type' => 'string', 'description' => 'HTML email body (optional, creates multipart if both text and html provided)'],
				'cc' => ['type' => 'string', 'description' => 'CC recipients, comma-separated (optional)'],
				'bcc' => ['type' => 'string', 'description' => 'BCC recipients, comma-separated (optional)'],
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
		string $instance = ''
	): array {
		$name = $instance ?: null;
		$config = $this->manager->getConfig($name);
		$smtp = $this->manager->getSmtpClient($name);

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

		if (!empty($text)) {
			$builder->setTextBody($text);
		}
		if (!empty($html)) {
			$builder->setHtmlBody($html);
		}
		if (empty($text) && empty($html)) {
			$builder->setTextBody('');
		}

		$rawMessage = $builder->build();
		$recipients = $builder->getAllRecipients();

		$smtp->send($config['username'], $recipients, $rawMessage);

		return [
			'instance' => $instance ?: $this->manager->getDefault(),
			'sent' => true,
			'to' => $to,
			'subject' => $subject,
			'recipients' => count($recipients),
		];
	}

	/**
	 * Reply to an existing email.
	 */
	#[McpTool(
		name: 'mail_reply',
		description: 'Reply to an existing email. Fetches the original message to set proper In-Reply-To and References headers. Creates Re: subject prefix if not already present.',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'uid' => ['type' => 'integer', 'description' => 'UID of the message to reply to'],
				'text' => ['type' => 'string', 'description' => 'Reply text body'],
				'html' => ['type' => 'string', 'description' => 'Reply HTML body (optional)'],
				'reply_all' => ['type' => 'boolean', 'description' => 'Reply to all recipients (default: false)'],
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
		string $instance = ''
	): array {
		$name = $instance ?: null;
		$config = $this->manager->getConfig($name);
		$imap = $this->manager->getImapClient($name);
		$smtp = $this->manager->getSmtpClient($name);

		// Fetch original message headers
		$original = $imap->fetchMessage($uid, false);

		$builder = new MessageBuilder();
		$builder->setFrom($config['username']);

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

		// Reply-all: add original To and CC (excluding our own address)
		if ($reply_all) {
			$myAddress = strtolower($config['username']);
			foreach ($this->parseAddresses($original->to) as $addr) {
				if (strtolower($addr) !== $myAddress) {
					$builder->addTo($addr);
				}
			}
			foreach ($this->parseAddresses($original->cc) as $addr) {
				if (strtolower($addr) !== $myAddress) {
					$builder->addCc($addr);
				}
			}
		}

		$builder->setTextBody($text);
		if (!empty($html)) {
			$builder->setHtmlBody($html);
		}

		$rawMessage = $builder->build();
		$recipients = $builder->getAllRecipients();

		$smtp->send($config['username'], $recipients, $rawMessage);

		return [
			'instance' => $instance ?: $this->manager->getDefault(),
			'sent' => true,
			'reply_to_uid' => $uid,
			'subject' => $subject,
			'recipients' => count($recipients),
			'reply_all' => $reply_all,
		];
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
			// Extract email from "Name <email>" format
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
