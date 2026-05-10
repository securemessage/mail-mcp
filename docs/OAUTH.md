# SecureMessage Mail MCP — OAuth Setup Guide

This guide covers OAuth/XOAUTH2 authentication for Gmail, Google Workspace, and Microsoft 365.

## Overview

OAuth is the recommended authentication method for:
- **Corporate/Enterprise accounts** where admin policies require it
- **Google Workspace** accounts where "Less Secure Apps" is disabled
- **Microsoft 365** organizational accounts

For **personal accounts** (Gmail, Hotmail/Outlook.com), App Passwords are simpler and recommended for most users. See [App Passwords](#app-passwords-alternative) below.

## How It Works

1. First `mail_connect` call detects no cached token → returns an authorization URL
2. The IDE auto-opens the URL in your browser (or you click the link manually)
3. You sign in and grant permissions → browser redirects to a local callback server
4. Token is cached at `~/.config/mail-mcp/tokens/{instance}.json`
5. Subsequent `mail_connect` calls use the cached token silently (auto-refreshes)

Tokens persist across sessions. You only authorize once per account.

## Google (Gmail & Workspace)

### Option A: App Password (Simplest)

1. Enable 2-Step Verification: https://myaccount.google.com/signinoptions/two-step-verification
2. Generate App Password: https://myaccount.google.com/apppasswords
3. Use the generated 16-character password in your config:

```json
{
    "gmail": {
        "imap_host": "imap.gmail.com",
        "imap_port": 993,
        "smtp_host": "smtp.gmail.com",
        "smtp_port": 465,
        "username": "you@gmail.com",
        "password": "xxxx xxxx xxxx xxxx",
        "tls": true
    }
}
```

### Option B: OAuth (Own App Registration)

Recommended when you want full control or need to distribute the config.

#### 1. Create OAuth Credentials

1. Go to https://console.cloud.google.com/apis/credentials
2. Create a project (or select existing)
3. Click **Create Credentials** → **OAuth client ID**
4. Application type: **Desktop app**
5. Note the **Client ID** and **Client Secret**

#### 2. Configure the Instance

```json
{
    "gmail": {
        "imap_host": "imap.gmail.com",
        "imap_port": 993,
        "smtp_host": "smtp.gmail.com",
        "smtp_port": 465,
        "auth_type": "xoauth2",
        "username": "you@gmail.com",
        "oauth_client_id": "YOUR_CLIENT_ID.apps.googleusercontent.com",
        "oauth_client_secret": "GOCSPX-YOUR_SECRET",
        "oauth_authorize_url": "https://accounts.google.com/o/oauth2/auth",
        "oauth_token_url": "https://oauth2.googleapis.com/token",
        "oauth_scopes": "https://mail.google.com/",
        "tls": true
    }
}
```

#### 3. First Connection

Call `mail_connect`. A browser window opens, you sign in and grant access. Done.

> **Note:** Unverified apps show a warning screen ("This app isn't verified"). Click **Advanced** → **Go to [app name]**. For Workspace accounts where the admin blocks unverified apps, see Option C below.

### Option C: OAuth with Pre-Approved Client (Workspace)

If your Google Workspace admin blocks unverified third-party apps, you can use a well-known pre-approved client ID. Many organizations pre-approve major email clients like Thunderbird.

> **Tip:** Well-known client IDs for major email clients (Thunderbird, etc.) can be found on the [Mozilla wiki](https://wiki.mozilla.org/Thunderbird:Autoconfiguration:ConfigFileFormat#OAuth2) and similar public sources. Use any pre-approved client ID your organization trusts.

```json
{
    "workspace": {
        "imap_host": "imap.gmail.com",
        "imap_port": 993,
        "smtp_host": "smtp.gmail.com",
        "smtp_port": 465,
        "auth_type": "xoauth2",
        "username": "you@company.com",
        "oauth_client_id": "<pre-approved-client-id>",
        "oauth_client_secret": "<client-secret>",
        "oauth_authorize_url": "https://accounts.google.com/o/oauth2/auth",
        "oauth_token_url": "https://oauth2.googleapis.com/token",
        "oauth_scopes": "https://mail.google.com/",
        "tls": true
    }
}
```

## Microsoft 365 (Corporate/Organizational)

### Option A: App Password

If your organization allows it:
1. Go to https://mysignins.microsoft.com/security-info
2. Add method → App password
3. Use the generated password in your config with basic auth

### Option B: OAuth

Microsoft 365 organizational accounts support OAuth with Exchange Online scopes.

> **Tip:** Well-known public client IDs (e.g., Thunderbird's) can be found on the [Mozilla wiki](https://wiki.mozilla.org/Thunderbird:Autoconfiguration:ConfigFileFormat#OAuth2). Microsoft public clients use an empty `oauth_client_secret`.

#### Configuration

```json
{
    "work": {
        "imap_host": "outlook.office365.com",
        "imap_port": 993,
        "smtp_host": "smtp.office365.com",
        "smtp_port": 587,
        "auth_type": "xoauth2",
        "username": "you@company.com",
        "oauth_client_id": "<pre-approved-client-id>",
        "oauth_client_secret": "",
        "oauth_authorize_url": "https://login.microsoftonline.com/common/oauth2/v2.0/authorize",
        "oauth_token_url": "https://login.microsoftonline.com/common/oauth2/v2.0/token",
        "oauth_scopes": "https://outlook.office365.com/IMAP.AccessAsUser.All https://outlook.office365.com/SMTP.Send offline_access",
        "tls": true,
        "smtp_tls": false
    }
}
```

> **Note:** `smtp_tls: false` is required because Microsoft uses STARTTLS on port 587 (upgrade from plaintext), not implicit TLS.

#### Admin Consent

Some organizations require admin consent for third-party OAuth apps. If you see "Need admin approval":
- Ask your IT admin to grant consent for the client ID above, or
- Register your own app in your organization's Azure AD (see [Custom App Registration](#custom-microsoft-app-registration) below)

### Custom Microsoft App Registration

If you need your own OAuth app (e.g., for internal compliance):

1. Go to https://entra.microsoft.com → **App registrations** → **New registration**
2. Name: your choice
3. Supported account types: **Accounts in any organizational directory and personal Microsoft accounts**
4. Redirect URI: Platform = **Mobile and desktop applications**, URI = `http://localhost`
5. After creation:
   - Note the **Application (client) ID**
   - Go to **API permissions** → Add → **Office 365 Exchange Online** → Delegated:
     - `IMAP.AccessAsUser.All`
     - `SMTP.Send`
   - Also add **Microsoft Graph** → Delegated → `offline_access`
6. Under **Authentication** → set `isFallbackPublicClient` to **Yes** (or set in Manifest: `"isFallbackPublicClient": true`)
7. Do NOT create a client secret (public client)

Use your client ID with `oauth_client_secret: ""` in the config.

> **Important:** The app MUST be registered in a tenant that has Exchange Online (any M365 subscription). Personal-only Azure directories do not have Exchange Online available.

## Hotmail / Outlook.com (Personal Microsoft)

**OAuth is not supported** for personal Microsoft accounts through third-party app registrations. This is a Microsoft platform limitation — personal directories lack the Exchange Online service required to issue IMAP-compatible tokens.

### Recommended: App Password

1. Go to https://account.microsoft.com/security
2. Enable Two-Step Verification if not already enabled
3. Under **Advanced security options** → **App passwords** → Create new
4. Use the generated password:

```json
{
    "hotmail": {
        "imap_host": "outlook.office365.com",
        "imap_port": 993,
        "smtp_host": "smtp.office365.com",
        "smtp_port": 587,
        "username": "you@hotmail.com",
        "password": "your-app-password",
        "tls": true,
        "smtp_tls": false
    }
}
```

> **Why OAuth doesn't work:** Microsoft's identity platform requires Exchange Online API scopes (`https://outlook.office365.com/IMAP.AccessAsUser.All`) for IMAP authentication. These scopes are only available in organizational Azure AD tenants with Exchange Online licenses. Personal Microsoft accounts use a consumer identity provider that doesn't expose Exchange Online as an API resource.

## Remote SSH / Port Forwarding

When running over SSH (e.g., VS Code Remote SSH, Windsurf Remote), the OAuth callback server listens on the remote machine. The browser redirect needs to reach it.

### Automatic (Recommended)

VS Code and Windsurf set the `$BROWSER` environment variable to a helper that auto-opens URLs on your local machine and auto-forwards ports. This works automatically in most setups.

### Manual Port Forwarding

If auto-open doesn't work, set up a port forward for the callback port shown in the authorize URL:

```sh
ssh -L PORT:localhost:PORT user@remote-host
```

Then click the authorization URL manually.

## Token Storage

Tokens are stored at `~/.config/mail-mcp/tokens/{instance}.json` with `0600` permissions.

Each token file contains:
- `access_token` — short-lived (1 hour typical)
- `refresh_token` — long-lived (used to get new access tokens)
- `expires_at` — Unix timestamp for access token expiry

The server automatically refreshes expired access tokens using the refresh token. You should never need to re-authorize unless:
- The refresh token is revoked (e.g., password change, admin action)
- You call `mail_connect` with `force_reauth=true`
- You delete the token file

## App Passwords (Alternative)

For individual users, App Passwords are the simplest and most reliable approach:

| Provider | App Password URL |
|----------|-----------------|
| Gmail | https://myaccount.google.com/apppasswords |
| Microsoft (personal) | https://account.microsoft.com/security |
| Microsoft 365 | https://mysignins.microsoft.com/security-info |
| Yahoo | https://login.yahoo.com/account/security/app-passwords |

App passwords require Two-Factor Authentication to be enabled on your account.

## Troubleshooting

### "This app isn't verified" (Google)

For personal Gmail: click Advanced → Go to [app name]. For Workspace: ask your admin to allow the app, or use a pre-approved client ID.

### "Need admin approval" (Microsoft)

Your organization requires admin consent for third-party apps. Ask your IT admin, or use an App Password if allowed.

### Browser doesn't open automatically

Ensure your IDE passes the `$BROWSER` environment variable to MCP server processes. For manual workaround, port-forward the callback port and click the URL.

### "AUTHENTICATE failed" after successful authorization

- **Microsoft:** Ensure the scope uses `https://outlook.office365.com/` (not `https://graph.microsoft.com/`)
- **Gmail:** Ensure IMAP is enabled in Gmail settings (Settings → See all settings → Forwarding and POP/IMAP)
- **Workspace:** The Workspace admin may have disabled IMAP access

### Token refresh fails

Delete the token file and re-authorize:
```sh
rm ~/.config/mail-mcp/tokens/INSTANCE_NAME.json
```
Then call `mail_connect` again.
