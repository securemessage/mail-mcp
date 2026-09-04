<?php
/**
 * Tests for Mail\AttachmentHelper — disk attachment loading and
 * missing-attachment phrase detection (issue #19).
 */

use PHPUnit\Framework\TestCase;
use Mail\AttachmentHelper;
use Mail\MessageBuilder;

class AttachmentHelperTest extends TestCase
{
	public function testFindMentionMatchesKnownPhrases(): void
	{
		$bodies = [
			'Attached is our PGP public key.',
			'Please find attached the quarterly report.',
			'See attached for details.',
			"I've attached the file you requested.",
			'I have attached the invoice.',
			'You will find the attachment useful.',
			'As attached, the draft agreement.',
			'I am attaching the logs.',
		];

		foreach ($bodies as $body) {
			$this->assertNotNull(
				AttachmentHelper::findMention($body),
				"Expected attachment mention detected in: {$body}"
			);
		}
	}

	public function testFindMentionIsCaseInsensitive(): void
	{
		$this->assertNotNull(AttachmentHelper::findMention('ATTACHED IS the report.'));
		$this->assertNotNull(AttachmentHelper::findMention('SEE ATTACHED'));
		$this->assertNotNull(AttachmentHelper::findMention('Please Find Attached the File'));
	}

	public function testFindMentionReturnsNullWhenNoPhrase(): void
	{
		$this->assertNull(AttachmentHelper::findMention('Hello, how are you doing today?'));
		$this->assertNull(AttachmentHelper::findMention('Thanks for the update. Talk soon.'));
		$this->assertNull(AttachmentHelper::findMention(''));
	}

	/**
	 * Bare words must not trigger the heuristic — only established
	 * phrases. Prevents false positives on ordinary prose.
	 */
	public function testFindMentionDoesNotMatchBareWords(): void
	{
		$this->assertNull(AttachmentHelper::findMention('The cable is attached to the router.'));
		$this->assertNull(AttachmentHelper::findMention('There is no attachment between the parts.'));
	}

	public function testFindMentionScansHtmlBody(): void
	{
		$this->assertNotNull(
			AttachmentHelper::findMention('', '<p>See <b>attached</b> file.</p>')
		);
		$this->assertNull(
			AttachmentHelper::findMention('', '<p>Nothing to see here.</p>')
		);
	}

	public function testAttachFilesLoadsFromDisk(): void
	{
		$tmp = tempnam(sys_get_temp_dir(), 'mailmcp');
		file_put_contents($tmp, 'attachment payload');

		$builder = new MessageBuilder();
		$files = AttachmentHelper::attachFiles($builder, [$tmp]);

		$this->assertEquals([basename($tmp)], $files);

		$builder->setFrom('test@example.com');
		$builder->addTo('dest@example.com');
		$builder->setTextBody('body');
		$raw = $builder->build();
		$this->assertStringContainsString('multipart/mixed', $raw);
		$this->assertStringContainsString('filename="' . basename($tmp) . '"', $raw);

		unlink($tmp);
	}

	public function testAttachFilesThrowsForMissingFile(): void
	{
		$builder = new MessageBuilder();
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Attachment file not found');
		AttachmentHelper::attachFiles($builder, ['/nonexistent/path/to/file.txt']);
	}
}
