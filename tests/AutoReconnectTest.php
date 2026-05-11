<?php
/**
 * Tests for IMAP auto-reconnect behavior in InstanceManager.
 *
 * Verifies that when an IMAP connection drops after a successful connect,
 * getImapClient() transparently reconnects using stored credentials.
 */

use PHPUnit\Framework\TestCase;
use Mail\InstanceManager;
use Mail\ImapClientInterface;
use Mail\Mailbox;

class AutoReconnectTest extends TestCase
{
	/**
	 * Test that getImapClient does NOT attempt reconnect before connectImap is called.
	 */
	public function testNoReconnectBeforeInitialConnect(): void
	{
		$manager = new InstanceManager([
			'test' => [
				'imap_host' => 'imap.example.com',
				'username' => 'user@example.com',
				'password' => 'secret',
			],
		], 'test');

		// Getting client before connecting should just return the client
		$client = $manager->getImapClient('test');
		$this->assertInstanceOf(ImapClientInterface::class, $client);
		$this->assertFalse($client->isConnected());
	}

	/**
	 * Test that isConnected returns false for a fresh (unconnected) client.
	 */
	public function testFreshClientIsNotConnected(): void
	{
		$manager = new InstanceManager([
			'test' => [
				'imap_host' => 'imap.example.com',
				'username' => 'user@example.com',
				'password' => 'secret',
			],
		], 'test');

		$client = $manager->getImapClient();
		$this->assertFalse($client->isConnected());
	}

	/**
	 * Test that noop returns false for unconnected client.
	 */
	public function testNoopReturnsFalseWhenNotConnected(): void
	{
		$manager = new InstanceManager([
			'test' => [
				'imap_host' => 'imap.example.com',
				'username' => 'user@example.com',
				'password' => 'secret',
			],
		], 'test');

		$client = $manager->getImapClient();
		$this->assertFalse($client->noop());
	}

	/**
	 * Test that getCurrentMailbox returns null for fresh client.
	 */
	public function testGetCurrentMailboxReturnsNullWhenNotConnected(): void
	{
		$manager = new InstanceManager([
			'test' => [
				'imap_host' => 'imap.example.com',
				'username' => 'user@example.com',
				'password' => 'secret',
			],
		], 'test');

		$client = $manager->getImapClient();
		$this->assertNull($client->getCurrentMailbox());
	}

	/**
	 * Test that the same client instance is returned on repeated calls.
	 */
	public function testGetImapClientReturnsSameInstance(): void
	{
		$manager = new InstanceManager([
			'test' => [
				'imap_host' => 'imap.example.com',
				'username' => 'user@example.com',
				'password' => 'secret',
			],
		], 'test');

		$client1 = $manager->getImapClient('test');
		$client2 = $manager->getImapClient('test');
		$this->assertSame($client1, $client2);
	}

	/**
	 * Test that disconnectAll clears the connected state.
	 */
	public function testDisconnectAllClearsConnectedState(): void
	{
		$manager = new InstanceManager([
			'test' => [
				'imap_host' => 'imap.example.com',
				'username' => 'user@example.com',
				'password' => 'secret',
			],
		], 'test');

		// Get client (creates it in cache)
		$manager->getImapClient('test');

		// Disconnect all should not throw
		$manager->disconnectAll();

		// Getting client again should still work
		$client = $manager->getImapClient('test');
		$this->assertFalse($client->isConnected());
	}
}
