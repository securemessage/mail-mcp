<?php
/**
 * Tests that all tool classes load and register correctly with the MCP server.
 */

use PHPUnit\Framework\TestCase;
use EnchiladaMCP\McpServer;
use Mail\InstanceManager;

class ToolRegistrationTest extends TestCase
{
	private function makeServer(): McpServer
	{
		$manager = new InstanceManager([
			'test' => [
				'imap_host' => 'localhost',
				'smtp_host' => 'localhost',
				'username' => 'test',
				'password' => 'test',
			],
		], 'test');

		$server = new McpServer('mail-mcp', '0.1.0');

		$toolFiles = glob(APPLICATION_ROOT . 'tools/*.php');
		foreach ($toolFiles as $toolFile) {
			$className = basename($toolFile, '.php');
			if (class_exists($className)) {
				$server->register(new $className($manager));
			}
		}

		return $server;
	}

	public function testAllToolsRegister(): void
	{
		$server = $this->makeServer();
		$response = $server->handleRequest([
			'id' => 1,
			'method' => 'tools/list',
			'params' => [],
		]);

		$tools = $response['result']['tools'];
		$toolNames = array_column($tools, 'name');

		// Verify expected tools exist
		$expectedTools = [
			'mail_connect',
			'mail_disconnect',
			'mail_connection_status',
			'mail_list_mailboxes',
			'mail_open_mailbox',
			'mail_search',
			'mail_get_message',
			'mail_get_headers',
			'mail_get_messages',
			'mail_mark_read',
			'mail_mark_unread',
			'mail_delete_message',
			'mail_send',
			'mail_reply',
			'mail_get_attachments',
			'mail_save_attachment',
			'mail_list_instances',
			'mail_switch_instance',
			'mail_create_draft',
			'mail_move_message',
			'mail_set_flags',
			'mail_create_mailbox',
			'mail_get_thread',
		];

		foreach ($expectedTools as $expected) {
			$this->assertContains($expected, $toolNames, "Missing tool: {$expected}");
		}

		$this->assertCount(count($expectedTools), $tools, 'Unexpected tool count');
	}

	public function testInitializeResponse(): void
	{
		$server = $this->makeServer();
		$response = $server->handleRequest([
			'id' => 1,
			'method' => 'initialize',
			'params' => [],
		]);

		$this->assertEquals('2.0', $response['jsonrpc']);
		$this->assertEquals(1, $response['id']);
		$this->assertEquals('mail-mcp', $response['result']['serverInfo']['name']);
		$this->assertEquals('0.1.0', $response['result']['serverInfo']['version']);
		$this->assertArrayHasKey('tools', $response['result']['capabilities']);
	}

	public function testListInstancesTool(): void
	{
		$server = $this->makeServer();
		$response = $server->handleRequest([
			'id' => 1,
			'method' => 'tools/call',
			'params' => ['name' => 'mail_list_instances', 'arguments' => []],
		]);

		$result = json_decode($response['result']['content'][0]['text'], true);
		$this->assertEquals('test', $result['default']);
		$this->assertArrayHasKey('test', $result['instances']);
	}

	public function testSwitchInstanceTool(): void
	{
		$manager = new InstanceManager([
			'a' => ['imap_host' => 'a', 'username' => 'a', 'password' => 'a'],
			'b' => ['imap_host' => 'b', 'username' => 'b', 'password' => 'b'],
		], 'a');

		$server = new McpServer('mail-mcp', '0.1.0');
		$server->register(new InstanceTools($manager));

		$response = $server->handleRequest([
			'id' => 1,
			'method' => 'tools/call',
			'params' => ['name' => 'mail_switch_instance', 'arguments' => ['instance' => 'b']],
		]);

		$result = json_decode($response['result']['content'][0]['text'], true);
		$this->assertTrue($result['success']);
		$this->assertEquals('b', $result['default']);
	}

	public function testToolSchemaHasRequiredFields(): void
	{
		$server = $this->makeServer();
		$response = $server->handleRequest([
			'id' => 1,
			'method' => 'tools/list',
			'params' => [],
		]);

		foreach ($response['result']['tools'] as $tool) {
			$this->assertArrayHasKey('name', $tool);
			$this->assertArrayHasKey('description', $tool);
			$this->assertArrayHasKey('inputSchema', $tool);
			$this->assertNotEmpty($tool['description'], "Tool {$tool['name']} has empty description");
		}
	}
}
