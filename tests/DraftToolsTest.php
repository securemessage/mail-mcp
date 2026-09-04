<?php
/**
 * Tests for DraftTools — mail_update_draft signature and schema (issue #22).
 *
 * These tests exercise the tool definition via reflection since the
 * public tool methods need a live IMAP connection.
 */

use PHPUnit\Framework\TestCase;
use EnchiladaMCP\McpTool;
use Mail\InstanceManager;

class DraftToolsTest extends TestCase
{
	private DraftTools $tools;

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

		$this->tools = new DraftTools($manager);
	}

	public function testMailUpdateDraftExists(): void
	{
		$this->assertTrue(
			method_exists(DraftTools::class, 'mail_update_draft'),
			'DraftTools should expose mail_update_draft (#22)'
		);
	}

	public function testMailUpdateDraftParameters(): void
	{
		$method = new ReflectionMethod(DraftTools::class, 'mail_update_draft');
		$params = [];
		foreach ($method->getParameters() as $p) {
			$params[$p->getName()] = $p;
		}

		$this->assertArrayHasKey('uid', $params);
		$this->assertFalse($params['uid']->isDefaultValueAvailable(), 'uid must be required');

		foreach (['to', 'cc', 'bcc', 'subject', 'text', 'html'] as $optional) {
			$this->assertArrayHasKey($optional, $params, "Missing parameter: {$optional}");
			$this->assertTrue($params[$optional]->isDefaultValueAvailable());
			$this->assertEquals('', $params[$optional]->getDefaultValue());
		}

		foreach (['attachments', 'add_attachments'] as $optional) {
			$this->assertArrayHasKey($optional, $params, "Missing parameter: {$optional}");
			$this->assertTrue($params[$optional]->isDefaultValueAvailable());
			$this->assertEquals([], $params[$optional]->getDefaultValue());
		}
	}

	public function testMailUpdateDraftSchema(): void
	{
		$attr = (new ReflectionMethod(DraftTools::class, 'mail_update_draft'))
			->getAttributes(McpTool::class)[0];
		$args = $attr->getArguments();
		$schema = $args['inputSchema'];

		$this->assertEquals(['uid'], $schema['required'], 'Only uid should be required');

		foreach (['uid', 'to', 'cc', 'bcc', 'subject', 'text', 'html', 'attachments', 'add_attachments', 'instance'] as $prop) {
			$this->assertArrayHasKey($prop, $schema['properties'], "Schema missing property: {$prop}");
		}
	}

	public function testMailUpdateDraftRequiresConnection(): void
	{
		// Without mail_connect the tool must fail cleanly, not throw
		$result = $this->tools->mail_update_draft(1);
		$this->assertArrayHasKey('error', $result);
	}
}
