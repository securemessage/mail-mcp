<?php
/**
 * SecureMessage Mail MCP Server — Raw Header Block Parsing
 *
 * @package    MailMCP\Mail
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace Mail;

/**
 * Lossless parsing of an RFC 5322 header block.
 *
 * Deliberately preserves everything the display-oriented parsing in
 * SocketImapClient::applyHeaders() discards: line folding, the sender's choice
 * of field-name case, and repeated fields. Those are not cosmetic details for
 * every consumer -- a DKIM verifier hashes the folded octets, a Received chain
 * is a sequence rather than a value, and Authentication-Results appears once
 * per authenticating hop.
 */
class HeaderBlock
{
	/**
	 * Split a raw header block into whole fields.
	 *
	 * A field runs from the start of its name to the start of the next name,
	 * including every continuation line that begins with WSP. Splitting on
	 * newlines instead would cut folded fields into fragments and lose the
	 * leading whitespace that is part of the value.
	 *
	 * @param  string   $raw Raw header block
	 * @return string[]      Fields in order, folding intact, no trailing newline
	 */
	public static function split(string $raw): array
	{
		if ($raw === '') {
			return [];
		}

		$fields = [];
		foreach (preg_split('/\r?\n(?![ \t])/', $raw) as $field) {
			// A run of blank lines, or the block terminator, yields empty
			// pieces that are not fields.
			if (trim($field) === '') {
				continue;
			}
			$fields[] = $field;
		}

		return $fields;
	}

	/**
	 * The lowercased field name of a single raw field, or '' if malformed.
	 *
	 * Trailing whitespace before the colon is stripped: RFC 5322 does not permit
	 * it, but it is trimmed rather than treated as part of the name so that a
	 * sloppy sender's field is still findable by name.
	 *
	 * @param  string $field One field, as returned by split()
	 * @return string        Lowercased name, or '' when there is no colon
	 */
	public static function nameOf(string $field): string
	{
		$colon = strpos($field, ':');
		if ($colon === false) {
			return '';
		}

		return strtolower(rtrim(substr($field, 0, $colon)));
	}

	/**
	 * Every field whose name is in $names, in the order it appeared.
	 *
	 * @param  string   $raw   Raw header block
	 * @param  string[] $names Field names to keep (matched case-insensitively)
	 * @return string[]        Matching fields, folding intact
	 */
	public static function select(string $raw, array $names): array
	{
		$wanted = array_map('strtolower', $names);

		$matched = [];
		foreach (self::split($raw) as $field) {
			if (in_array(self::nameOf($field), $wanted, true)) {
				$matched[] = $field;
			}
		}

		return $matched;
	}
}
