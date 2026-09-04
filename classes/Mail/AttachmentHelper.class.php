<?php
/**
 * SecureMessage Mail MCP Server — Attachment Helper
 *
 * Shared attachment handling for the send/reply/draft tools:
 * loading files from disk into a MessageBuilder and detecting
 * attachment mentions in message bodies that have no files
 * attached (#19).
 *
 * @package    MailMCP\Mail
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace Mail;

class AttachmentHelper
{
	/**
	 * Phrases that indicate the sender intends an attachment.
	 *
	 * Deliberately phrase-level (not bare "attached"/"attachment") to
	 * avoid false positives in ordinary prose. Same heuristic used by
	 * Thunderbird, Gmail, and Outlook.
	 */
	private const MENTION_PHRASES = [
		'attached is',
		'attached are',
		'please find attached',
		'see attached',
		'i\'ve attached',
		'i have attached',
		'i am attaching',
		'i\'m attaching',
		'find the attachment',
		'as attached',
		'attached hereto',
	];

	/**
	 * Attach files from disk to a message builder.
	 *
	 * @param  MessageBuilder $builder     Builder to receive attachments
	 * @param  string[]       $attachments Absolute file paths
	 * @return string[]                    Basenames of attached files
	 * @throws \RuntimeException           If a file is missing, unreadable, or fails to load
	 */
	public static function attachFiles(MessageBuilder $builder, array $attachments): array
	{
		$attachedFiles = [];
		foreach ($attachments as $filePath) {
			if (!file_exists($filePath)) {
				throw new \RuntimeException("Attachment file not found: {$filePath}");
			}
			if (!is_readable($filePath)) {
				throw new \RuntimeException("Attachment file not readable: {$filePath}");
			}
			$content = file_get_contents($filePath);
			if ($content === false) {
				throw new \RuntimeException("Failed to read attachment: {$filePath}");
			}
			$filename = basename($filePath);
			$mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
			$builder->addAttachment($filename, $content, $mimeType);
			$attachedFiles[] = $filename;
		}
		return $attachedFiles;
	}

	/**
	 * Detect an attachment mention in a message body.
	 *
	 * @param  string  $text Plain text body
	 * @param  string  $html HTML body (tags stripped before scanning)
	 * @return ?string       The matched phrase, or null if none found
	 */
	public static function findMention(string $text, string $html = ''): ?string
	{
		$body = $text;
		if ($html !== '') {
			$body .= "\n" . self::htmlToText($html);
		}

		$lower = strtolower($body);
		foreach (self::MENTION_PHRASES as $phrase) {
			if (str_contains($lower, $phrase)) {
				return $phrase;
			}
		}
		return null;
	}

	/**
	 * Reduce HTML to plain text for scanning.
	 */
	private static function htmlToText(string $html): string
	{
		// Paragraph/block boundaries become spaces so phrases
		// spanning tags ("see <b>attached</b>") still match.
		$html = preg_replace('/<\s*(br|\/p|\/div|\/li)\b[^>]*>/i', ' ', $html);
		return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}
}
