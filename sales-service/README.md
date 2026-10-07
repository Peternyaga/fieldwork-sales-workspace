# Fieldwork — local sales workspace

React + PHP 8.2+ + MySQL/MariaDB. First implementation of the lead-to-follow-up workflow; see PROGRESS.md for gaps and next steps. Not ready for public hosting.

## Run locally

1. Start your local MySQL service. Create a dedicated `sales_workspace` database. Copy `config.example.php` to `config.local.php` to override database credentials. The example uses local XAMPP defaults; use a restricted database user before hosting.
2. Run `php backend/migrate.php` to create or update the schema. On an empty local database, the website offers first-run owner setup so the owner chooses their own password. The older `php backend/provision-local.php` remains available only for the legacy local plugin prototype; it creates an owner and saves generated credentials to ignored `var/local-account.json`.
3. Run `php -S 127.0.0.1:8788 backend/router.php` from this directory.
4. Run `npm install`, then `npm run dev`; open http://127.0.0.1:5178. Vite proxies API requests to PHP. Complete first-run owner setup, or sign in if the workspace already exists. This laptop already has the legacy owner account, so its login remains in the ignored local account file.

PHP's development server is loopback-only. Do not expose it publicly. There is no production deployment or external communication integration yet.

## Current workflow

Select a project in the sidebar before working. Leads, tasks, opportunities and import previews are scoped to the selected project; owners and managers can create projects and move leads between them. General is the default project. Projects group records within a workspace, but do not create separate access-control boundaries. Existing team roles still determine visibility.

The Leads list and the dashboard's **Next relationships** section put the earliest pending follow-up first, including overdue work. Completed tasks do not affect this order, and leads without a pending follow-up appear last.

From Today, Schedule, or a lead profile, cancel a pending follow-up with a reason when it will not happen. It remains in the record as Cancelled rather than Done. To stop pursuing a lead, open its profile and choose **Close lead**; enter the reason. This marks the lead Lost and cancels all its remaining pending tasks while preserving completed work and activity history. Change its stage again to reopen it before adding new follow-ups.

Capture a lead with source, collector, offering and suggested owner; review matching contact warnings in the capture form; preview and import CSV batches through Intake; assign unowned leads to active team members with a first-contact due date; edit sales stage with stale-write detection; record notes; create internal follow-ups; complete them from Today or Schedule. A lead profile now shows separate sales opportunities: add another product or service, move its stage independently, and see each opportunity in Pipeline. When capture matches an accessible existing contact, the form offers a link to its profile so another opportunity can be added there. Assignment and the first-contact task are saved together, so a failed retry cannot duplicate the task. Data persists in MySQL. No invented sample leads are inserted. CSV imports accept up to 1000 rows or 900 KB with a `name` header and optional organisation, email, phone, source, offering, owner, collector and notes columns. The CSV owner field is a suggestion only; assignment is a separate reviewed action.

The initial lead row remains an intake record. Separate organisation, contact and opportunity tables now store the first offering and any additional opportunities for that contact. Owners and managers can see the workspace and assign leads; salespeople see leads and opportunities assigned to or submitted by them; collectors see only their own handoff records and cannot see sales notes, opportunities or tasks. Assignment changes retain a separate event history.

The owner can open **Team & access** to invite a manager, salesperson or collector. The app shows a one-use setup link valid for 48 hours; the recipient opens it and sets a password before the account becomes active. For an active member who loses access, the owner can issue a one-use reset link valid for 30 minutes. Links are displayed to the owner for private sharing and are not sent automatically. Redeeming a reset invalidates existing sessions for that account. If the only owner loses access, run `php backend/recover-local-owner.php owner@example.com` on the local machine to print a one-use recovery link. The older `backend/add-user.php` CLI remains for local testing.

Contact matching and merge review, shared organisations across multiple people, campaigns and configurable playbooks remain on the implementation queue. No email, SMS or WhatsApp is sent, and follow-ups do not yet ring alarms.

## Codex connection

`../sales-workspace` is the source plugin. Its setup skill guides Codex to open the local web app for account creation after the plugin is installed and setup is invoked. Plugin installation itself does not auto-open a browser. After signing in, choose **Connect Codex** in Fieldwork. The service saves a local, revocable 30-day connection token in ignored `var/plugin-access.json`; the plugin reads that file without receiving the user's password. Reconnecting rotates the token, and resetting the account password invalidates it. The stdio MCP bridge uses the token to get a session/CSRF pair and provides lead lookup, capture, assignment, CSV preview/import, notes and task tools. The bridge still accepts the legacy local account file if no connection token exists. The plugin is source-only and is not installed automatically. This single-machine local connection is not hosted authentication; account-specific installation and token storage must be hardened before broad distribution.

The MCP bridge also lists and creates projects, scopes lead/task searches and intake to a project, and lets an owner or manager move a lead using its current version. Its project capability is available through the same account connection as the other tools.

When the plugin package is copied outside this repository (as Codex does during installation), set `FIELDWORK_SERVICE_ROOT` to the absolute `sales-service` directory in the MCP server's environment. The bridge needs that path to read the local connection file. A copy in a separate folder passed the protocol lookup with this setting; marketplace installation in the Codex app has not yet been verified.

## Checks

`npm run build` builds the frontend. `php tests/integration.php` checks a temporary isolated test workspace against MySQL and cleans up only records created by that test. `node tests/plugin-protocol.mjs` checks the local MCP bridge. PHP files can be syntax checked with `php -l`.

## Agent attention (local prototype)

Recording a note flags that specific lead and note for later agent review. Select **Number busy** when a call reached a busy line; the plugin can then inspect the lead and internal task schedule and create one new internal call follow-up. The service rejects a conflicting time and duplicate handling of the same item. General notes remain for review rather than causing automatic changes based on their text.

The attention queue and MCP tools are implemented, but periodic Codex execution is not active until the plugin is installed, connected to the owner's account, and a recurring run is configured and tested. The task list is internal to Fieldwork; external calendar availability is not yet connected. No calls, messages, or invitations are sent by this workflow.

## Plugin onboarding

The **Connect Codex** button prepares a local access token, even before plugin installation. The app calls it **Access token prepared** until an installed plugin actually authenticates; then it shows **Plugin access verified**. Installing this local-process plugin does not automatically open Fieldwork. Its first-use setup prompt guides Codex to open the app, sign in, connect if needed, and verify a read-only lookup. Local plugin installation may require restarting the Codex desktop app to discover the marketplace.

A hosted Fieldwork plugin can instead use the same general model as Timeline Curator: a remote HTTPS MCP server protected by OAuth, with account authentication in Fieldwork and a return redirect to Codex. The marketplace's `ON_INSTALL` setting alone does not implement authentication for this local `node` MCP server. The hosted OAuth flow has not been built or tested yet.
