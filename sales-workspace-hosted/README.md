# Fieldwork Reach for Codex — 0.4.1

Connect to https://reach.vumbualabs.com/mcp using OAuth. Manage accessible leads and internal tasks, correct eleven profile fields with provenance and retained history, and process queued work with guarded reversal. No calls, messages or external calendar events are sent.

## Install

Add the existing Vumbua Labs marketplace and install the hosted plugin:

```powershell
codex plugin marketplace add Peternyaga/vumbua-labs-plugins --ref main
codex plugin add sales-workspace-hosted@vumbua-labs
```

Restart Codex desktop, open a new chat and connect Fieldwork Reach to your own Fieldwork account. If linking was not offered, run `codex mcp login fieldwork`. Approve only the scopes you need: `leads:read`, `leads:write`, `tasks:write`. Ask the plugin to read your current follow-ups to verify the connection. A Fieldwork owner must provision your account; installing the plugin does not create an account or grant access to other workspaces.

## Update

```powershell
codex plugin marketplace upgrade vumbua-labs
codex plugin add sales-workspace-hosted@vumbua-labs
```

Restart Codex desktop and test in a new chat. Reapprove OAuth only if the existing connection is missing or lacks the requested scopes. Server tool changes are discovered from the hosted MCP endpoint; bundled skill/script changes require refreshing the plugin package.

## Migrate from fieldwork-local

Install the marketplace package first. Disable `sales-workspace-hosted@fieldwork-local` after the marketplace copy is enabled to avoid duplicate tools. Both packages use the same `fieldwork` server name, HTTPS endpoint and scopes; preserve the existing connection and verify a read before reauthenticating. Do not log out or revoke the existing grant just to change package source.

The optional Windows poller is separate from OAuth. Keep the `.fieldwork-reach` directory and the existing `Fieldwork Reach Poller` scheduled task. Run the new package's `scripts/install-poller.ps1 -Accept -WorkspaceRoot <your existing workspace>` to update its script; when that task and credential exist, the installer preserves pairing. For first-time installation, invoke the setup skill to review permissions and approve pairing in Reach. The poller runs only while the configured Windows user is signed in and the machine is online; work remains queued while offline.

## Release 0.4.1

Moves distribution to the dedicated Vumbua Labs catalog while retaining the same plugin identity and OAuth endpoint. Includes the dedicated lead-profile skill, updated queue correction/recovery guidance and a poller prompt that invokes that workflow. Profile fields: phone, email, website, Facebook, Instagram, LinkedIn, name, organisation, contact role, location and source. Each tool requires current version and factual provenance. Historical values are retained. Ambiguous evidence needs review.

Source: [Fieldwork sales workspace](https://github.com/Peternyaga/fieldwork-sales-workspace/tree/main/sales-workspace-hosted). Distribution: [Vumbua Labs](https://github.com/Peternyaga/vumbua-labs-plugins/tree/main/plugins/sales-workspace-hosted).
