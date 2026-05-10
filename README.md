# Mail MCP Server

A PHP Model Context Protocol server for IMAP/SMTP email operations, built on the [Enchilada Framework](https://buenapp.org/enchilada).

Uses pure PHP socket IMAP/SMTP clients — no ext-imap, no Composer, no Node.js. Zero external dependencies beyond PHP 8.4 with `openssl`, `curl`, `iconv`, and `ctype`.

## Features

- **22 tools** — search, read, send, reply, drafts, attachments, threads, move, flags
- **Multi-account** — manage multiple mail accounts, switchable at runtime
- **Unified search** — single tool with 12+ filter parameters, multi-mailbox search across all folders
- **Drafts** — create drafts for review before sending
- **Organization** — move messages between folders, manage flags/labels
- **Threads** — retrieve full conversation threads
- **OAuth/XOAUTH2** — Gmail, Microsoft 365, Yahoo via XOAUTH2 authentication
- **PHAR deployment** — single-file distribution, no installation required
- Built on the [EnchiladaMCP](https://buenapp.org/docs/enchilada-mcp) library

## Requirements

- **PHP 8.4+** with `openssl`, `curl`, `iconv`, `ctype`, and `phar` extensions
- An IMAP/SMTP mail account (Gmail, Outlook, Fastmail, self-hosted, etc.)

## Quick Start (PHAR)

Download the latest PHAR from [Releases](https://pacyworld.dev/pacyworld/mail-mcp/releases):

```sh
curl -LO https://pacyworld.dev/pacyworld/mail-mcp/releases/download/v1.0.0/mail-mcp.phar
chmod +x mail-mcp.phar
```

Create a config file:

```sh
mkdir -p ~/.config/mail-mcp
cat > ~/.config/mail-mcp/instances.json << 'EOF'
{
    "default": "personal",
    "instances": {
        "personal": {
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

Start the server:

```sh
php mail-mcp.phar
```

For OAuth, multi-instance, and provider-specific settings, see [docs/SETUP.md](docs/SETUP.md).

### From Source

```sh
git clone https://pacyworld.dev/pacyworld/mail-mcp.git
cd mail-mcp
cp config/instances.json.sample config/instances.json
# Edit config/instances.json with your mail server details
php bin/mail-mcp
```

## AI Assistant Configuration

### Windsurf / Cascade

Add to `~/.codeium/windsurf/mcp_config.json`:

```json
{
    "mail": {
        "command": "php",
        "args": ["/path/to/mail-mcp.phar"]
    }
}
```

### Claude Code / Claude Desktop

```json
{
    "mcpServers": {
        "mail": {
            "command": "php",
            "args": ["/path/to/mail-mcp.phar"]
        }
    }
}
```

To use a config file in a non-default location:

```json
{
    "mail": {
        "command": "php",
        "args": ["/path/to/mail-mcp.phar"],
        "env": {
            "MAIL_MCP_CONFIG": "/path/to/instances.json"
        }
    }
}
```

## Available Tools

| Tool | Description |
|------|-------------|
| `mail_connect` | Connect to IMAP/SMTP for a mail account |
| `mail_disconnect` | Disconnect from mail servers |
| `mail_connection_status` | Show connection state for all accounts |
| `mail_list_mailboxes` | List available folders |
| `mail_open_mailbox` | Select a folder (returns message counts) |
| `mail_create_mailbox` | Create a new folder |
| `mail_search` | Search with flexible filters (from, to, cc, subject, body, date, flags, keywords) |
| `mail_get_message` | Fetch single message with full content |
| `mail_get_messages` | Fetch multiple messages (headers only) |
| `mail_get_thread` | Retrieve full conversation thread |
| `mail_delete_message` | Delete a message |
| `mail_send` | Send a new email (with file attachments) |
| `mail_reply` | Reply with quoted original message |
| `mail_create_draft` | Save a draft for review before sending |
| `mail_mark_read` | Mark message(s) as read |
| `mail_mark_unread` | Mark message(s) as unread |
| `mail_set_flags` | Add/remove IMAP flags and keywords |
| `mail_move_message` | Move messages between folders |
| `mail_get_attachments` | List attachment metadata |
| `mail_save_attachment` | Save attachment to file or return as base64 |
| `mail_list_instances` | List configured accounts |
| `mail_switch_instance` | Change default account |

All tools accept an optional `instance` parameter to target a specific mail account.

## Agent Skill

For AI-assisted setup, point your AI agent to the [setup skill](https://pacyworld.dev/pacyworld/mail-mcp/raw/branch/master/docs/AGENT_SKILL.md).

## Documentation

- [docs/SETUP.md](docs/SETUP.md) — Full setup guide (auth methods, providers, IDE integration, troubleshooting)
- [docs/OAUTH.md](docs/OAUTH.md) — OAuth setup for Gmail, Google Workspace, Microsoft 365
- [docs/TOOLS.md](docs/TOOLS.md) — Complete tool reference with all parameters
- [docs/AGENT_SKILL.md](docs/AGENT_SKILL.md) — Agent skill for AI-assisted installation

## Donations

If you find this project useful, consider a small donation:

| Currency | Address |
|---|---|
| **BTC** | `1B6eyXVRPxdEitW5vWrUnzzXUy6o38P9wN` |
| **LTC** | `MCrnhTAHA3n6X8jUJQj9hed5CT585sJExQ` |
| **PEPE (Ᵽ)** | `Pk3WZshXxi656RNNoVuZTCERVhhv4pyPJS` |
| **DOGE** | `DQgDGexy5tJ4StbMdyGwgfyxhcAGTRrPVB` |

## License

BSD 2-Clause — see [LICENSE](LICENSE).
