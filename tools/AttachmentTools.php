<?php
/**
 * SecureMessage Mail MCP Server — Attachment Tools
 *
 * @package    MailMCP\Tools
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

use EnchiladaMCP\McpTool;
use Mail\InstanceManager;

class AttachmentTools
{
	private InstanceManager $manager;

	public function __construct(InstanceManager $manager)
	{
		$this->manager = $manager;
	}

	/**
	 * List attachments for a message.
	 */
	#[McpTool(
		name: 'mail_get_attachments',
		description: 'List attachment metadata (filename, type, size) for a message. Does not download the attachment content.',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'uid' => ['type' => 'integer', 'description' => 'Message UID'],
				'instance' => ['type' => 'string', 'description' => 'Mail account name (optional, uses default)'],
			],
			'required' => ['uid'],
		]
	)]
	public function mail_get_attachments(int $uid, string $instance = ''): array
	{
		$client = $this->manager->getImapClient($instance ?: null);
		$message = $client->fetchMessage($uid, false);

		return [
			'instance' => $instance ?: $this->manager->getDefault(),
			'uid' => $uid,
			'attachment_count' => count($message->attachments),
			'attachments' => array_map(fn($a) => $a->toArray(), $message->attachments),
		];
	}

	/**
	 * Save an attachment to a local file or return its content as base64.
	 */
	#[McpTool(
		name: 'mail_save_attachment',
		description: 'Download an attachment. Save it to a local file path and/or return its content as base64. Use mail_get_attachments first to see available attachments and their part numbers.',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'uid' => ['type' => 'integer', 'description' => 'Message UID'],
				'part_number' => ['type' => 'string', 'description' => 'MIME part number (from mail_get_attachments)'],
				'save_path' => ['type' => 'string', 'description' => 'Absolute path where to save the file (optional if return_content is true)'],
				'return_content' => ['type' => 'boolean', 'description' => 'Return base64-encoded attachment content in response (default: false)'],
				'instance' => ['type' => 'string', 'description' => 'Mail account name (optional, uses default)'],
			],
			'required' => ['uid', 'part_number'],
		]
	)]
	public function mail_save_attachment(int $uid, string $part_number, string $save_path = '', bool $return_content = false, string $instance = ''): array
	{
		if (empty($save_path) && !$return_content) {
			return ['error' => 'Either save_path or return_content (or both) must be specified'];
		}

		$client = $this->manager->getImapClient($instance ?: null);
		$content = $client->fetchAttachment($uid, $part_number);

		$result = [
			'instance' => $instance ?: $this->manager->getDefault(),
			'size' => strlen($content),
		];

		if (!empty($save_path)) {
			$dir = dirname($save_path);
			if (!is_dir($dir)) {
				if (!mkdir($dir, 0755, true)) {
					return ['error' => "Cannot create directory: {$dir}"];
				}
			}

			$bytes = file_put_contents($save_path, $content);
			if ($bytes === false) {
				return ['error' => "Failed to write to: {$save_path}"];
			}

			$result['saved'] = true;
			$result['path'] = $save_path;
		}

		if ($return_content) {
			$result['content_base64'] = base64_encode($content);
		}

		return $result;
	}
}
