<?php
/**
 * Tests for Mail\Mailbox DTO
 */

use PHPUnit\Framework\TestCase;
use Mail\Mailbox;

class MailboxTest extends TestCase
{
	public function testConstructor(): void
	{
		$mbox = new Mailbox(
			name: 'INBOX',
			totalMessages: 42,
			recentMessages: 3,
			unseenMessages: 7,
			uidValidity: 12345,
			uidNext: 100,
			delimiter: '.',
			flags: ['\\HasNoChildren'],
		);

		$this->assertEquals('INBOX', $mbox->name);
		$this->assertEquals(42, $mbox->totalMessages);
		$this->assertEquals(3, $mbox->recentMessages);
		$this->assertEquals(7, $mbox->unseenMessages);
		$this->assertEquals(12345, $mbox->uidValidity);
		$this->assertEquals(100, $mbox->uidNext);
		$this->assertEquals('.', $mbox->delimiter);
		$this->assertEquals(['\\HasNoChildren'], $mbox->flags);
	}

	public function testToArray(): void
	{
		$mbox = new Mailbox(name: 'Sent', totalMessages: 10, delimiter: '/');
		$arr = $mbox->toArray();

		$this->assertEquals('Sent', $arr['name']);
		$this->assertEquals(10, $arr['total_messages']);
		$this->assertEquals('/', $arr['delimiter']);
		$this->assertArrayHasKey('uid_validity', $arr);
	}

	public function testDefaults(): void
	{
		$mbox = new Mailbox(name: 'Drafts');

		$this->assertEquals(0, $mbox->totalMessages);
		$this->assertEquals(0, $mbox->recentMessages);
		$this->assertEquals('/', $mbox->delimiter);
		$this->assertEquals([], $mbox->flags);
	}
}
