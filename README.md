# Mail MCP Server

A Model Context Protocol server for IMAP/SMTP email operations with AI assistants. Built with PHP and the Enchilada Framework. Zero external dependencies beyond PHP 8.4 with `openssl` and `curl` extensions.

## Features

- **IMAP Operations** — Search, read, and manage emails across mailboxes
- **SMTP Support** — Send and reply to emails with text/HTML content and attachments
- **Multi-Account** — Manage multiple mail accounts, switchable at runtime
- **OAuth/XOAUTH2** — Gmail, Microsoft 365, and Yahoo support via XOAUTH2 authentication
- **Pure PHP Sockets** — No ext-imap dependency. Uses native PHP socket IMAP/SMTP clients
- **PHAR Deployment** — Single-file deployment via `mail-mcp.phar`
- **AI-Friendly** — Unified search with flexible filters, clear tool descriptions

## Quick Start

### From Source

```sh
git clone https://pacyworld.dev/pacyworld/mail-mcp.git
cd mail-mcp
cp config/instances.json.sample config/instances.json
# Edit config/instances.json with your mail server details
php bin/mail-mcp
```

### PHAR (Recommended)

```sh
curl -LO https://pacyworld.dev/pacyworld/mail-mcp/releases/latest/download/mail-mcp.phar
chmod +x mail-mcp.phar
sudo mv mail-mcp.phar /usr/local/bin/mail-mcp
```

## Configuration

Create `~/.config/mail-mcp/instances.json`:

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
            "password": "your-password",
            "tls": true
        }
    }
}
```

### Windsurf / Claude Code

Add to your MCP configuration:

```json
{
    "mcpServers": {
        "mail": {
            "command": "php",
            "args": ["/usr/local/bin/mail-mcp"],
            "env": {
                "MAIL_MCP_CONFIG": "/home/YOUR_USER/.config/mail-mcp/instances.json"
            }
        }
    }
}
```

## Available Tools

| Tool | Description |
|------|-------------|
| `mail_connect` | Connect to IMAP + SMTP for a mail account |
| `mail_disconnect` | Disconnect from mail servers |
| `mail_connection_status` | Show connection state |
| `mail_list_mailboxes` | List available folders |
| `mail_open_mailbox` | Select a folder |
| `mail_search` | Search with flexible filters (from, to, subject, body, date, flags) |
| `mail_get_message` | Fetch single message with full content |
| `mail_get_messages` | Fetch multiple messages (headers only) |
| `mail_mark_read` | Mark message(s) as read |
| `mail_mark_unread` | Mark message(s) as unread |
| `mail_delete_message` | Delete a message |
| `mail_send` | Send a new email |
| `mail_reply` | Reply to an existing email |
| `mail_get_attachments` | List attachment metadata |
| `mail_save_attachment` | Save attachment to local file |
| `mail_list_instances` | List configured accounts |
| `mail_switch_instance` | Change default account |

## License

BSD 2-Clause License — see [LICENSE](LICENSE) file.
