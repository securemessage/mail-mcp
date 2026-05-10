<?php
/**
 * SecureMessage Mail MCP Server — RFC 2822 Message Builder
 *
 * Constructs properly formatted email messages with support for
 * multipart content (text + HTML), attachments, and correct headers.
 *
 * @package    MailMCP\Mail
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace Mail;

class MessageBuilder
{
	private string $from = '';
	private array $to = [];
	private array $cc = [];
	private array $bcc = [];
	private string $subject = '';
	private ?string $textBody = null;
	private ?string $htmlBody = null;
	private string $inReplyTo = '';
	private string $references = '';
	private array $attachments = [];

	public function setFrom(string $from): self
	{
		$this->from = $from;
		return $this;
	}

	public function addTo(string $address): self
	{
		$this->to[] = $address;
		return $this;
	}

	public function addCc(string $address): self
	{
		$this->cc[] = $address;
		return $this;
	}

	public function addBcc(string $address): self
	{
		$this->bcc[] = $address;
		return $this;
	}

	public function setSubject(string $subject): self
	{
		$this->subject = $subject;
		return $this;
	}

	public function setTextBody(string $text): self
	{
		$this->textBody = $text;
		return $this;
	}

	public function setHtmlBody(string $html): self
	{
		$this->htmlBody = $html;
		return $this;
	}

	public function setInReplyTo(string $messageId): self
	{
		$this->inReplyTo = $messageId;
		return $this;
	}

	public function setReferences(string $references): self
	{
		$this->references = $references;
		return $this;
	}

	/**
	 * Add a file attachment.
	 *
	 * @param string $filename    Display filename
	 * @param string $content     Raw file content
	 * @param string $contentType MIME type
	 */
	public function addAttachment(string $filename, string $content, string $contentType = 'application/octet-stream'): self
	{
		$this->attachments[] = [
			'filename' => $filename,
			'content' => $content,
			'content_type' => $contentType,
		];
		return $this;
	}

	/**
	 * Build the complete RFC 2822 message.
	 *
	 * @return string Raw message ready for SMTP DATA
	 */
	public function build(): string
	{
		$headers = [];
		$headers[] = "From: {$this->from}";
		$headers[] = 'To: ' . implode(', ', $this->to);

		if (!empty($this->cc)) {
			$headers[] = 'Cc: ' . implode(', ', $this->cc);
		}

		$headers[] = 'Subject: ' . $this->encodeHeader($this->subject);
		$headers[] = 'Date: ' . date('r');
		$headers[] = 'Message-ID: <' . $this->generateMessageId() . '>';
		$headers[] = 'MIME-Version: 1.0';
		$headers[] = 'X-Mailer: ' . (defined('APPLICATION_USERAGENT') ? APPLICATION_USERAGENT : 'Mail-MCP/1.0');

		if (!empty($this->inReplyTo)) {
			$headers[] = "In-Reply-To: {$this->inReplyTo}";
		}
		if (!empty($this->references)) {
			$headers[] = "References: {$this->references}";
		}

		$body = $this->buildBody($headers);

		return implode("\r\n", $headers) . "\r\n\r\n" . $body;
	}

	/**
	 * Get all recipients (to + cc + bcc) for SMTP envelope.
	 *
	 * @return string[]
	 */
	public function getAllRecipients(): array
	{
		return array_merge($this->to, $this->cc, $this->bcc);
	}

	/**
	 * Build the message body (handles multipart).
	 *
	 * @param array &$headers Headers array (modified to add Content-Type)
	 * @return string
	 */
	private function buildBody(array &$headers): string
	{
		$hasAttachments = !empty($this->attachments);
		$hasAlternative = ($this->textBody !== null && $this->htmlBody !== null);

		if ($hasAttachments) {
			return $this->buildMixed($headers, $hasAlternative);
		} elseif ($hasAlternative) {
			return $this->buildAlternative($headers);
		} elseif ($this->htmlBody !== null) {
			$headers[] = 'Content-Type: text/html; charset=UTF-8';
			$headers[] = 'Content-Transfer-Encoding: quoted-printable';
			return quoted_printable_encode($this->htmlBody);
		} else {
			$headers[] = 'Content-Type: text/plain; charset=UTF-8';
			$headers[] = 'Content-Transfer-Encoding: quoted-printable';
			return quoted_printable_encode($this->textBody ?? '');
		}
	}

	/**
	 * Build multipart/alternative body (text + HTML).
	 */
	private function buildAlternative(array &$headers): string
	{
		$boundary = $this->generateBoundary('alt');
		$headers[] = "Content-Type: multipart/alternative; boundary=\"{$boundary}\"";

		$parts = [];

		$parts[] = "--{$boundary}";
		$parts[] = 'Content-Type: text/plain; charset=UTF-8';
		$parts[] = 'Content-Transfer-Encoding: quoted-printable';
		$parts[] = '';
		$parts[] = quoted_printable_encode($this->textBody ?? '');

		$parts[] = "--{$boundary}";
		$parts[] = 'Content-Type: text/html; charset=UTF-8';
		$parts[] = 'Content-Transfer-Encoding: quoted-printable';
		$parts[] = '';
		$parts[] = quoted_printable_encode($this->htmlBody ?? '');

		$parts[] = "--{$boundary}--";

		return implode("\r\n", $parts);
	}

	/**
	 * Build multipart/mixed body (content + attachments).
	 */
	private function buildMixed(array &$headers, bool $hasAlternative): string
	{
		$boundary = $this->generateBoundary('mix');
		$headers[] = "Content-Type: multipart/mixed; boundary=\"{$boundary}\"";

		$parts = [];

		// Content part
		$parts[] = "--{$boundary}";
		if ($hasAlternative) {
			$altBoundary = $this->generateBoundary('alt');
			$parts[] = "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"";
			$parts[] = '';

			$parts[] = "--{$altBoundary}";
			$parts[] = 'Content-Type: text/plain; charset=UTF-8';
			$parts[] = 'Content-Transfer-Encoding: quoted-printable';
			$parts[] = '';
			$parts[] = quoted_printable_encode($this->textBody ?? '');

			$parts[] = "--{$altBoundary}";
			$parts[] = 'Content-Type: text/html; charset=UTF-8';
			$parts[] = 'Content-Transfer-Encoding: quoted-printable';
			$parts[] = '';
			$parts[] = quoted_printable_encode($this->htmlBody ?? '');

			$parts[] = "--{$altBoundary}--";
		} elseif ($this->htmlBody !== null) {
			$parts[] = 'Content-Type: text/html; charset=UTF-8';
			$parts[] = 'Content-Transfer-Encoding: quoted-printable';
			$parts[] = '';
			$parts[] = quoted_printable_encode($this->htmlBody);
		} else {
			$parts[] = 'Content-Type: text/plain; charset=UTF-8';
			$parts[] = 'Content-Transfer-Encoding: quoted-printable';
			$parts[] = '';
			$parts[] = quoted_printable_encode($this->textBody ?? '');
		}

		// Attachment parts
		foreach ($this->attachments as $attachment) {
			$parts[] = "--{$boundary}";
			$parts[] = "Content-Type: {$attachment['content_type']}; name=\"{$attachment['filename']}\"";
			$parts[] = 'Content-Transfer-Encoding: base64';
			$parts[] = "Content-Disposition: attachment; filename=\"{$attachment['filename']}\"";
			$parts[] = '';
			$parts[] = chunk_split(base64_encode($attachment['content']), 76, "\r\n");
		}

		$parts[] = "--{$boundary}--";

		return implode("\r\n", $parts);
	}

	/**
	 * Encode a header value for RFC 2047 if it contains non-ASCII characters.
	 */
	private function encodeHeader(string $value): string
	{
		if (preg_match('/[^\x20-\x7E]/', $value)) {
			return '=?UTF-8?B?' . base64_encode($value) . '?=';
		}
		return $value;
	}

	/**
	 * Generate a unique message ID.
	 */
	private function generateMessageId(): string
	{
		$hostname = gethostname() ?: 'localhost';
		return sprintf('%s.%s@%s', bin2hex(random_bytes(8)), time(), $hostname);
	}

	/**
	 * Generate a unique MIME boundary.
	 */
	private function generateBoundary(string $prefix = 'part'): string
	{
		return "=_{$prefix}_" . bin2hex(random_bytes(16));
	}
}
