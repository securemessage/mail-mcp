<?php
/**
 * SecureMessage Mail MCP Server — IMAP Client Interface
 *
 * Abstraction layer for IMAP operations. Implementations may use
 * native PHP sockets, ext-imap (PECL), Horde_Imap_Client, or any
 * other IMAP library. Designed for future extraction to Enchilada Extras.
 *
 * @package    MailMCP\Mail
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace Mail;

interface ImapClientInterface
{
	/**
	 * Connect to the IMAP server.
	 *
	 * @param string $host     Hostname or IP
	 * @param int    $port     Port number (993 for implicit TLS, 143 for STARTTLS)
	 * @param bool   $tls      Use implicit TLS (true) or STARTTLS (false)
	 * @param bool   $starttls Attempt STARTTLS when not using implicit TLS (false = plaintext only)
	 * @throws \RuntimeException On connection failure
	 */
	public function connect(string $host, int $port, bool $tls = true, bool $starttls = true): void;

	/**
	 * Authenticate with username and password (LOGIN command).
	 *
	 * @param string $username
	 * @param string $password
	 * @throws \RuntimeException On authentication failure
	 */
	public function login(string $username, string $password): void;

	/**
	 * Authenticate with OAuth2 access token (AUTHENTICATE XOAUTH2).
	 *
	 * @param string $username    Email address
	 * @param string $accessToken OAuth2 access token
	 * @throws \RuntimeException On authentication failure
	 */
	public function authenticateXOAuth2(string $username, string $accessToken): void;

	/**
	 * List available mailboxes.
	 *
	 * @param  string $reference Reference name (usually empty string)
	 * @param  string $pattern   Mailbox pattern (usually "*")
	 * @return Mailbox[]         Array of mailbox info objects
	 */
	public function listMailboxes(string $reference = '', string $pattern = '*'): array;

	/**
	 * Select a mailbox for read/write access.
	 *
	 * @param  string  $name     Mailbox name (e.g., "INBOX")
	 * @param  bool    $readOnly Use EXAMINE instead of SELECT
	 * @return Mailbox            Mailbox info with message counts
	 * @throws \RuntimeException If mailbox does not exist
	 */
	public function selectMailbox(string $name, bool $readOnly = false): Mailbox;

	/**
	 * Search for messages matching criteria.
	 *
	 * @param  array $criteria IMAP search criteria (e.g., ['FROM' => 'user@example.com'])
	 * @return int[]           Array of matching message UIDs
	 */
	public function search(array $criteria = []): array;

	/**
	 * Fetch message headers for multiple UIDs.
	 *
	 * @param  int[] $uids     Message UIDs to fetch
	 * @return Message[]       Array of messages with headers populated
	 */
	public function fetchHeaders(array $uids): array;

	/**
	 * Fetch the complete raw header block of a message, exactly as transmitted.
	 *
	 * Unlike fetchHeaders(), nothing is decoded, unfolded, lowercased or
	 * deduplicated, and no field is filtered out. Callers that need to inspect
	 * a message as a signer or a relay saw it -- DKIM-Signature, Received,
	 * Authentication-Results -- require the original octets, because folding
	 * and repeated fields are significant to them.
	 *
	 * @param  int    $uid Message UID
	 * @return string      Raw header block, CRLF line endings, no trailing blank line
	 * @throws \RuntimeException If message does not exist
	 */
	public function fetchRawHeaders(int $uid): string;

	/**
	 * Fetch a complete message including body and attachment metadata.
	 *
	 * @param  int     $uid      Message UID
	 * @param  bool    $markSeen Mark as \Seen when fetching
	 * @return Message            Complete message object
	 * @throws \RuntimeException If message does not exist
	 */
	public function fetchMessage(int $uid, bool $markSeen = false): Message;

	/**
	 * Fetch raw attachment content by message UID and part number.
	 *
	 * @param  int    $uid        Message UID
	 * @param  string $partNumber MIME part number (e.g., "1.2")
	 * @return string             Raw decoded attachment content
	 */
	public function fetchAttachment(int $uid, string $partNumber): string;

	/**
	 * Set flags on a message.
	 *
	 * @param int      $uid   Message UID
	 * @param string[] $flags Flags to set (e.g., ['\Seen', '\Flagged'])
	 */
	public function addFlags(int $uid, array $flags): void;

	/**
	 * Remove flags from a message.
	 *
	 * @param int      $uid   Message UID
	 * @param string[] $flags Flags to remove
	 */
	public function removeFlags(int $uid, array $flags): void;

	/**
	 * Delete a message (sets \Deleted flag and expunges).
	 *
	 * @param int $uid Message UID
	 */
	public function deleteMessage(int $uid): void;

	/**
	 * Copy a message to another mailbox (IMAP UID COPY command).
	 *
	 * @param int    $uid            Message UID
	 * @param string $targetMailbox  Destination mailbox name
	 * @throws \RuntimeException On failure
	 */
	public function copyMessage(int $uid, string $targetMailbox): void;

	/**
	 * Create a new mailbox (IMAP CREATE command).
	 *
	 * @param string $name Mailbox name to create
	 * @throws \RuntimeException On failure
	 */
	public function createMailbox(string $name): void;

	/**
	 * Append a raw message to a mailbox (IMAP APPEND command).
	 *
	 * Used to save sent messages to the Sent folder.
	 *
	 * @param string   $mailbox    Target mailbox name (e.g., "Sent")
	 * @param string   $rawMessage Complete RFC 2822 message
	 * @param string[] $flags      Flags to set (e.g., ['\Seen'])
	 * @throws \RuntimeException On failure
	 */
	public function appendMessage(string $mailbox, string $rawMessage, array $flags = []): void;

	/**
	 * Send a NOOP command to verify the connection is alive.
	 *
	 * Useful as a keepalive or liveness check before operations.
	 *
	 * @return bool True if the server responded OK
	 */
	public function noop(): bool;

	/**
	 * Get the name of the currently selected mailbox.
	 *
	 * @return string|null Mailbox name, or null if none selected
	 */
	public function getCurrentMailbox(): ?string;

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
