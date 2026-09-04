<?php
/**
 * Tests for SendTools — quoting and attribution logic.
 *
 * These tests exercise the private helper methods via reflection
 * since they need a live IMAP/SMTP connection to test through the
 * public tool methods.
 */

use PHPUnit\Framework\TestCase;
use EnchiladaMCP\McpTool;
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

	/**
	 * Regression test for issues #12/#13: comma inside quoted display name
	 * must not split the address into two malformed entries.
	 */
	public function testParseAddressesCommaInQuotedDisplayName(): void
	{
		$method = new ReflectionMethod(SendTools::class, 'parseAddresses');
		$method->setAccessible(true);

		$result = $method->invoke(
			$this->tools,
			'"WILLIAMS, MEGAN" <mewilliams@example.org>, alice@example.com'
		);
		$this->assertCount(2, $result);
		$this->assertEquals('mewilliams@example.org', $result[0]);
		$this->assertEquals('alice@example.com', $result[1]);
	}

	public function testParseAddressesMultipleCommasInQuotedNames(): void
	{
		$method = new ReflectionMethod(SendTools::class, 'parseAddresses');
		$method->setAccessible(true);

		$result = $method->invoke(
			$this->tools,
			'"Hailperin-Lausch, Rebecca" <rhlausch@example.org>, "Smith, John" <jsmith@example.com>'
		);
		$this->assertCount(2, $result);
		$this->assertEquals('rhlausch@example.org', $result[0]);
		$this->assertEquals('jsmith@example.com', $result[1]);
	}

	public function testMailReplyAcceptsNewParameters(): void
	{
		$method = new ReflectionMethod(SendTools::class, 'mail_reply');
		$params = $method->getParameters();
		$paramNames = array_map(fn($p) => $p->getName(), $params);

		$this->assertContains('cc', $paramNames, 'mail_reply should accept cc parameter');
		$this->assertContains('bcc', $paramNames, 'mail_reply should accept bcc parameter');
		$this->assertContains('draft', $paramNames, 'mail_reply should accept draft parameter');
		$this->assertContains('attachments', $paramNames, 'mail_reply should accept attachments parameter (#19)');
	}

	public function testMailReplyAttachmentsParameterHasDefault(): void
	{
		$method = new ReflectionMethod(SendTools::class, 'mail_reply');
		$params = [];
		foreach ($method->getParameters() as $p) {
			$params[$p->getName()] = $p;
		}

		$this->assertTrue($params['attachments']->isDefaultValueAvailable());
		$this->assertEquals([], $params['attachments']->getDefaultValue());
	}

	public function testMailReplySchemaIncludesAttachments(): void
	{
		$attr = (new ReflectionMethod(SendTools::class, 'mail_reply'))
			->getAttributes(McpTool::class)[0];
		$args = $attr->getArguments();
		$properties = $args['inputSchema']['properties'];

		$this->assertArrayHasKey('attachments', $properties, 'Schema should include attachments property (#19)');
		$this->assertEquals('array', $properties['attachments']['type']);
	}

	public function testMailSendAcceptsForceParameter(): void
	{
		$method = new ReflectionMethod(SendTools::class, 'mail_send');
		$params = [];
		foreach ($method->getParameters() as $p) {
			$params[$p->getName()] = $p;
		}

		$this->assertArrayHasKey('force', $params, 'mail_send should accept force parameter (#19)');
		$this->assertTrue($params['force']->isDefaultValueAvailable());
		$this->assertFalse($params['force']->getDefaultValue());
	}

	public function testMailSendSchemaIncludesForce(): void
	{
		$attr = (new ReflectionMethod(SendTools::class, 'mail_send'))
			->getAttributes(McpTool::class)[0];
		$args = $attr->getArguments();
		$properties = $args['inputSchema']['properties'];

		$this->assertArrayHasKey('force', $properties, 'Schema should include force property (#19)');
		$this->assertEquals('boolean', $properties['force']['type']);
	}

	public function testMailReplyNewParametersHaveDefaults(): void
	{
		$method = new ReflectionMethod(SendTools::class, 'mail_reply');
		$params = [];
		foreach ($method->getParameters() as $p) {
			$params[$p->getName()] = $p;
		}

		$this->assertTrue($params['cc']->isDefaultValueAvailable());
		$this->assertEquals('', $params['cc']->getDefaultValue());

		$this->assertTrue($params['bcc']->isDefaultValueAvailable());
		$this->assertEquals('', $params['bcc']->getDefaultValue());

		$this->assertTrue($params['draft']->isDefaultValueAvailable());
		$this->assertFalse($params['draft']->getDefaultValue());
	}

	public function testMailReplySchemaIncludesNewProperties(): void
	{
		$attr = (new ReflectionMethod(SendTools::class, 'mail_reply'))
			->getAttributes(McpTool::class)[0];
		$args = $attr->getArguments();
		$schema = $args['inputSchema'];
		$properties = $schema['properties'];

		$this->assertArrayHasKey('cc', $properties, 'Schema should include cc property');
		$this->assertArrayHasKey('bcc', $properties, 'Schema should include bcc property');
		$this->assertArrayHasKey('draft', $properties, 'Schema should include draft property');
		$this->assertEquals('boolean', $properties['draft']['type']);
	}

	public function testFindDraftsMailboxExists(): void
	{
		$method = new ReflectionMethod(SendTools::class, 'findDraftsMailbox');
		$this->assertTrue($method->isPrivate(), 'findDraftsMailbox should be a private method');
	}
}
