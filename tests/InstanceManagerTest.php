<?php
/**
 * Tests for Mail\InstanceManager
 */

use PHPUnit\Framework\TestCase;
use Mail\InstanceManager;

class InstanceManagerTest extends TestCase
{
	private function makeManager(): InstanceManager
	{
		return new InstanceManager([
			'personal' => [
				'description' => 'Personal',
				'imap_host' => 'imap.example.com',
				'imap_port' => 993,
				'smtp_host' => 'smtp.example.com',
				'smtp_port' => 465,
				'username' => 'user@example.com',
				'password' => 'secret',
				'tls' => true,
			],
			'work' => [
				'description' => 'Work',
				'imap_host' => 'imap.work.com',
				'smtp_host' => 'smtp.work.com',
				'username' => 'worker@work.com',
				'password' => 'worksecret',
				'auth_type' => 'xoauth2',
				'oauth_client_id' => 'xxx',
			],
		], 'personal');
	}

	public function testConstructorWithValidConfig(): void
	{
		$manager = $this->makeManager();
		$this->assertEquals('personal', $manager->getDefault());
		$this->assertEquals(2, $manager->count());
	}

	public function testConstructorRejectsEmptyInstances(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		new InstanceManager([], 'none');
	}

	public function testConstructorRejectsInvalidDefault(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		new InstanceManager(['a' => ['username' => 'x']], 'nonexistent');
	}

	public function testGetConfig(): void
	{
		$manager = $this->makeManager();
		$config = $manager->getConfig('personal');

		$this->assertEquals('imap.example.com', $config['imap_host']);
		$this->assertEquals('user@example.com', $config['username']);
	}

	public function testGetConfigDefault(): void
	{
		$manager = $this->makeManager();
		$config = $manager->getConfig(null);

		$this->assertEquals('imap.example.com', $config['imap_host']);
	}

	public function testGetConfigInvalidInstance(): void
	{
		$manager = $this->makeManager();
		$this->expectException(\InvalidArgumentException::class);
		$manager->getConfig('nonexistent');
	}

	public function testSetDefault(): void
	{
		$manager = $this->makeManager();
		$manager->setDefault('work');
		$this->assertEquals('work', $manager->getDefault());
	}

	public function testSetDefaultInvalid(): void
	{
		$manager = $this->makeManager();
		$this->expectException(\InvalidArgumentException::class);
		$manager->setDefault('nonexistent');
	}

	public function testHasInstance(): void
	{
		$manager = $this->makeManager();
		$this->assertTrue($manager->hasInstance('personal'));
		$this->assertTrue($manager->hasInstance('work'));
		$this->assertFalse($manager->hasInstance('nonexistent'));
	}

	public function testIsOAuth(): void
	{
		$manager = $this->makeManager();
		$this->assertFalse($manager->isOAuth('personal'));
		$this->assertTrue($manager->isOAuth('work'));
	}

	public function testListInstances(): void
	{
		$manager = $this->makeManager();
		$list = $manager->listInstances();

		$this->assertArrayHasKey('personal', $list);
		$this->assertArrayHasKey('work', $list);
		$this->assertTrue($list['personal']['is_default']);
		$this->assertFalse($list['work']['is_default']);
		$this->assertEquals('xoauth2', $list['work']['auth_type']);
	}

	public function testFromFile(): void
	{
		$tmpFile = tempnam(sys_get_temp_dir(), 'mail_mcp_test_');
		file_put_contents($tmpFile, json_encode([
			'default' => 'test',
			'instances' => [
				'test' => [
					'imap_host' => 'imap.test.com',
					'username' => 'test@test.com',
					'password' => 'pass',
				],
			],
		]));

		$manager = InstanceManager::fromFile($tmpFile);
		$this->assertEquals('test', $manager->getDefault());
		$this->assertEquals(1, $manager->count());

		unlink($tmpFile);
	}

	public function testFromFileMissing(): void
	{
		$this->expectException(\RuntimeException::class);
		InstanceManager::fromFile('/nonexistent/path.json');
	}

	public function testFromFileInvalidJson(): void
	{
		$tmpFile = tempnam(sys_get_temp_dir(), 'mail_mcp_test_');
		file_put_contents($tmpFile, 'not json{{{');

		$this->expectException(\RuntimeException::class);
		InstanceManager::fromFile($tmpFile);
	}
}
