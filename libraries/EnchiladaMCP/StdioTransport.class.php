<?php

namespace EnchiladaMCP;

/* Enchilada Framework 3.0
 * MCP Stdio Transport
 *
 * Non-blocking event loop for MCP servers communicating over stdin/stdout.
 * Uses stream_select() to multiplex stdin with additional stream sources
 * (e.g., OAuth callback server, WebSocket, timers).
 *
 * Usage:
 *   $server = new McpServer('my-server', '1.0.0');
 *   $transport = new StdioTransport($server);
 *   $transport->run();
 *
 * Software License Agreement (BSD License)
 * 
 * Copyright (c) 2026, The Daniel Morante Company, Inc.
 * All rights reserved.
 */

class StdioTransport
{
	/** @var McpServer */
	private McpServer $server;

	/** @var array<string,array{stream:resource,callback:callable}> */
	private array $additionalStreams = [];

	/** @var bool */
	private bool $running = true;

	/** @var callable|null */
	private $logger = null;

	/** @var int Select timeout in seconds */
	private int $selectTimeout = 1;

	/**
	 * Create a new stdio transport.
	 *
	 * @param McpServer $server Protocol handler
	 */
	public function __construct(McpServer $server)
	{
		$this->server = $server;
	}

	/**
	 * Set a logging callback.
	 *
	 * @param callable $logger Function accepting a string message
	 */
	public function setLogger(callable $logger): void
	{
		$this->logger = $logger;
	}

	/**
	 * Register an additional stream to monitor in the event loop.
	 *
	 * When the stream becomes readable, $callback is invoked.
	 *
	 * @param resource $stream   Stream resource (e.g., TCP socket)
	 * @param callable $callback Called when stream is readable: function($stream): void
	 */
	public function addStream($stream, callable $callback): void
	{
		$key = (string)(int)$stream;
		$this->additionalStreams[$key] = [
			'stream' => $stream,
			'callback' => $callback,
		];
	}

	/**
	 * Remove a previously registered stream.
	 *
	 * @param resource $stream Stream resource to remove
	 */
	public function removeStream($stream): void
	{
		$key = (string)(int)$stream;
		unset($this->additionalStreams[$key]);
	}

	/**
	 * Enter the main event loop.
	 *
	 * Reads JSON-RPC messages from stdin, dispatches to the server,
	 * writes responses to stdout. Also polls additional registered streams.
	 *
	 * Exits when stdin reaches EOF (client disconnected) or stop() is called.
	 */
	public function run(): void
	{
		stream_set_blocking(STDIN, false);
		$this->running = true;
		$buffer = '';

		$this->log("Transport started (stream_select loop)");

		while ($this->running) {
			// Build read array
			$read = [STDIN];
			foreach ($this->additionalStreams as $entry) {
				$read[] = $entry['stream'];
			}

			$write = $except = null;
			$changed = @stream_select($read, $write, $except, $this->selectTimeout);

			// Dispatch signals if available
			if (function_exists('pcntl_signal_dispatch')) {
				pcntl_signal_dispatch();
			}

			if ($changed === false) {
				// Select error — likely interrupted by signal
				continue;
			}

			if ($changed === 0) {
				// Timeout — loop again
				continue;
			}

			// Check stdin
			if (in_array(STDIN, $read, true)) {
				$chunk = fread(STDIN, 65536);
				if ($chunk === false || ($chunk === '' && feof(STDIN))) {
					// EOF — client disconnected
					$this->log("stdin EOF, stopping");
					break;
				}

				$buffer .= $chunk;

				// Process complete lines (JSON-RPC messages are newline-delimited)
				while (($pos = strpos($buffer, "\n")) !== false) {
					$line = substr($buffer, 0, $pos);
					$buffer = substr($buffer, $pos + 1);
					$line = trim($line);

					if (empty($line)) {
						continue;
					}

					$this->handleLine($line);
				}
			}

			// Check additional streams
			foreach ($this->additionalStreams as $key => $entry) {
				if (in_array($entry['stream'], $read, true)) {
					($entry['callback'])($entry['stream']);
				}
			}
		}

		$this->log("Transport stopped");
	}

	/**
	 * Send a JSON-RPC notification to the client (no id, no response expected).
	 *
	 * @param string              $method Notification method name
	 * @param array<string,mixed> $params Notification parameters
	 */
	public function sendNotification(string $method, array $params = []): void
	{
		$msg = ['jsonrpc' => '2.0', 'method' => $method];
		if (!empty($params)) {
			$msg['params'] = $params;
		}
		$output = json_encode($msg, JSON_UNESCAPED_SLASHES);
		$this->log("Notification: " . substr($output, 0, 200));
		fwrite(STDOUT, $output . "\n");
		fflush(STDOUT);
	}

	/**
	 * Send a log message notification to the client.
	 *
	 * @param string $level   Log level: debug, info, notice, warning, error, critical, alert, emergency
	 * @param string $message Log message text
	 * @param string $logger  Optional logger name
	 */
	public function sendLogMessage(string $level, string $message, string $logger = ''): void
	{
		$params = ['level' => $level, 'message' => $message];
		if (!empty($logger)) {
			$params['logger'] = $logger;
		}
		$this->sendNotification('notifications/message', $params);
	}

	/**
	 * Stop the event loop.
	 */
	public function stop(): void
	{
		$this->running = false;
	}

	/**
	 * Check if the transport is currently running.
	 *
	 * @return bool
	 */
	public function isRunning(): bool
	{
		return $this->running;
	}

	/**
	 * Process a single JSON-RPC line from stdin.
	 *
	 * @param string $line Raw JSON string
	 */
	private function handleLine(string $line): void
	{
		$this->log("Received: " . substr($line, 0, 200) . (strlen($line) > 200 ? '...' : ''));

		$request = json_decode($line, true);
		if ($request === null) {
			$this->log("Invalid JSON received");
			return;
		}

		$response = $this->server->handleRequest($request);

		if (!empty($response)) {
			$output = json_encode($response, JSON_UNESCAPED_SLASHES);
			$this->log("Sending: " . substr($output, 0, 200) . (strlen($output) > 200 ? '...' : ''));
			fwrite(STDOUT, $output . "\n");
			fflush(STDOUT);
		}
	}

	/**
	 * Log a message via the configured logger.
	 *
	 * @param string $message
	 */
	private function log(string $message): void
	{
		if ($this->logger) {
			($this->logger)($message);
		}
	}
}
