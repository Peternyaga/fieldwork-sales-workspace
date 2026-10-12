# Fieldwork Reach hosting runbook

Target: `https://reach.vumbualabs.com` on the HostAfrica DirectAdmin account `supashop`.

## One-archive upgrade of an existing installation

From `sales-service`, run `npm run package:hosted`. Upload the resulting
`publish/fieldwork-reach-upgrade.zip` to
`/home/supashop/domains/reach.vumbualabs.com/` (not `public_html`). In the
DirectAdmin terminal run this one command:

```bash
cd /home/supashop/domains/reach.vumbualabs.com && unzip -oq fieldwork-reach-upgrade.zip -d fieldwork-upgrade && bash fieldwork-upgrade/install-upgrade.sh
```

The installer requires an existing `fieldwork/config.local.php`. It backs up
the database, `public_html`, and the current backend under `fieldwork-backups/`
before changing the site; it then overlays the new backend, runs the repeatable
migration, and updates the website. It never copies a local password or replaces
the server configuration. If the database dump utility or backup fails, it
stops before touching the application. On a later failure, it restores the old
files and prints the backup path; database schema changes require a deliberate
restore from `database.sql` if a rollback is necessary. Do not delete the backup
until the site and authenticated Codex connection have been checked.

The website/server upgrade cannot refresh a plugin cached inside a user's Codex
installation. Publish and refresh `sales-workspace-hosted` separately so its
agent-work skill recognizes the new profile-edit tools.

## Release layout

Run `npm run build:hosted` from `sales-service`. The ignored `publish/reach` folder contains:

- `public_html/`: built frontend, Apache rewrites, and the PHP entry point.
- `fieldwork/backend/`: PHP application, OAuth server, MCP endpoint, and schema, outside the web root.
- `fieldwork/config.production.example.php`: configuration template. Do not put a real password in the source tree.

On HostAfrica, upload the **contents** of these folders to:

- `/home/supashop/domains/reach.vumbualabs.com/public_html/`
- `/home/supashop/domains/reach.vumbualabs.com/fieldwork/`

Preserve the `.htaccess` file. Confirm the domain's document root is the first path. Back up any existing site files before replacement.

## Private configuration and database

Create a new database and a user limited to that database in DirectAdmin. Copy `fieldwork/config.production.example.php` to `fieldwork/config.local.php` on the server. Fill in its DSN, user, and password there. Keep `fieldwork/` outside `public_html`, and restrict its configuration file to the account owner.

Do not copy the laptop's `config.local.php` or `var/` directory to the server. The hosted database starts independently of the local prototype.

In the DirectAdmin terminal, from `/home/supashop/domains/reach.vumbualabs.com/fieldwork`, run:

```bash
php backend/migrate.php
read -r -s -p 'Initial owner password: ' FIELDWORK_FIRST_PASSWORD; printf '\n'
printf '%s\n' "$FIELDWORK_FIRST_PASSWORD" | php backend/provision-hosted-owner.php 'Vumbua Labs' 'Peter Nyaga' 'YOUR_EMAIL_ADDRESS'
unset FIELDWORK_FIRST_PASSWORD
```

Replace the email placeholder with the owner's actual Fieldwork sign-in address. The password must be 12–500 characters. Provisioning works only while the database has no workspace.

Add a daily DirectAdmin cron job to run `php /home/supashop/domains/reach.vumbualabs.com/fieldwork/backend/cleanup-hosted-auth.php`.

## Verification before linking Codex

1. Verify HTTPS and that `/` serves the Fieldwork sign-in page.
2. Verify `/api/session` returns JSON and sets a secure session cookie.
3. Verify `/.well-known/oauth-protected-resource` identifies `https://reach.vumbualabs.com/mcp` and the matching authorization server.
4. Verify `/.well-known/oauth-authorization-server` contains the authorization, token, and registration endpoints.
5. Verify unauthenticated `/mcp` tool calls are rejected, then run an MCP Inspector OAuth round trip with a dedicated test account.
6. Install the separate `sales-workspace-hosted` plugin from a test marketplace and complete Codex browser linking. Verify read tools, then approve the distinct `leads:write` and `tasks:write` permissions before testing lead and internal follow-up changes.
7. Verify the **Connected apps** page can revoke the grant, after which the same access token fails.

The local `sales-workspace` plugin is separate and remains a local Node connection. The hosted plugin is listed in the repository marketplace. Existing OAuth grants do not gain write access when the service is updated; the account owner must approve a new connection. Follow-ups are Fieldwork records, not external calendar events or notifications.
