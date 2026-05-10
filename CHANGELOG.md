# Changelog

All notable changes to the SecureMessage Mail MCP Server are documented here.

## [1.0.1] - 2026-05-10

### Fixed
- Added `ctype` to PHP extension requirements in README, SETUP, and AGENT_SKILL (#6)
- Fixed PHAR download URLs in documentation (Forgejo `/releases/latest/download/` returns 404)

### Changed
- Rebranded to SecureMessage Mail MCP (APPLICATION_NAME, X-Mailer header, all docs and docblocks)
- APPLICATION_WEBSITE now points to https://www.securemessage.cc/mail-mcp

## [1.0.0] - 2026-05-09

### Added
- OAuth browser auto-open for VS Code / Windsurf Remote SSH (via `$BROWSER` env, `code --open-url`, `windsurf --open-url`)
- Comprehensive OAuth setup guide (`docs/OAUTH.md`) covering Gmail, Google Workspace, Microsoft 365, and Hotmail
- PKCE support for public OAuth clients (no client_secret required)
- Token persistence at `~/.config/mail-mcp/tokens/{instance}.json` with automatic refresh

### Fixed
- OAuth callback URL uses `localhost` instead of `127.0.0.1` (Microsoft requires exact match)
- Removed `/callback` path from redirect URI (Microsoft strict URI matching for public clients)
- `client_secret` now omitted from token exchange when empty (Microsoft public clients reject it)
- StdioTransport event loop: properly removes streams when callback returns `false` (prevented crash)
- StdioTransport event loop: wraps stream callbacks in try/catch for `\Throwable` (prevented crash)
- OAuth callback error handler catches `\Throwable` (not just `\Exception`)

### Changed
- OAuth token exchange and refresh are now compatible with both confidential and public OAuth clients
- Validated against: Gmail (personal), Google Workspace, Microsoft 365 (corporate)
- Documented limitation: Personal Hotmail/Outlook.com accounts require App Passwords (Microsoft platform restriction)

## [0.3.1] - 2026-05-08

### Fixed
- Attachment fetching for multipart messages (#5)
- Added `iconv` to required extensions list (#4)

## [0.3.0] - 2026-05-07

### Added
- Draft creation tool (`mail_create_draft`) — save drafts for review before sending
- Move messages between folders (`mail_move_message`)
- Thread retrieval (`mail_get_thread`) — fetch full conversation threads
- Flag management (`mail_set_flags`) — add/remove IMAP flags and keywords
- Create mailbox tool (`mail_create_mailbox`)
- Multi-mailbox search across all folders
- CC/BCC field support in search filters
- Keyword/label search support

### Changed
- Tool count increased from 16 to 22
- Search tool expanded with 12+ filter parameters

## [0.2.1] - 2026-05-06

### Fixed
- Simplified PHAR builder — stub requires entry point directly
- Absolute config paths resolve correctly from any working directory
- Removed unnecessary `chdir()` from entry point

## [0.2.0] - 2026-05-05

### Changed
- Vendored PHAR-compatible bootstrap from Enchilada Framework
- Simplified PHAR stub to use standard framework autoloader
- Version bump for PHAR deployment improvements

### Fixed
- APPLICATION_CONFDIR default uses absolute path (not CWD-relative)

## [0.1.2] - 2026-05-04

### Fixed
- Release workflow YAML syntax error preventing automated builds

## [0.1.1] - 2026-05-04

### Fixed
- PHAR autoloader: replaced framework autoloader with phar-aware path resolution
- Vendored updated autoloader with `APPLICATION_ROOT` support

## [0.1.0] - 2026-05-03

### Added
- Initial release
- Pure PHP socket IMAP client (IMAP4rev1, STARTTLS, LOGIN, XOAUTH2)
- Pure PHP socket SMTP client (EHLO, STARTTLS, AUTH LOGIN/PLAIN, XOAUTH2)
- 16 MCP tools: connect, disconnect, status, list mailboxes, open mailbox, search, get message(s), mark read/unread, delete, send, reply, get/save attachments, list/switch instances
- Multi-instance configuration via `instances.json`
- `verify_ssl` option per instance
- PHAR builder (`bin/build-phar.php`)
- PHPUnit test suite
- Forgejo CI/CD workflows (ci.yml + release.yml)
- Plaintext connection mode (`starttls` config option)
