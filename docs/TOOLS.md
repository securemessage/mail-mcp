# SecureMessage Mail MCP Server — Tool Reference

22 tools organized into 8 categories. All tools accept an optional `instance` parameter to target a specific mail account.

## Connection Management

### mail_connect
Connect to IMAP and/or SMTP servers for a mail account.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `instance` | string | no | default | Mail account name |
| `imap` | boolean | no | true | Connect IMAP |
| `smtp` | boolean | no | true | Connect SMTP |
| `access_token` | string | no | — | OAuth2 access token (for xoauth2 auth) |

### mail_disconnect
Disconnect from mail servers. Pass an instance name to disconnect one account, or omit to disconnect all.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `instance` | string | no | — | Account to disconnect (empty = all) |

### mail_connection_status
Show IMAP and SMTP connection status for all configured accounts. No parameters.

## Mailbox Operations

### mail_list_mailboxes
List all available mailboxes (folders) on the mail server.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `instance` | string | no | default | Mail account name |

### mail_open_mailbox
Open a mailbox for reading. Returns message counts (total, recent, unseen).

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `mailbox` | string | no | INBOX | Mailbox name |
| `read_only` | boolean | no | false | Open in read-only mode |
| `instance` | string | no | default | Mail account name |

### mail_create_mailbox
Create a new mailbox (folder) on the server.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `name` | string | yes | — | New mailbox name |
| `instance` | string | no | default | Mail account name |

## Search

### mail_search
Search for emails using flexible filters. All filters are ANDed together. Returns message headers (not full body). Searches across all mailboxes by default.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `from` | string | no | — | Filter by sender |
| `to` | string | no | — | Filter by recipient |
| `cc` | string | no | — | Filter by CC recipient |
| `subject` | string | no | — | Filter by subject keywords |
| `body` | string | no | — | Filter by body text |
| `since` | string | no | — | Messages since date (e.g., "2026-01-01") |
| `before` | string | no | — | Messages before date |
| `unread` | boolean | no | false | Only unread messages |
| `answered` | boolean | no | false | Only replied-to messages |
| `unanswered` | boolean | no | false | Only unreplied messages |
| `flagged` | boolean | no | false | Only starred messages |
| `keyword` | string | no | — | IMAP keyword (user-defined flag) |
| `limit` | integer | no | 50 | Maximum results |
| `mailbox` | string | no | — | Search specific mailbox only |
| `instance` | string | no | default | Mail account name |

## Message Operations

### mail_get_message
Retrieve a specific message by UID with full content (text body, HTML body, attachments).

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `uid` | integer | yes | — | Message UID |
| `mark_read` | boolean | no | false | Mark as read when retrieving |
| `instance` | string | no | default | Mail account name |

### mail_get_headers
Retrieve the raw RFC 5322 header block of a message, exactly as transmitted. Nothing is decoded, unfolded, or deduplicated.

Use this rather than `mail_get_message` when the exact octets matter. `mail_get_message` unfolds continuation lines and keeps only the last instance of each field name, which is fine for display but destroys what these need:

- **`DKIM-Signature`** — folding and the exact `h=` list are part of what was signed
- **`Received`** — the chain is meaningless collapsed to one entry
- **`Authentication-Results`** — one per authenticating hop

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `uid` | integer | yes | — | Message UID |
| `names` | string[] | no | all | Header names to return (case-insensitive) |
| `instance` | string | no | default | Mail account name |

With `names` omitted, returns the whole block as `raw`. With `names` given, returns `headers` — every matching field, in the order it appeared, each including its continuation lines.

```json
{ "uid": 454, "names": ["DKIM-Signature", "Received"] }
```

### mail_get_messages
Retrieve multiple messages by UIDs. Returns headers only (not full body).

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `uids` | integer[] | yes | — | Array of message UIDs |
| `instance` | string | no | default | Mail account name |

### mail_delete_message
Delete a message (sets \Deleted flag and expunges).

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `uid` | integer | yes | — | Message UID to delete |
| `instance` | string | no | default | Mail account name |

### mail_get_thread
Retrieve a full conversation thread from any message UID within it.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `uid` | integer | yes | — | UID of any message in the thread |
| `instance` | string | no | default | Mail account name |

## Sending

### mail_send
Send a new email via SMTP. Supports file attachments. Saves to Sent folder automatically.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `to` | string | yes | — | Recipients, comma-separated |
| `subject` | string | yes | — | Email subject |
| `text` | string | no | — | Plain text body |
| `html` | string | no | — | HTML body |
| `cc` | string | no | — | CC recipients |
| `bcc` | string | no | — | BCC recipients |
| `attachments` | string[] | no | — | Absolute file paths to attach |
| `force` | boolean | no | false | Send even if the body mentions an attachment but none were included |
| `instance` | string | no | default | Mail account name |

