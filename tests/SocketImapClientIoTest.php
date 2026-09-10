<?php
/**
 * Functional I/O tests for SocketImapClient's event-driven read/write core.
 *
 * An in-process fake IMAP server (pcntl_fork + a raw tcp listener)
 * exercises the non-blocking buffered read path: complete lines, literal
 * continuation, split lines, and slow responses (progress emission).
 *
 * No TLS here — the handshake paths are covered by live functional runs
 * against the dev mail server; these tests pin the protocol engine.
 */

use PHPUnit\Framework\TestCase;
use Mail\SocketImapClient;

class SocketImapClientIoTest extends TestCase
{
	/** @var resource|null Fake server socket for the child-side reference cleanup */
	private static $serverSocket = null;

	/** @var array<int> PIDs of server children to reap */
	private static array $childPids = [];

	public static function tearDownAfterClass(): void
	{
		// Children exit by themselves when their parent closes the pipe.
		foreach (self::$childPids as $pid) {
			pcntl_waitpid($pid, $st);
		}
	}

	/**
	 * Spawn a fake IMAP server speaking the given script.
	 *
	 * The script is a list of steps; each step is:
	 *   ['expect' => 'LOGIN',      'send' => ["* OK pot", "@@ OK logged in"]]
	 *   ['expect' => 'STARTTLS',   'send' => [...], 'slow' => 0.5, 'split' => true]
	 * '@@' in the reply is replaced by the received command's tag.
	 * 'slow' delays the reply (seconds); 'split' writes the tag line in two chunks.
	 *
	 * @return array{host:string,port:int}
	 */
	private static function fakeServer(array $script): array
	{
		// Use a pipe pair so the child can report its bound port.
		$sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
		if ($sockets === false) {
			self::fail('could not create socket pair');
		}

		$pid = pcntl_fork();
		if ($pid === -1) {
			self::fail('pcntl_fork failed');
		}

		if ($pid === 0) {
			// Child: run the scripted server, then exit.
			fclose($sockets[1]);
			self::runFakeServer($sockets[0], $script);
			exit(0);
		}

		// Parent
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

	private static function runFakeServer($portPipe, array $script): void
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

		$conn = stream_socket_accept($server, 10);
		if ($conn === false) {
			exit(1);
		}

		fwrite($conn, "* OK Fake IMAP ready\r\n");

		foreach ($script as $step) {
			$line = fgets($conn);
			if ($line === false) {
				exit(1);
			}
			if (!preg_match('/^(\S+)\s+(.+)$/', trim($line), $m)) {
				exit(1);
			}
			[$full, $tag, $cmd] = $m;
			if (isset($step['expect']) && stripos($cmd, $step['expect']) !== 0) {
				exit(1);
			}

			if (!empty($step['literal'])) {
				// APPEND: acknowledge continuation, consume a line of body
				fwrite($conn, "+ Ready for literal\r\n");
				fgets($conn);
			}

			if (!empty($step['slow'])) {
				usleep((int) ($step['slow'] * 1000000));
			}

			foreach ($step['send'] as $i => $reply) {
				$reply = str_replace('@@', $tag, $reply) . "\r\n";
				if (!empty($step['split']) && $i === count($step['send']) - 1) {
					// Deliver the final line in two fragments
					$half = (int) (strlen($reply) / 2);
					fwrite($conn, substr($reply, 0, $half));
					usleep(100000);
					fwrite($conn, substr($reply, $half));
				} else {
					fwrite($conn, $reply);
				}
			}
		}

		fclose($conn);
		fclose($server);
		exit(0);
	}

	private function makeClient(int $timeout = 5): SocketImapClient
	{
		return new SocketImapClient($timeout, false);
	}

