<?php
/**
 * Tests for SendTools — quoting and attribution logic.
 *
 * These tests exercise the private helper methods via reflection
 * since they need a live IMAP/SMTP connection to test through the
 * public tool methods.
 */

use PHPUnit\Framework\TestCase;
use Mail\InstanceManager;

class SendToolsTest extends TestCase
{
	private SendTools $tools;

	protected function setUp(): void
	{
		$manager = new InstanceManager([
			'test' => [
				'imap_host' => 'localhost',
				'smtp_host' => 'localhost',
				'username' => 'test@example.com',
				'password' => 'test',
			],
		], 'test');

		$this->tools = new SendTools($manager);
	}

	public function testBuildAttributionWithDisplayName(): void
	{
		$method = new ReflectionMethod(SendTools::class, 'buildAttribution');
		$method->setAccessible(true);

		$result = $method->invoke($this->tools, 'Sat, 9 May 2026 10:30:00 -0400', 'Daniel Morante <daniel@example.com>');
		$this->assertStringContainsString('Daniel Morante', $result);
		$this->assertStringContainsString('Sat, 9 May 2026', $result);
		$this->assertStringContainsString('wrote:', $result);
	}

	public function testBuildAttributionWithEmailOnly(): void
	{
		$method = new ReflectionMethod(SendTools::class, 'buildAttribution');
		$method->setAccessible(true);

		$result = $method->invoke($this->tools, 'Mon, 1 Jan 2026 00:00:00 +0000', 'sender@example.com');
		$this->assertStringContainsString('sender@example.com', $result);
		$this->assertStringContainsString('wrote:', $result);
	}

	public function testBuildAttributionWithQuotedName(): void
	{
		$method = new ReflectionMethod(SendTools::class, 'buildAttribution');
		$method->setAccessible(true);

		$result = $method->invoke($this->tools, 'Mon, 1 Jan 2026 00:00:00 +0000', '"John Doe" <john@example.com>');
		$this->assertStringContainsString('John Doe', $result);
		$this->assertStringNotContainsString('<john@example.com>', $result);
	}

	public function testQuoteTextBody(): void
	{
		$method = new ReflectionMethod(SendTools::class, 'quoteTextBody');
		$method->setAccessible(true);

		$body = "Hello World\nThis is a test\nThird line";
		$result = $method->invoke($this->tools, $body);

		$this->assertEquals("> Hello World\n> This is a test\n> Third line", $result);
	}

	public function testQuoteTextBodyWithWindowsLineEndings(): void
	{
		$method = new ReflectionMethod(SendTools::class, 'quoteTextBody');
		$method->setAccessible(true);

		$body = "Line one\r\nLine two\r\nLine three";
		$result = $method->invoke($this->tools, $body);

		$this->assertEquals("> Line one\n> Line two\n> Line three", $result);
	}

	public function testQuoteTextBodyEmptyString(): void
	{
		$method = new ReflectionMethod(SendTools::class, 'quoteTextBody');
		$method->setAccessible(true);

		$result = $method->invoke($this->tools, '');
		$this->assertEquals('> ', $result);
	}

	public function testQuoteTextBodySingleLine(): void
	{
		$method = new ReflectionMethod(SendTools::class, 'quoteTextBody');
		$method->setAccessible(true);

		$result = $method->invoke($this->tools, 'Just one line');
		$this->assertEquals('> Just one line', $result);
	}

	public function testParseAddressesSingle(): void
	{
		$method = new ReflectionMethod(SendTools::class, 'parseAddresses');
		$method->setAccessible(true);

		$result = $method->invoke($this->tools, 'user@example.com');
		$this->assertCount(1, $result);
		$this->assertEquals('user@example.com', $result[0]);
	}

	public function testParseAddressesMultiple(): void
	{
		$method = new ReflectionMethod(SendTools::class, 'parseAddresses');
		$method->setAccessible(true);

		$result = $method->invoke($this->tools, 'alice@example.com, bob@example.com');
		$this->assertCount(2, $result);
		$this->assertEquals('alice@example.com', $result[0]);
		$this->assertEquals('bob@example.com', $result[1]);
	}

	public function testParseAddressesEmpty(): void
	{
		$method = new ReflectionMethod(SendTools::class, 'parseAddresses');
		$method->setAccessible(true);

		$result = $method->invoke($this->tools, '');
		$this->assertCount(0, $result);
	}

	public function testParseAddressesWithDisplayNames(): void
	{
		$method = new ReflectionMethod(SendTools::class, 'parseAddresses');
		$method->setAccessible(true);

		$result = $method->invoke($this->tools, 'Alice <alice@example.com>, "Bob Smith" <bob@example.com>');
		$this->assertCount(2, $result);
	}
}
