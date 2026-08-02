---
name: mail-mcp
description: Install and configure the SecureMessage Mail MCP server for IMAP/SMTP email operations via AI assistants
---

# SecureMessage Mail MCP Server — Installation Skill

## Prerequisites

- PHP 8.4+ with `openssl`, `curl`, `iconv`, `ctype`, and `fileinfo` extensions
- An IMAP/SMTP mail account with credentials (password or app password)

Verify PHP:
```sh
php -v            # Must be 8.4+
php -m | grep -E 'openssl|curl|iconv|ctype|fileinfo'  # All five must be listed
```

## Install

```sh
curl -LO https://pacyworld.dev/securemessage/mail-mcp/releases/download/v1.1.6/mail-mcp.phar
chmod +x mail-mcp.phar
sudo mv mail-mcp.phar /usr/local/bin/mail-mcp
```

## Configure

Create the config file at `~/.config/mail-mcp/instances.json`:

```json
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
```

Common providers:
- **Gmail**: imap.gmail.com:993, smtp.gmail.com:465, use [App Password](https://myaccount.google.com/apppasswords)
- **Outlook/M365**: outlook.office365.com:993, smtp.office365.com:587
- **Fastmail**: imap.fastmail.com:993, smtp.fastmail.com:465

Multiple accounts: add more entries under `instances`.

## Add to Windsurf

Edit `~/.codeium/windsurf/mcp_config.json` and add:

```json
"mail": {
    "command": "php",
    "args": ["/usr/local/bin/mail-mcp"]
}
```

With explicit config path:

```json
"mail": {
    "command": "php",
    "args": ["/usr/local/bin/mail-mcp"],
    "env": {
        "MAIL_MCP_CONFIG": "/home/YOUR_USER/.config/mail-mcp/instances.json"
    }
}
```

## Add to Claude Code / Claude Desktop

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

## Verify

After restarting the IDE, the `mail_*` tools should be available. Test with:
- `mail_list_instances` — shows configured accounts
- `mail_connect` — establish IMAP/SMTP connections
- `mail_search` with `unread: true` — list unread messages

## Available Tool Categories

- **Connection**: connect, disconnect, status (3 tools)
- **Mailbox**: list, open, create (3 tools)
- **Search**: unified search with 12+ filter parameters (1 tool)
- **Messages**: get, get multiple, delete, thread view (4 tools)
- **Sending**: send, reply, create draft (3 tools)
- **Organization**: mark read/unread, set flags, move message (4 tools)
- **Attachments**: list metadata, save/download (2 tools)
- **Instances**: list accounts, switch default (2 tools)

## Multi-Account Usage

All tools accept an optional `instance` parameter:
```
mail_search instance="work" from="boss@company.com" unread=true
```

Switch default at runtime:
```
mail_switch_instance instance="personal"
```

## Source Repository

https://pacyworld.dev/securemessage/mail-mcp
