# SecureMessage Mail MCP Server — Setup Guide

## Requirements

- **PHP 8.4+** with the following extensions:
  - `openssl` (TLS connections)
  - `curl` (OAuth token exchange)
  - `iconv` (character set conversion for email headers and body)
  - `ctype` (character type checking)
  - `fileinfo` (MIME type detection for attachments)
  - `phar` (only if running from PHAR)
- An IMAP/SMTP mail account (Gmail, Outlook, Fastmail, self-hosted, etc.)

No Composer, no Node.js, no external PHP libraries required.

## Installation

### PHAR (Recommended)

Download the latest release:

```sh
curl -LO https://pacyworld.dev/securemessage/mail-mcp/releases/download/v1.1.5/mail-mcp.phar
chmod +x mail-mcp.phar
sudo mv mail-mcp.phar /usr/local/bin/mail-mcp
```

### From Source

```sh
git clone https://pacyworld.dev/securemessage/mail-mcp.git
cd mail-mcp
cp config/instances.json.sample config/instances.json
# Edit config/instances.json
php bin/mail-mcp
```

## Configuration

### Config File Location

The server searches for `instances.json` in this order:

1. `MAIL_MCP_CONFIG` environment variable
2. `--config=/path/to/instances.json` CLI argument
3. `~/.config/mail-mcp/instances.json`
4. `/usr/local/etc/mail-mcp/instances.json`
5. `config/instances.json` (relative to source root / PHAR)

### Basic Auth (Password)

Most mail providers support app-specific passwords. Create one in your provider's security settings.

```sh
mkdir -p ~/.config/mail-mcp
cat > ~/.config/mail-mcp/instances.json << 'EOF'
{
    "default": "personal",
    "instances": {
        "personal": {
            "description": "Personal email",
            "imap_host": "imap.example.com",
            "imap_port": 993,
            "smtp_host": "smtp.example.com",
            "smtp_port": 465,
            "username": "user@example.com",
            "password": "your-app-password",
            "tls": true
        }
    }
}
EOF
```

### OAuth / XOAUTH2 (Gmail, Microsoft 365)

For providers that require OAuth (or where you prefer not to store passwords):

```json
{
    "default": "gmail",
    "instances": {
        "gmail": {
            "description": "Gmail via OAuth",
            "imap_host": "imap.gmail.com",
            "imap_port": 993,
            "smtp_host": "smtp.gmail.com",
            "smtp_port": 465,
            "auth_type": "xoauth2",
            "username": "user@gmail.com",
            "oauth_client_id": "xxxxx.apps.googleusercontent.com",
            "oauth_client_secret": "GOCSPX-xxxxx",
            "oauth_authorize_url": "https://accounts.google.com/o/oauth2/auth",
            "oauth_token_url": "https://oauth2.googleapis.com/token",
            "oauth_scopes": "https://mail.google.com/",
            "tls": true
        }
    }
}
```

For detailed OAuth setup (Google Workspace, Microsoft 365, pre-approved clients, troubleshooting), see [docs/OAUTH.md](OAUTH.md).

### Local Proxy (DavMail, stunnel)

For Exchange/OWA servers that don't expose IMAP directly:

```json
{
    "local-proxy": {
        "description": "Exchange via DavMail",
        "imap_host": "127.0.0.1",
        "imap_port": 1143,
        "smtp_host": "127.0.0.1",
        "smtp_port": 1025,
        "username": "user@company.com",
        "password": "secret",
        "tls": false,
        "starttls": false
    }
}
```

### Self-Signed Certificates

If your mail server uses a self-signed certificate:

```json
{
    "my-server": {
        "imap_host": "mail.internal.com",
        "imap_port": 993,
        "username": "user",
        "password": "pass",
        "from": "user@internal.com",
        "tls": true,
        "verify_ssl": false
    }
}
```

### Multi-Account

Add multiple entries under `instances`. All tools accept an optional `instance` parameter:

```json
{
    "default": "work",
    "instances": {
        "work": { ... },
        "personal": { ... },
        "support": { ... }
    }
}
```

Switch accounts at runtime with `mail_switch_instance` or pass `instance` to any tool.

## Configuration Reference

