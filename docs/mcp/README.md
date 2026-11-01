# Poson MCP: connecting your own Claude (owner guide)

This lets **the CEO's own Claude** (Claude Desktop or Claude Code) read business data and, in later releases, prepare content drafts in the Poson admin. It is not a website chat. Only **one person** can hold MCP access at a time, and only an active **Admin** account.

> Uzbek and Russian versions of this guide come with release M4.

## 1. What an Admin does (Settings → MCP access)

1. Make sure Telegram is set up: **Settings → Integrations** → bot token and **Management chat** (`TELEGRAM_MANAGEMENT_CHAT_ID`). Every MCP event is reported there: a new token, first use, a new IP address or client, revokes, idle auto-revokes. In production you can't generate tokens until this chat is set.
2. Open **Settings → MCP access** and confirm your password (valid for 15 minutes).
3. **Grant access** to the CEO's Admin account. If someone else holds it, use **Transfer access**: their tokens stop working at once.
4. **Generate token**: name it after the device ("MacBook", "Office PC"). You can have up to 3 tokens. Optionally:
   - *Auto-revoke after*: 14–180 days without use (45 by default). A token never used within 7 days is revoked too.
   - *Allowed IP addresses*: up to 10 IPs or CIDR ranges. Leave it empty if the laptop moves between networks.
5. The token (`posm_…`) is shown **once**, together with ready-made setup snippets. Copy it straight into the client config. It can't be viewed again; if it's lost, use **Regenerate**.

Tokens don't expire. They stay valid until they're revoked: manually, by **Revoke all**, by idle auto-revoke, or automatically when the holder is deactivated, changes the password (including a password rehash, see section 5) or loses the Admin role. Tokens older than 180 days get a **Rotate** badge and a monthly reminder.

## 2. Connecting the client

The server address is `https://<your-domain>/mcp/admin`.

### Claude Desktop (via mcp-remote, needs Node.js)

Settings → Developer → Edit config, then add the following to `claude_desktop_config.json` and restart Claude:

```json
{
  "mcpServers": {
    "poson": {
      "command": "npx",
      "args": ["-y", "mcp-remote@0.1.16", "https://<your-domain>/mcp/admin", "--header", "Authorization:${POSON_MCP_AUTH}"],
      "env": { "POSON_MCP_AUTH": "Bearer posm_…" }
    }
  }
}
```

- Keep the version **pinned** (0.1.16 or newer; older versions have a known vulnerability). Update it on purpose, not automatically.
- The token goes in the `env` block, **never** as a literal in `args`.
- Never add `--allow-http` or `--debug`. Debug mode writes headers to log files.

### Claude Code

```bash
read -rs POSON_MCP_TOKEN && claude mcp add --transport http --scope user poson https://<your-domain>/mcp/admin --header "Authorization: Bearer $POSON_MCP_TOKEN"; unset POSON_MCP_TOKEN
```

After running it, paste the token and press Enter. Nothing is shown, and the token stays out of the shell history. Always use `--scope user`. **Never** use `--scope project` or a `.mcp.json` file: those end up in git repositories.

## 3. Hardening checklist (do all of these)

- Protect the config file: `chmod 600 ~/Library/Application\ Support/Claude/claude_desktop_config.json` (and `~/.claude.json` for Claude Code).
- Turn on full-disk encryption (FileVault on macOS, BitLocker on Windows) and a screen lock with a short timeout.
- Don't keep the config in synced folders (iCloud Drive, Dropbox, Google Drive, OneDrive).
- **Never** paste the token into a chat (Claude included), an email, Telegram or a shared document.
- In Claude, **never choose "Always allow"** for tools that change data (drafts and publishing, from release M3). Read each request before approving it.
- Did a Telegram alert show a new IP or client you don't recognise? Revoke that token on Settings → MCP access right away.
- Lost, sold or replaced the laptop: call an Admin, who revokes the token (or uses **Revoke all**).

## 4. What Claude can do

| Release | Tools |
|---|---|
| M1 (now) | `get_my_access`: who you're connected as, your role, the token's label and dates, and when it will be auto-revoked |
| M2+ | Business stats, orders and payments totals (no customer personal data), site analytics, then content drafts |

Claude acts with the holder's own permissions. Every call is written to the **Call log** tab (kept 365 days; long texts are stored only as a character count, never tokens).

## 5. For developers / ops

- Endpoint: `routes/ai.php` → `Mcp::web('/mcp/admin', AdminServer::class)`, outside the `web` group (no session, cookies, CSRF or CORS). There are no OAuth or `.well-known` routes.
- Middleware order: `throttle:mcp-ip` (60/min/IP) → `mcp.auth` (`AuthenticateMcpToken`: `posm_` + type `mcp` only, shared bad-token limiter of 10/min/IP, IP allow-list, alerts) → `EnsureMcpUser` (active, Admin role, single holder, locale en) → `throttle:mcp` (120/min/token). Writes: 30/h per user; publishing: 5/day per user (in `PosonTool`).
- Production needs `APP_URL` on https and `TRUSTED_PROXIES` set to the real proxy, so `$request->ip()` (and the IP allow-list) can't be spoofed with `X-Forwarded-For`.
- Scheduler: `mcp:revoke-idle` (daily), `mcp:sweep` (hourly; revokes holders who lost the Admin role, since spatie role events are off), and `model:prune` for `McpCall` (365 days).
- Deploy: `php artisan migrate`, then the usual production seeding via `LaunchSeeder` (it calls `RolesAndPermissionsSeeder`, which adds `mcp.manage`). Never run individual seeders on production.
- Password rehash: `HASH_REHASH_ON_LOGIN` is false by default. A rehash saves a new password hash, which counts as a password change and **revokes MCP access**. If you change `BCRYPT_ROUNDS` or the hashing driver and turn rehashing on, expect the holder to need a new grant and new tokens after their next login.
- Leak check: `tests/Feature/Security/McpTokenLeakTest.php` fails if any tracked file contains a `pos_`/`posm_` + 48-character value.
- Debug locally: `php artisan mcp:inspector mcp/admin`.
