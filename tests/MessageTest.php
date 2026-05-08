<?php
/**
 * Tests for Mail\Message DTO
 */

use PHPUnit\Framework\TestCase;
use Mail\Message;
use Mail\Attachment;

class MessageTest extends TestCase
{
	public function testFlagChecks(): void
	{
		$msg = new Message();
		$msg->flags = ['\\Seen', '\\Flagged', '\\Answered'];

		$this->assertTrue($msg->isSeen());
		$this->assertTrue($msg->isFlagged());
		$this->assertTrue($msg->isAnswered());
	}

	public function testFlagChecksNegative(): void
	{
		$msg = new Message();
		$msg->flags = [];

		$this->assertFalse($msg->isSeen());
		$this->assertFalse($msg->isFlagged());
		$this->assertFalse($msg->isAnswered());
	}

	public function testToArrayBasic(): void
	{
		$msg = new Message();
		$msg->uid = 42;
		$msg->subject = 'Test Subject';
		$msg->from = 'sender@example.com';
		$msg->to = 'recipient@example.com';
		$msg->date = 'Mon, 01 Jan 2026 00:00:00 +0000';
		$msg->size = 1234;
		$msg->flags = ['\\Seen'];
		$msg->mailbox = 'INBOX';

		$arr = $msg->toArray();

		$this->assertEquals(42, $arr['uid']);
		$this->assertEquals('Test Subject', $arr['subject']);
		$this->assertEquals('sender@example.com', $arr['from']);
		$this->assertTrue($arr['is_read']);
		$this->assertFalse($arr['is_flagged']);
		$this->assertArrayNotHasKey('text_body', $arr);
	}

	public function testToArrayWithBody(): void
	{
		$msg = new Message();
		$msg->uid = 1;
		$msg->textBody = 'Hello';
		$msg->htmlBody = '<p>Hello</p>';

		$arr = $msg->toArray(true);

		$this->assertEquals('Hello', $arr['text_body']);
		$this->assertEquals('<p>Hello</p>', $arr['html_body']);
	}

	public function testToArrayWithAttachments(): void
	{
		$msg = new Message();
		$msg->uid = 1;
		$msg->attachments = [
			new Attachment(filename: 'doc.pdf', contentType: 'application/pdf', size: 5000, partNumber: '2'),
		];

		$arr = $msg->toArray();

		$this->assertEquals(1, $arr['attachment_count']);
		$this->assertEquals('doc.pdf', $arr['attachments'][0]['filename']);
	}

	public function testCcIncludedWhenPresent(): void
	{
		$msg = new Message();
		$msg->uid = 1;
		$msg->cc = 'cc@example.com';

		$arr = $msg->toArray();
		$this->assertArrayHasKey('cc', $arr);

		$msg2 = new Message();
		$msg2->uid = 2;
		$arr2 = $msg2->toArray();
		$this->assertArrayNotHasKey('cc', $arr2);
	}
}
