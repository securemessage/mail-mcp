<?php
/**
 * Mail MCP Server — SMTP Client Interface
 *
 * Abstraction layer for SMTP operations. Implementations may use
 * native PHP sockets, ext-imap's smtp support, or any other SMTP library.
 * Designed for future extraction to Enchilada Extras.
 *
 * @package    MailMCP\Mail
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace Mail;

interface SmtpClientInterface
{
	/**
	 * Connect to the SMTP server.
	 *
	 * @param string $host Hostname or IP
	 * @param int    $port Port number (465 for implicit TLS, 587 for STARTTLS)
	 * @param bool   $tls  Use implicit TLS (true) or STARTTLS (false)
	 * @throws \RuntimeException On connection failure
	 */
	public function connect(string $host, int $port, bool $tls = true): void;

	/**
	 * Authenticate with username and password (AUTH LOGIN or AUTH PLAIN).
	 *
	 * @param string $username
	 * @param string $password
	 * @throws \RuntimeException On authentication failure
	 */
	public function authenticate(string $username, string $password): void;

	/**
	 * Authenticate with OAuth2 access token (AUTH XOAUTH2).
	 *
	 * @param string $username    Email address
	 * @param string $accessToken OAuth2 access token
	 * @throws \RuntimeException On authentication failure
	 */
	public function authenticateXOAuth2(string $username, string $accessToken): void;

	/**
	 * Send an email.
	 *
	 * @param string   $from       Sender email address
	 * @param string[] $recipients Recipient email addresses (to + cc + bcc)
	 * @param string   $rawMessage Complete RFC 2822 message (headers + body)
	 * @throws \RuntimeException On send failure
	 */
	public function send(string $from, array $recipients, string $rawMessage): void;

	/**
	 * Disconnect from the server.
	 */
	public function disconnect(): void;

	/**
	 * Check if currently connected and authenticated.
	 *
	 * @return bool
	 */
	public function isConnected(): bool;
}
