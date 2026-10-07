# Hosted Codex connection prototype

The local `sales-workspace` plugin still uses its installed, loopback MCP bridge. Installation of that package does **not** open Fieldwork automatically. Timeline Curator uses a remote HTTPS MCP server with OAuth; the same pattern needs an internet-reachable Fieldwork service before it can be used by other people.

`backend/hosted-router.php` is the first server-side slice of that path. It serves OAuth discovery, public-client registration, Authorization Code with PKCE, an account sign-in and consent page, short-lived access tokens, rotating refresh tokens, and read-only MCP tools. Tokens are stored as hashes, bound to the MCP resource and account, and checked against account activation and password version. The normal local app and MCP bridge continue to work independently.

To prepare a hosted pilot:

1. Set up a real HTTPS origin and restricted database credentials. Configure `FIELDWORK_OAUTH_BASE_URL` to that exact origin. Route `/.well-known/oauth-*`, `/oauth/*`, and `/mcp` to `backend/hosted-router.php` at that origin while keeping the web app/API available there. Do not enable `FIELDWORK_OAUTH_ALLOW_HTTP_LOCAL` on hosting; it exists solely for loopback tests.
2. Confirm the Codex callback hostname in its actual connection setup and set `FIELDWORK_OAUTH_REDIRECT_HOSTS` to the narrow list of allowed hosts. The prototype defaults to `chatgpt.com,127.0.0.1,localhost`; a submitted redirect URI must match the URI registered by that client exactly.
3. Complete an account-facing **disconnect/revoke** control, registration/login rate limits, expired-token cleanup, HTTPS/cookie and reverse-proxy review, and a security review of this custom OAuth implementation. The current consent screen deliberately does not claim that revocation is available yet.
4. Validate the remote HTTP MCP transport with the official MCP Inspector and then an actual Codex installation. The PHP endpoint has passed direct protocol round-trip tests, but this does not establish full host compatibility.
5. Package a separate hosted plugin whose `.mcp.json` points at the verified HTTPS `/mcp` URL with `auth: "oauth"`. Keep the local package available. The marketplace's `ON_INSTALL` setting can then request linking on installation. A package without a real reachable URL cannot safely provide this experience.

Local verification uses a disposable account and loopback-only HTTP override:

```powershell
$env:FIELDWORK_OAUTH_BASE_URL='http://127.0.0.1:8791'
$env:FIELDWORK_OAUTH_ALLOW_HTTP_LOCAL='1'
php -S 127.0.0.1:8791 backend/hosted-router.php
# In a second terminal, from sales-service:
php tests/hosted-oauth.php
```

The test creates and removes its own workspace, user, lead, client registration, and grants. It never contacts a lead or sends an external message.
