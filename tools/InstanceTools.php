<?php
/**
 * Mail MCP Server — Instance Management Tools
 *
 * @package    MailMCP\Tools
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

use EnchiladaMCP\McpTool;
use Mail\InstanceManager;

class InstanceTools
{
	private InstanceManager $manager;

	public function __construct(InstanceManager $manager)
	{
		$this->manager = $manager;
	}

	/**
	 * List all configured mail accounts with connection status.
	 */
	#[McpTool(
		name: 'mail_list_instances',
		description: 'List all configured mail accounts. Shows connection status, auth type, and which is the default.'
	)]
	public function mail_list_instances(): array
	{
		return [
			'default' => $this->manager->getDefault(),
			'instances' => $this->manager->listInstances(),
		];
	}

	/**
	 * Switch the default mail account.
	 */
	#[McpTool(
		name: 'mail_switch_instance',
		description: 'Switch the active default mail account. All subsequent tool calls without an explicit instance parameter will use this account.',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'instance' => ['type' => 'string', 'description' => 'Name of the instance to set as default'],
			],
			'required' => ['instance'],
		]
	)]
	public function mail_switch_instance(string $instance): array
	{
		$this->manager->setDefault($instance);
		return [
			'success' => true,
			'default' => $instance,
		];
	}
}