	public function testLoginSelectFetchFlow(): void
	{
		$addr = self::fakeServer([
			['expect' => 'LOGIN', 'send' => ['@@ OK Login completed']],
			['expect' => 'SELECT', 'send' => [
				'* 2 EXISTS', '* 0 RECENT', '* OK [UIDVALIDITY 1] UIDs valid',
				'* FLAGS (\Seen \Deleted)', '@@ OK [READ-WRITE] Select completed',
			]],
			['expect' => 'UID FETCH', 'send' => [
				'* 1 FETCH (UID 42 FLAGS (\Seen) INTERNALDATE "01-Jan-2026 00:00:00 +0000" RFC822.SIZE 25 BODY[HEADER] {12}' . "\r\n" . 'Header: v1' . "\r\n" . ')',
				'@@ OK Fetch completed',
			]],
			['expect' => 'LOGOUT', 'send' => ['@@ OK Logout completed']],
		]);

		$client = $this->makeClient();
		$client->connect($addr['host'], $addr['port'], false, false);
		$client->login('u', 'p');

		$mailbox = $client->selectMailbox('INBOX');
		$this->assertSame('INBOX', $mailbox->name);
		$this->assertSame(2, $mailbox->totalMessages);

		$headers = $client->fetchRawHeaders(42);
		$this->assertStringContainsString('Header: v1', $headers);

		$client->disconnect();
	}

	public function testSlowAndSplitResponses(): void
	{
		$progressCalls = 0;

		$addr = self::fakeServer([
			['expect' => 'LOGIN', 'send' => ['@@ OK Login completed'], 'slow' => 0.6, 'split' => true],
			['expect' => 'NOOP', 'send' => ['@@ OK noop'], 'slow' => 0.4],
			['expect' => 'LOGOUT', 'send' => ['@@ OK Logout completed']],
		]);

		$client = $this->makeClient();
		$client->setTransport(null, function () use (&$progressCalls) {
			$progressCalls++;
		});
		$client->connect($addr['host'], $addr['port'], false, false);

		$t0 = microtime(true);
		$client->login('u', 'p'); // 0.6s slow + 0.1s split on the server side
		$this->assertGreaterThan(0.6, microtime(true) - $t0);

		// Blocking regime: progress must have fired while waiting
		$this->assertGreaterThan(2, $progressCalls);

		$this->assertTrue($client->noop());
		$client->disconnect();
	}

	public function testAppendLiteral(): void
	{
		$addr = self::fakeServer([
			['expect' => 'LOGIN', 'send' => ['@@ OK Login completed']],
			['expect' => 'APPEND', 'literal' => true, 'send' => ['@@ OK Append completed']],
			['expect' => 'LOGOUT', 'send' => ['@@ OK Logout completed']],
		]);

		$client = $this->makeClient();
		$client->connect($addr['host'], $addr['port'], false, false);
		$client->login('u', 'p');
		$client->appendMessage('INBOX', "From: a\r\nSubject: t\r\n\r\nbody\r\n", ['\Draft']);
		$this->assertTrue($client->isConnected());
		$client->disconnect();
	}

	public function testPeerCloseDuringReadReportsLost(): void
	{
		// Server accepts, sends greeting, replies to LOGIN with nothing
		// and simply closes: client must see the EOF, not spin.
		$sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
		$pid = pcntl_fork();
		if ($pid === 0) {
			fclose($sockets[1]);
			$server = stream_socket_server('tcp://127.0.0.1:0', $e, $es);
			$name = stream_socket_get_name($server, false);
			fwrite($sockets[0], (int) substr($name, strrpos($name, ':') + 1) . "\n");
			$conn = stream_socket_accept($server, 10);
			fwrite($conn, "* OK Fake IMAP ready\r\n");
			fgets($conn);    // LOGIN line
			fclose($conn);   // drop without answering
			fclose($server);
			exit(0);
		}
		self::$childPids[] = $pid;
		fclose($sockets[0]);
		$port = (int) trim(fgets($sockets[1]));
		fclose($sockets[1]);

		$client = $this->makeClient(2);
		$client->connect('127.0.0.1', $port, false, false);
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('IMAP connection lost');
		$client->login('u', 'p');
	}
}
