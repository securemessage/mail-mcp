<?php
/**
 * SecureMessage Mail MCP Server — Email Message Data Object
 *
 * @package    MailMCP\Mail
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace Mail;

class Message
{
	public int $uid = 0;
	public string $messageId = '';
	public string $subject = '';
	public string $from = '';
	public string $to = '';
	public string $cc = '';
	public string $bcc = '';
	public string $replyTo = '';
	public string $date = '';
	public int $size = 0;
	public array $flags = [];
	public string $mailbox = '';

	// Body (populated on full fetch only)
	public ?string $textBody = null;
	public ?string $htmlBody = null;

	/** @var Attachment[] */
	public array $attachments = [];

	// Headers (raw)
	public array $headers = [];

	public function isSeen(): bool
	{
		return in_array('\\Seen', $this->flags);
	}

	public function isFlagged(): bool
	{
		return in_array('\\Flagged', $this->flags);
	}

	public function isAnswered(): bool
	{
		return in_array('\\Answered', $this->flags);
	}

	public function toArray(bool $includeBody = false): array
	{
		$result = [
			'uid' => $this->uid,
			'message_id' => $this->messageId,
			'subject' => $this->subject,
			'from' => $this->from,
			'to' => $this->to,
			'date' => $this->date,
			'size' => $this->size,
			'flags' => $this->flags,
			'mailbox' => $this->mailbox,
			'is_read' => $this->isSeen(),
			'is_flagged' => $this->isFlagged(),
			'is_answered' => $this->isAnswered(),
		];

		if (!empty($this->cc)) {
			$result['cc'] = $this->cc;
		}
		if (!empty($this->replyTo)) {
			$result['reply_to'] = $this->replyTo;
		}
		if (!empty($this->attachments)) {
			$result['attachment_count'] = count($this->attachments);
			$result['attachments'] = array_map(fn(Attachment $a) => $a->toArray(), $this->attachments);
		}

		if ($includeBody) {
			$result['text_body'] = $this->textBody;
			$result['html_body'] = $this->htmlBody;
		}

		return $result;
	}
}
