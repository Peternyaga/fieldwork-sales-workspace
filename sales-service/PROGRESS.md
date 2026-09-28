# Implementation progress

## Scope and authorisation

Local first; React frontend, PHP backend, MySQL-compatible database. Host later on the user's PHP/MySQL hosting using cron workers. General multi-offering sales product, not school-specific. User authorised implementation and requested an elegant interface and Codex plugin. No external outreach or deployment authorised. Recurring same-thread continuation `continue-sales-service-implementation` runs hourly at minute 36. It cannot guarantee resumption after quota exhaustion.

## Implemented

- React dashboard (Today, Leads, Intake, Pipeline, Schedule), responsive styling, login, manual lead capture, CSV preview/import, detail panel, notes, stage editing and follow-up creation/completion. The new Intake assignment queue supports an owner and first-contact due date; collectors have a scoped Today view and handoff detail.
- PHP session/CSRF API with workspace-scoped queries, prepared statements, optimistic lead versioning, creation request keys and duplicate warnings.
- MySQL schema, repeatable migration and CLI account/team-member setup. Existing XAMPP MariaDB is reachable locally. No user sales data imported.
- Team roles are owner, manager, salesperson and collector. Owner/manager assignment uses active workspace members, preserves assignment history and can create a first-contact task atomically. Inactive members lose access on their next request. Collectors see only their own handoff summaries, without private sales notes, tasks or other leads' identities in duplicate warnings.
- `npm install` and `npm run build` completed; PHP syntax checks passed. `php backend/migrate.php` completed successfully on the existing local database.
- `php tests/integration.php` passed all checks: sessions, lead capture, idempotency, duplicates, CSV intake, notes, task history, assignment and atomic first-contact task, stale retries, role boundaries, deactivation and tenant isolation. Test workspace and rows were cleaned; the local database has one owner workspace and zero leads.
- `node tests/plugin-protocol.mjs` passed MCP handshake, tool discovery and authenticated lead lookup.
- Owner-managed team invitations and password resets now use one-use, expiring links. Invited members stay inactive until they set a password; resets revoke older sessions. First-run owner account creation is available through the local browser on an empty database. The only owner can recover locally through a console-issued link while email delivery is unavailable.
- Account integration checks pass for invitation redemption, replay rejection, password recovery and session revocation. The local frontend build and direct plugin protocol check pass after these changes.
- Codex plugin package scaffolded with a local MCP bridge, assignment tool and sales-workspace skill; official plugin validator passed in the first turn. The package is source-only and not installed in the app marketplace yet.
- Browser visually inspected login, Today dashboard and lead profile in the first turn. A lead was created through the UI, verified, then that temporary lead and visual QA user were removed. No sample leads remain. Assignment queue and collector layout need a fresh browser review.
- The Fieldwork source and plugin prototype were pushed to the private GitHub repository `Peternyaga/fieldwork-sales-workspace` on `main`; local credentials, generated files and unrelated projects were excluded.
- Desktop Intake and Team & access layouts were reviewed in a separate local browser tab. Added horizontally scrollable mobile navigation so six sections remain reachable on narrow screens, and restored Sign out there; the previous mobile rule hid it. `npm run build` passed afterward. No new sample leads were created. Narrow-screen visual testing remains open.
- Local Codex pairing now supports browser-created owners and members. A signed-in user selects **Connect Codex**; the loopback API stores a hashed, 30-day, account-bound token and writes an ignored local token file for the MCP bridge. Reconnection rotates the token; account password reset, expiration, or token removal blocks new plugin sessions. No password is copied into chat or the plugin file.
- The **Connect Codex** control was visually checked in a separate, hidden browser tab and is present in the signed-in desktop sidebar. It was not clicked, so the user's account remains unpaired.
- Pairing was verified on a separate loopback server and disposable account: connection file creation, token rotation, old-token rejection, new-token authentication, and an MCP lead lookup all passed. The test-only file and account were removed without replacing the user's connection file. The main integration suite also passed valid, expired, revoked, and password-reset-invalidated token cases. PHP syntax, frontend build, MCP protocol, and official plugin validation passed.
- The MCP bridge can now take `FIELDWORK_SERVICE_ROOT` when installed outside the source repository. A copied plugin package in a separate temporary folder passed a live read-only MCP lookup with this setting; the temporary copy was removed. This checks portability of the bridge path, not Codex marketplace installation.

## In progress at the latest checkpoint

- The pairing change is implemented, tested, and pushed to the private GitHub repository. The active laptop database has the new table, but the user's account has not been paired; the ignored user connection file was not created or overwritten. The browser button was not clicked in the user's session.
- The user asked how others will get the plugin. Official plugin guidance supports private repo/personal marketplaces for a pilot and a reviewed public directory submission later. No marketplace entry or public publication has been created. The repo remains private.
- Next, install the source plugin through a supported local workflow, configure its `FIELDWORK_SERVICE_ROOT`, and test a live read-only tool call in Codex. The plugin still has a legacy credential-file fallback, and the local token file is single-machine state. Design multi-user and hosted connection management before broader distribution.

## Next steps

1. Browser-review populated assignment and collector layouts at desktop and mobile widths, plus the first-run and recovery screens on a disposable database. The empty Intake and owner Team screens have been reviewed at desktop width. Then separate contact/organisation/opportunity records and configurable playbooks; the current lead row is an initial vertical slice.
2. Test the pairing button in the browser with user approval for a real account, install the source plugin through the supported local workflow, and harden deployment configuration. The plugin still falls back to the legacy owner credentials file; do not treat it as hosted-ready.
3. Implement mailbox connection, authorised message revisions, reply sync, cron-compatible scheduling, desktop helper pairing and reminder sync. Do not claim these are connected yet.
4. Install the Codex plugin through the supported local plugin workflow after the service API and authentication are mature; test tool calls from an actual Codex chat.
5. Re-run the build and integration checks after each meaningful backend change. Exercise mobile layout and full lead flows once the next UI features are added.

## Known limitations

Lead assignment now uses a team member reference, while task owner remains a name label; reassignment does not transfer existing tasks. Stages are fixed. Phone comparison removes punctuation but country normalisation and shared endpoint resolution require expansion. No email, SMS, WhatsApp, calendar or alarm sync yet. MCP bridge exists and passed its direct protocol test, but it is not installed into Codex. Initial loopback server only; not production-ready. Local default config uses XAMPP root with no password; replace with a restricted dedicated user before deployment. External-host security and operational hardening remain required.