| Key | Type | Required | Default | Description |
|-----|------|----------|---------|-------------|
| `imap_host` | string | yes | — | IMAP server hostname |
| `imap_port` | int | no | 993 | IMAP port |
| `smtp_host` | string | no | — | SMTP server hostname |
| `smtp_port` | int | no | 465 | SMTP port |
| `username` | string | yes | — | Login username for IMAP/SMTP authentication |
| `password` | string | yes* | — | Password or app password (*not needed for OAuth) |
| `from` | string | no | `username` | RFC 5322 From address, e.g. `"Name <user@domain>"`. Required when username is not an email |
| `auth_type` | string | no | `plain` | `plain` or `xoauth2` |
| `tls` | bool | no | `true` | Use implicit TLS (port 993/465) |
| `starttls` | bool | no | `true` | Upgrade plaintext via STARTTLS |
| `smtp_tls` | bool | no | `true` | TLS for SMTP separately |
| `verify_ssl` | bool | no | `true` | Verify server certificate |
| `description` | string | no | — | Human-readable label |
| `oauth_client_id` | string | no | — | OAuth client ID |
| `oauth_client_secret` | string | no | — | OAuth client secret |
| `oauth_authorize_url` | string | no | — | OAuth authorization endpoint |
| `oauth_token_url` | string | no | — | OAuth token endpoint |
| `oauth_scopes` | string | no | — | OAuth scopes |

## IDE Integration

### Windsurf / Cascade

Add to `~/.codeium/windsurf/mcp_config.json`:

```json
{
    "mail": {
        "command": "php",
        "args": ["/usr/local/bin/mail-mcp"]
    }
}
```

With explicit config path:

```json
{
    "mail": {
        "command": "php",
        "args": ["/usr/local/bin/mail-mcp"],
        "env": {
            "MAIL_MCP_CONFIG": "/home/YOUR_USER/.config/mail-mcp/instances.json"
        }
    }
}
```

### Claude Code / Claude Desktop

Add to your Claude MCP configuration:

```json
{
    "mcpServers": {
        "mail": {
            "command": "php",
            "args": ["/usr/local/bin/mail-mcp"]
        }
    }
}
```

### Running from Source

```json
{
    "mail": {
        "command": "php",
        "args": ["/path/to/mail-mcp/bin/mail-mcp"]
    }
}
```

## Common Email Providers

| Provider | IMAP Host | IMAP Port | SMTP Host | SMTP Port | Auth |
|----------|-----------|-----------|-----------|-----------|------|
| Gmail | imap.gmail.com | 993 | smtp.gmail.com | 465 | App password or OAuth |
| Outlook/M365 | outlook.office365.com | 993 | smtp.office365.com | 587 | App password or OAuth |
| Yahoo | imap.mail.yahoo.com | 993 | smtp.mail.yahoo.com | 465 | App password |
| Fastmail | imap.fastmail.com | 993 | smtp.fastmail.com | 465 | App password |
| iCloud | imap.mail.me.com | 993 | smtp.mail.me.com | 587 | App password |
| ProtonMail | 127.0.0.1 | 1143 | 127.0.0.1 | 1025 | via ProtonMail Bridge |

## Troubleshooting

### Connection refused / timeout

- Verify hostname and port are correct
- Check if your firewall allows outbound IMAP (993) and SMTP (465/587)
- For local proxies (DavMail), ensure the proxy is running

### Authentication failed

- Gmail: generate an [App Password](https://myaccount.google.com/apppasswords) (requires 2FA enabled)
- Outlook: generate an app password or use OAuth
- Check that `username` is the full email address, or add a `from` key if your login is a bare username

### Self-signed certificate errors

Set `"verify_ssl": false` in the instance config.

### "Not connected" errors

Call `mail_connect` before performing operations. Each session requires an explicit connection.

### SMTP sends but message not in Sent folder

The server automatically saves sent messages to the Sent folder via IMAP APPEND. If this fails silently, check that IMAP is connected and the Sent folder exists.

## Security Notes

- Store `instances.json` with restricted permissions: `chmod 600 ~/.config/mail-mcp/instances.json`
- Use app passwords instead of your main account password
- OAuth tokens are stored at `~/.config/mail-mcp/tokens/` with `0600` permissions. Authorize once, tokens auto-refresh indefinitely
- The MCP transport is stdio (local process) — credentials never leave your machine
