<?php

namespace EnchiladaMCP;

/* Enchilada Framework 3.0
 * MCP File Logger
 *
 * Minimal, failure-proof logger for MCP servers. Stdio transports own
 * stdout (protocol channel) and stderr is often captured and discarded
 * by MCP hosts, so durable diagnostics require an opt-in log file.
 *
 * The logger never throws: any write failure is silently ignored so a
 * logging problem can never break the JSON-RPC protocol stream.
 *
 * Instances are callable (via __invoke) so a Logger can be passed
 * directly to StdioTransport::setLogger(), McpServer::setLogger(), etc.
 *
 * Usage:
 *   $logger = Logger::fromEnv('FORGEJO_MCP'); // FORGEJO_MCP_LOG, FORGEJO_MCP_LOG_LEVEL, FORGEJO_MCP_LOG_STDERR
 *   $logger->info('Server started');
 *   $transport->setLogger($logger);
 *
 * Software License Agreement (BSD License)
 *
 * Copyright (c) 2026, The Daniel Morante Company, Inc.
 * All rights reserved.
 */

class Logger
{
	public const LEVEL_DEBUG = 0;
	public const LEVEL_INFO  = 1;
	public const LEVEL_ERROR = 2;

	private const LEVEL_NAMES = [
		self::LEVEL_DEBUG => 'DEBUG',
		self::LEVEL_INFO  => 'INFO',
		self::LEVEL_ERROR => 'ERROR',
	];

	/** @var string|null Log file path (null = file logging disabled) */
	private ?string $path;

	/** @var int Minimum level written to the file */
	private int $level;

	/** @var bool Mirror log lines to STDERR */
	private bool $mirrorStderr;

	/** @var string Tag prepended to each line (e.g. "forgejo-mcp") */
	private string $tag;

	/**
	 * Create a new logger.
	 *
	 * @param string|null $path         Log file path, or null to disable file output
	 * @param int         $level        Minimum level (LEVEL_DEBUG, LEVEL_INFO, LEVEL_ERROR)
	 * @param bool        $mirrorStderr Also write every emitted line to STDERR
	 * @param string      $tag          Short identifier included in each line
	 */
	public function __construct(?string $path = null, int $level = self::LEVEL_INFO, bool $mirrorStderr = false, string $tag = 'mcp')
	{
		$this->path = ($path !== null && $path !== '') ? $path : null;
		$this->level = $level;
		$this->mirrorStderr = $mirrorStderr;
		$this->tag = $tag;
	}

	/**
	 * Build a logger from environment variables.
	 *
	 *   {PREFIX}_LOG         Log file path (enables file logging)
	 *   {PREFIX}_LOG_LEVEL   debug|info|error (default: debug when a log file is set)
	 *   {PREFIX}_LOG_STDERR  truthy value mirrors output to STDERR
	 *
	 * @param  string $prefix Environment variable prefix (e.g. "FORGEJO_MCP")
	 * @param  string $tag    Tag for log lines
	 * @return self
	 */
	public static function fromEnv(string $prefix, string $tag = 'mcp'): self
	{
		$path = getenv("{$prefix}_LOG") ?: null;

		$level = self::LEVEL_DEBUG;
		switch (strtolower((string)(getenv("{$prefix}_LOG_LEVEL") ?: ''))) {
			case 'info':  $level = self::LEVEL_INFO;  break;
			case 'error': $level = self::LEVEL_ERROR; break;
		}

		$stderr = (bool)getenv("{$prefix}_LOG_STDERR");

		return new self($path, $level, $stderr, $tag);
	}

	/**
	 * Parse a level name into a level constant.
	 *
	 * @param  string $name Level name (debug, info, error)
	 * @return int|null     Level constant, or null if unrecognized
	 */
	public static function levelFromString(string $name): ?int
	{
		return match (strtolower($name)) {
			'debug' => self::LEVEL_DEBUG,
			'info'  => self::LEVEL_INFO,
			'error' => self::LEVEL_ERROR,
			default => null,
		};
	}

	/**
	 * Whether any output sink is active.
	 *
	 * @return bool
	 */
	public function enabled(): bool
	{
		return $this->path !== null || $this->mirrorStderr;
	}

	/**
	 * Whether debug-level messages are emitted.
	 *
	 * @return bool
	 */
	public function isDebug(): bool
	{
		return $this->enabled() && $this->level <= self::LEVEL_DEBUG;
	}

	/**
	 * Log a debug message (verbose protocol/transport detail).
	 *
	 * @param string $message
	 */
	public function debug(string $message): void
	{
		$this->log(self::LEVEL_DEBUG, $message);
	}

	/**
	 * Log an informational message (lifecycle, request summaries).
	 *
	 * @param string $message
	 */
	public function info(string $message): void
	{
		$this->log(self::LEVEL_INFO, $message);
	}

	/**
	 * Log an error message.
	 *
	 * @param string $message
	 */
	public function error(string $message): void
	{
		$this->log(self::LEVEL_ERROR, $message);
	}

	/**
	 * Callable interface — messages are logged at debug level.
	 *
	 * Allows passing the Logger directly where a callable is expected
	 * (e.g. StdioTransport::setLogger($logger)).
	 *
	 * @param string $message
	 */
	public function __invoke(string $message): void
	{
		$this->debug($message);
	}

	/**
	 * Summarize a payload for safe logging: byte length and SHA-256 digest.
	 *
	 * The digest allows byte-exactness verification (e.g. large base64
	 * secrets) without writing secret material to the log.
	 *
	 * @param  string $data Payload
	 * @return string       e.g. "len=10308 sha256=9f2c..."
	 */
	public static function digest(string $data): string
	{
		return 'len=' . strlen($data) . ' sha256=' . hash('sha256', $data);
	}

	/**
	 * Truncate a string for single-line log output.
	 *
	 * @param  string $text      Text to truncate
	 * @param  int    $maxLength Maximum characters kept (default 200)
	 * @return string
	 */
	public static function truncate(string $text, int $maxLength = 200): string
	{
		if (strlen($text) <= $maxLength) {
			return $text;
		}
		return substr($text, 0, $maxLength) . '...';
	}

	/**
	 * Emit a log line to all active sinks.
	 *
	 * @param int    $level   Level constant
	 * @param string $message Message text (newlines are flattened)
	 */
	private function log(int $level, string $message): void
	{
		if ($level < $this->level) {
			return;
		}

		$line = date('Y-m-d\TH:i:sP')
			. ' [' . $this->tag . ']'
			. ' [' . self::LEVEL_NAMES[$level] . '] '
			. str_replace(["\r", "\n"], ['\r', '\n'], $message)
			. "\n";

		if ($this->path !== null) {
			@file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);
		}

		if ($this->mirrorStderr) {
			@fwrite(STDERR, $line);
		}
	}
}
