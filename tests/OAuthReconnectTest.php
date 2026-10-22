<?php
/**
 * Regression tests for OAuth token handling during IMAP auto-reconnect.
 *
 * Background: Google access tokens live ~1 hour. A dropped IMAP session
 * that re-authenticates with the token captured at the last mail_connect
 * call fails with a misleading AUTHENTICATIONFAILED, which then invites a
 * destructive force_reauth. InstanceManager must instead consult the
 * configured token refresher (silent refresh) on every reconnect.
 *
 * Uses an in-process fake IMAP server (pcntl_fork + raw TCP listener)
 * that accepts two connections in sequence: the first answers one
 * AUTHENTICATE then drops mid-session; the second records the token used
 * for the reconnect. No TLS — these pin decision logic, not the handshake.
 */

use PHPUnit\Framework\TestCase;
use Mail\InstanceManager;
use Mail\SocketImapClient;

class OAuthReconnectTest extends TestCase
{
	/** @var array<int> PIDs of server children to reap */
	private static array $childPids = [];

	public static function tearDownAfterClass(): void
	{
		foreach (self::$childPids as $pid) {
			pcntl_waitpid($pid, $st);
		}
	}

	protected function setUp(): void
	{
		if (!function_exists('pcntl_fork')) {
			self::markTestSkipped('pcntl required');
		}
	}

	/**
	 * Spawn a fake IMAP server that handles two sequential connections.
	 *
	 * Connection 1: greeting, one AUTHENTICATE XOAUTH2 (token recorded),
	 * OK reply, then it reads one further command (the idle-probe NOOP)
	 * and closes the connection without answering (simulated peer drop).
	 *
	 * Connection 2: greeting, one AUTHENTICATE XOAUTH2 (token recorded),
	 * OK reply, then answers any remaining commands (NOOP/LOGOUT) with OK
	 * until the client goes away.
	 *
	 * @return array{host:string,port:int}
	 */
	private static function twoConnectionServer(string $logFile): array
	{
		$sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
		if ($sockets === false) {
			self::fail('could not create socket pair');
		}

		$pid = pcntl_fork();
		if ($pid === -1) {
			self::fail('pcntl_fork failed');
		}

		if ($pid === 0) {
			fclose($sockets[1]);
			self::runTwoConnectionServer($sockets[0], $logFile);
			exit(0);
		}

		self::$childPids[] = $pid;
		fclose($sockets[0]);
		stream_set_timeout($sockets[1], 5);
		$portLine = fgets($sockets[1]);
		fclose($sockets[1]);
		if ($portLine === false) {
			self::fail('fake server did not report a port');
		}

		return ['host' => '127.0.0.1', 'port' => (int) trim($portLine)];
	}

	private static function runTwoConnectionServer($portPipe, string $logFile): void
	{
		$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
		if ($server === false) {
			fwrite($portPipe, "0\n");
			exit(1);
		}
		$name = stream_socket_get_name($server, false);
		$port = (int) substr($name, strrpos($name, ':') + 1);
		fwrite($portPipe, "{$port}\n");
		fclose($portPipe);

		for ($c = 0; $c < 2; $c++) {
			$conn = stream_socket_accept($server, 10);
			if ($conn === false) {
				exit(1);
			}
			stream_set_timeout($conn, 10);
			fwrite($conn, "* OK Fake IMAP ready\r\n");

			// Expect AUTHENTICATE XOAUTH2 as the first command
			$line = fgets($conn);
			if ($line === false || !preg_match('/^(\S+) AUTHENTICATE XOAUTH2 (\S+)/i', trim($line), $m)) {
				exit(1);
			}
			$auth = base64_decode($m[2]);
			if (is_string($auth) && preg_match('/auth=Bearer ([^\x01]+)/', $auth, $tm)) {
				file_put_contents($logFile, $tm[1] . "\n", FILE_APPEND);
			}
			fwrite($conn, $m[1] . " OK authenticated\r\n");

			if ($c === 0) {
				// Idle-drop: consume the client's probe NOOP, then close
				// without answering it.
				fgets($conn);
				fclose($conn);
			} else {
				// Reconnected session: answer whatever follows until EOF.
				while (($line = fgets($conn)) !== false) {
					$tag = strtok(trim($line), ' ');
					if ($tag === false || $tag === '') {
						break;
					}
					fwrite($conn, $tag . " OK done\r\n");
				}
				fclose($conn);
			}
		}

		fclose($server);
		exit(0);
	}

	private function makeManager(array $addr): InstanceManager
	{
		return new InstanceManager([
			'test' => [
				'imap_host' => $addr['host'],
				'imap_port' => $addr['port'],
				'tls' => false,
				'starttls' => false,
				'timeout' => 3,
				'username' => 'user@example.com',
				'auth_type' => 'xoauth2',
			],
		], 'test');
	}

	/**
	 * A dead peer is only probed once the client has been idle past a
	 * threshold; age the connection so getImapClient() reconnects now.
	 */
	private function ageConnection(SocketImapClient $client): void
	{
		$prop = new \ReflectionProperty(SocketImapClient::class, 'lastActivity');
		$prop->setValue($client, microtime(true) - 3600);
	}

	public function testReconnectUsesFreshTokenFromRefresher(): void
	{
		$logFile = tempnam(sys_get_temp_dir(), 'imapauth-');
		$addr = self::twoConnectionServer($logFile);

		$manager = $this->makeManager($addr);

		$refresherCalls = [];
		$manager->setImapTokenRefresher(function (string $instance) use (&$refresherCalls) {
			$refresherCalls[] = $instance;
			return $instance === 'test' ? 'fresh-token' : null;
		});

		$manager->connectImap('test', 'stale-token');

		$client = $manager->getImapClient('test');
		$this->ageConnection($client);

		// Drop detected via NOOP probe -> reconnectImap() -> refresher consulted
		$client2 = $manager->getImapClient('test');

		$this->assertSame($client, $client2);
		$this->assertTrue($client2->isConnected());
		$this->assertSame(['test'], $refresherCalls);

		$tokens = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		$this->assertSame(['stale-token', 'fresh-token'], $tokens);

		$manager->disconnectAll();
		@unlink($logFile);
	}

	public function testReconnectFailsWithGuidanceWhenRefresherHasNoToken(): void
	{
		$logFile = tempnam(sys_get_temp_dir(), 'imapauth-');
		$addr = self::twoConnectionServer($logFile);

		$manager = $this->makeManager($addr);

		// Refresh grant is gone: refresher cannot produce a token
		$manager->setImapTokenRefresher(fn(string $instance): ?string => null);

		$manager->connectImap('test', 'stale-token');
		$this->ageConnection($manager->getImapClient('test'));

		try {
			$manager->getImapClient('test');
			$this->fail('Expected reconnect to fail when the refresher has no token');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('mail_connect', $e->getMessage());
		}

		// The stale token must NOT be sent on the reconnected socket — it
		// would only surface as a misleading AUTHENTICATIONFAILED.
		$tokens = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		$this->assertSame(['stale-token'], $tokens);

		$manager->disconnectAll();
		@unlink($logFile);
	}
}
