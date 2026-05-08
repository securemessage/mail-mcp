<?php
/**
 * Mail MCP Server — Connection Management Tools
 *
 * @package    MailMCP\Tools
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

use EnchiladaMCP\McpTool;
use Mail\InstanceManager;

class ConnectionTools
{
	private InstanceManager $manager;

	public function __construct(InstanceManager $manager)
	{
		$this->manager = $manager;
	}

	/**
	 * Connect to IMAP and SMTP servers for a mail account.
	 */
	#[McpTool(
		name: 'mail_connect',
		description: 'Connect to IMAP and SMTP servers for a mail account. For OAuth accounts, provide the access_token. For basic auth accounts, credentials are read from configuration.',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'instance' => ['type' => 'string', 'description' => 'Mail account name (optional, uses default)'],
				'imap' => ['type' => 'boolean', 'description' => 'Connect IMAP (default: true)'],
				'smtp' => ['type' => 'boolean', 'description' => 'Connect SMTP (default: true)'],
				'access_token' => ['type' => 'string', 'description' => 'OAuth2 access token (required for xoauth2 auth_type)'],
			],
		]
	)]
	public function mail_connect(string $instance = '', bool $imap = true, bool $smtp = true, string $access_token = ''): array
	{
		$name = $instance ?: null;
		$result = ['instance' => $instance ?: $this->manager->getDefault()];
		$token = !empty($access_token) ? $access_token : null;

		try {
			if ($imap) {
				$this->manager->connectImap($name, $token);
				$result['imap'] = 'connected';
			}
		} catch (\Throwable $e) {
			$result['imap'] = 'failed: ' . $e->getMessage();
		}

		try {
			if ($smtp) {
				$this->manager->connectSmtp($name, $token);
				$result['smtp'] = 'connected';
			}
		} catch (\Throwable $e) {
			$result['smtp'] = 'failed: ' . $e->getMessage();
		}

		if ($this->manager->isOAuth($name) && empty($access_token)) {
			$result['note'] = 'This is an OAuth account. Provide an access_token parameter or use the OAuth flow to authenticate.';
		}

		return $result;
	}

	/**
	 * Disconnect from mail servers.
	 */
	#[McpTool(
		name: 'mail_disconnect',
		description: 'Disconnect from IMAP and SMTP servers for a mail account, or all accounts.',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'instance' => ['type' => 'string', 'description' => 'Mail account name (optional, empty = disconnect all)'],
			],
		]
	)]
	public function mail_disconnect(string $instance = ''): array
	{
		if (empty($instance)) {
			$this->manager->disconnectAll();
			return ['disconnected' => 'all'];
		}

		$imap = $this->manager->getImapClient($instance ?: null);
		$smtp = $this->manager->getSmtpClient($instance ?: null);
		$imap->disconnect();
		$smtp->disconnect();

		return ['disconnected' => $instance ?: $this->manager->getDefault()];
	}

	/**
	 * Show connection status for all accounts.
	 */
	#[McpTool(
		name: 'mail_connection_status',
		description: 'Show IMAP and SMTP connection status for all configured mail accounts.'
	)]
	public function mail_connection_status(): array
	{
		return $this->manager->listInstances();
	}
}
