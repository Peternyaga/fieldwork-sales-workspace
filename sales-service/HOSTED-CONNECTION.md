# Hosted Codex connection

The local `sales-workspace` plugin still uses its installed, loopback MCP bridge. The separate `sales-workspace-hosted` plugin connects to the HTTPS service at `https://reach.vumbualabs.com/mcp` with OAuth.

`backend/hosted-router.php` serves OAuth discovery, public-client registration, Authorization Code with PKCE, an account sign-in and consent page, short-lived access tokens, rotating refresh tokens, and MCP read/write tools. Tokens are stored as hashes, bound to the MCP resource and account, and checked against account activation, password version, and each tool's required scope. Existing read-only grants remain read-only until the user approves new permissions. The normal local app and MCP bridge continue to work independently.

The HostAfrica deployment is live. The database, initial owner, HTTPS routing, and daily expired-token cleanup job are configured. A remote protocol test passed registration, sign-in, consent, PKCE, token exchange, MCP reads, refresh rotation, and revocation. The hosted plugin was installed from the repository marketplace, linked to the owner account with `codex mcp login fieldwork`, and an authenticated Codex `list_leads` call succeeded.

For another deployment:

1. Set up a real HTTPS origin and restricted database credentials. Configure `FIELDWORK_OAUTH_BASE_URL` to that exact origin. Route `/.well-known/oauth-*`, `/oauth/*`, and `/mcp` to `backend/hosted-router.php` at that origin while keeping the web app/API available there. Do not enable `FIELDWORK_OAUTH_ALLOW_HTTP_LOCAL` on hosting; it exists solely for loopback tests.
2. Set `FIELDWORK_OAUTH_REDIRECT_HOSTS` to `chatgpt.com,127.0.0.1,localhost` for ChatGPT and Codex desktop callbacks. A submitted redirect URI must match the URI registered by that client exactly. Plain HTTP is allowed only for loopback callback hosts.
3. The application now includes account-facing grant revocation, registration/login rate limits, and an expired-token cleanup command. Configure the cleanup cron job, verify HTTPS/cookie and reverse-proxy behavior on the host, and complete a security review of this custom OAuth implementation before inviting users.
4. Validate the remote HTTP MCP transport with a real Codex installation and an authorized tool call.
5. Add `sales-workspace-hosted` to the repository marketplace. Its `.mcp.json` points at the HTTPS `/mcp` URL with `auth: "oauth"` and requests `leads:read`, `leads:write`, and `tasks:write`. The marketplace's `ON_INSTALL` setting asks for linking on installation.

The DirectAdmin release layout and deployment sequence are in `deploy/README.md`.

Local verification uses a disposable account and loopback-only HTTP override:

```powershell
$env:FIELDWORK_OAUTH_BASE_URL='http://127.0.0.1:8791'
$env:FIELDWORK_OAUTH_ALLOW_HTTP_LOCAL='1'
php -S 127.0.0.1:8791 backend/hosted-router.php
# In a second terminal, from sales-service:
php tests/hosted-oauth.php
```

The test creates and removes its own workspace, user, lead, client registration, and grants. It never contacts a lead or sends an external message.