If the body contains an attachment phrase (e.g. "see attached") but `attachments` is empty, `mail_send` refuses to send unless `force` is true.

### mail_reply
Reply to an existing email. Sets In-Reply-To/References headers and Re: subject prefix. Includes quoted original by default. Saves the reply as a draft by default — set `draft: false` to send immediately.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `uid` | integer | yes | — | UID of message to reply to |
| `text` | string | yes | — | Reply text body |
| `html` | string | no | — | Reply HTML body |
| `reply_all` | boolean | no | true | Reply to all recipients |
| `cc` | string | no | — | CC recipients (overrides original CC list) |
| `bcc` | string | no | — | BCC recipients |
| `draft` | boolean | no | true | Save as draft instead of sending |
| `include_original` | boolean | no | true | Include quoted original message |
| `attachments` | string[] | no | — | Absolute file paths to attach |
| `instance` | string | no | default | Mail account name |

If the reply body mentions an attachment but `attachments` is empty, the response includes a `warning` field (never blocks).

### mail_create_draft
Create a draft email for review before sending. Saved to Drafts folder.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `to` | string | yes | — | Recipients, comma-separated |
| `subject` | string | yes | — | Email subject |
| `text` | string | no | — | Plain text body |
| `html` | string | no | — | HTML body |
| `cc` | string | no | — | CC recipients |
| `bcc` | string | no | — | BCC recipients |
| `in_reply_to` | integer | no | — | UID of message this replies to |
| `attachments` | string[] | no | — | Absolute file paths to attach |
| `instance` | string | no | default | Mail account name |

If the body mentions an attachment but `attachments` is empty, the response includes a `warning` field (never blocks).

### mail_update_draft
Modify an existing draft in the Drafts folder. Only provided fields are replaced; omitted fields are preserved, including threading headers (In-Reply-To/References) and existing attachments. IMAP cannot edit messages in place, so the draft is replaced: a new message is appended and the original is deleted.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `uid` | integer | yes | — | UID of the draft message to update |
| `to` | string | no | — | Recipients, comma-separated (replaces existing To) |
| `subject` | string | no | — | Subject (replaces existing) |
| `text` | string | no | — | Plain text body (replaces existing) |
| `html` | string | no | — | HTML body (replaces existing) |
| `cc` | string | no | — | CC recipients (replaces existing) |
| `bcc` | string | no | — | BCC recipients (replaces existing) |
| `attachments` | string[] | no | — | Replaces ALL existing attachments |
| `add_attachments` | string[] | no | — | Appends attachments without replacing existing ones |
| `instance` | string | no | default | Mail account name |

If the resulting draft mentions an attachment but has none, the response includes a `warning` field (never blocks).

## Organization

### mail_mark_read
Mark one or more messages as read.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `uids` | integer[] | yes | — | Message UIDs |
| `instance` | string | no | default | Mail account name |

### mail_mark_unread
Mark one or more messages as unread.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `uids` | integer[] | yes | — | Message UIDs |
| `instance` | string | no | default | Mail account name |

### mail_set_flags
Add or remove IMAP flags on messages. Standard flags: `\Seen`, `\Flagged`, `\Answered`, `\Draft`, `\Deleted`. Also supports user-defined keywords.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `uids` | integer[] | yes | — | Message UIDs |
| `add_flags` | string[] | no | — | Flags to add |
| `remove_flags` | string[] | no | — | Flags to remove |
| `mailbox` | string | no | INBOX | Mailbox containing the messages |
| `instance` | string | no | default | Mail account name |

### mail_move_message
Move a message from one folder to another.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `uid` | integer | yes | — | Message UID |
| `target_folder` | string | yes | — | Destination folder |
| `source_folder` | string | no | INBOX | Source folder |
| `instance` | string | no | default | Mail account name |

## Attachments

### mail_get_attachments
List attachment metadata for a message (filename, type, size, part number).

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `uid` | integer | yes | — | Message UID |
| `instance` | string | no | default | Mail account name |

### mail_save_attachment
Download an attachment. Save to disk and/or return as base64.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `uid` | integer | yes | — | Message UID |
| `part_number` | string | yes | — | MIME part number |
| `save_path` | string | no | — | File path to save to |
| `return_content` | boolean | no | false | Return base64 content |
| `instance` | string | no | default | Mail account name |

At least one of `save_path` or `return_content` must be specified.

## Instance Management

### mail_list_instances
List all configured mail accounts with connection status.

### mail_switch_instance
Switch the active default mail account.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `instance` | string | yes | — | Account to set as default |
