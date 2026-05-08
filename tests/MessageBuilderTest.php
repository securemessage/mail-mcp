<?php
/**
 * Tests for Mail\MessageBuilder
 */

use PHPUnit\Framework\TestCase;
use Mail\MessageBuilder;

class MessageBuilderTest extends TestCase
{
	public function testSimpleTextMessage(): void
	{
		$builder = new MessageBuilder();
		$builder->setFrom('sender@example.com')
			->addTo('recipient@example.com')
			->setSubject('Test Subject')
			->setTextBody('Hello, World!');

		$raw = $builder->build();

		$this->assertStringContainsString('From: sender@example.com', $raw);
		$this->assertStringContainsString('To: recipient@example.com', $raw);
		$this->assertStringContainsString('Subject: Test Subject', $raw);
		$this->assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $raw);
		$this->assertStringContainsString('MIME-Version: 1.0', $raw);
		$this->assertStringContainsString('Hello, World!', $raw);
	}

	public function testHtmlMessage(): void
	{
		$builder = new MessageBuilder();
		$builder->setFrom('sender@example.com')
			->addTo('recipient@example.com')
			->setSubject('HTML Test')
			->setHtmlBody('<h1>Hello</h1>');

		$raw = $builder->build();

		$this->assertStringContainsString('Content-Type: text/html; charset=UTF-8', $raw);
		$this->assertStringContainsString('<h1>Hello</h1>', $raw);
	}

	public function testMultipartAlternative(): void
	{
		$builder = new MessageBuilder();
		$builder->setFrom('sender@example.com')
			->addTo('recipient@example.com')
			->setSubject('Multipart')
			->setTextBody('Plain text version')
			->setHtmlBody('<p>HTML version</p>');

		$raw = $builder->build();

		$this->assertStringContainsString('multipart/alternative', $raw);
		$this->assertStringContainsString('Plain text version', $raw);
		$this->assertStringContainsString('<p>HTML version</p>', $raw);
	}

	public function testMultipleRecipients(): void
	{
		$builder = new MessageBuilder();
		$builder->setFrom('sender@example.com')
			->addTo('alice@example.com')
			->addTo('bob@example.com')
			->addCc('carol@example.com')
			->addBcc('dave@example.com')
			->setSubject('Multi')
			->setTextBody('Hi all');

		$raw = $builder->build();
		$recipients = $builder->getAllRecipients();

		$this->assertStringContainsString('To: alice@example.com, bob@example.com', $raw);
		$this->assertStringContainsString('Cc: carol@example.com', $raw);
		$this->assertStringNotContainsString('Bcc:', $raw); // BCC must not appear in headers
		$this->assertCount(4, $recipients);
		$this->assertContains('dave@example.com', $recipients);
	}

	public function testReplyHeaders(): void
	{
		$builder = new MessageBuilder();
		$builder->setFrom('sender@example.com')
			->addTo('recipient@example.com')
			->setSubject('Re: Original')
			->setTextBody('Reply body')
			->setInReplyTo('<original-123@example.com>')
			->setReferences('<original-123@example.com>');

		$raw = $builder->build();

		$this->assertStringContainsString('In-Reply-To: <original-123@example.com>', $raw);
		$this->assertStringContainsString('References: <original-123@example.com>', $raw);
	}

	public function testAttachment(): void
	{
		$builder = new MessageBuilder();
		$builder->setFrom('sender@example.com')
			->addTo('recipient@example.com')
			->setSubject('With Attachment')
			->setTextBody('See attached.')
			->addAttachment('test.txt', 'File content here', 'text/plain');

		$raw = $builder->build();

		$this->assertStringContainsString('multipart/mixed', $raw);
		$this->assertStringContainsString('Content-Disposition: attachment; filename="test.txt"', $raw);
		$this->assertStringContainsString(base64_encode('File content here'), $raw);
	}

	public function testNonAsciiSubjectEncoding(): void
	{
		$builder = new MessageBuilder();
		$builder->setFrom('sender@example.com')
			->addTo('recipient@example.com')
			->setSubject('Héllo Wörld 🚀')
			->setTextBody('body');

		$raw = $builder->build();

		$this->assertStringContainsString('=?UTF-8?B?', $raw);
	}

	public function testMessageIdGenerated(): void
	{
		$builder = new MessageBuilder();
		$builder->setFrom('sender@example.com')
			->addTo('recipient@example.com')
			->setSubject('Test')
			->setTextBody('body');

		$raw = $builder->build();

		$this->assertMatchesRegularExpression('/Message-ID: <[a-f0-9]+\.\d+@/', $raw);
	}

	public function testDateHeaderPresent(): void
	{
		$builder = new MessageBuilder();
		$builder->setFrom('sender@example.com')
			->addTo('recipient@example.com')
			->setSubject('Test')
			->setTextBody('body');

		$raw = $builder->build();

		$this->assertMatchesRegularExpression('/Date: .+\d{4}/', $raw);
	}
}
