---
name: fieldwork-reach-setup
description: Set up the Fieldwork Reach plugin and its optional five-minute Windows poller after installation.
---

# Fieldwork Reach setup

Use this when the user runs plugin setup or asks to enable the local Fieldwork background worker. Confirm the hosted Fieldwork connection works first.

Explain the poller permissions in bullets before running its installer:

- Windows registers a scheduled task for the current user. It checks at sign-in and every five minutes while that user is signed in.
- Each check asks Reach only whether agent work is waiting. Empty checks do not start Codex or use model tokens.
- If work is waiting, the task starts one local Codex run, which may use model tokens and the Fieldwork permissions the user granted separately.
- The poller stores a narrow readiness credential protected for the Windows user in an ignored local folder inside the selected workspace. It cannot read lead details or write to Reach.
- Setup runs the poller script with a process-only PowerShell execution-policy override; it does not change the machine policy.
- The user can remove the scheduled task and revoke polling access in Reach.

After the user authorizes this setup, run `scripts/install-poller.ps1 -Accept -WorkspaceRoot <the current Fieldwork workspace directory>` from the plugin root. The script opens Reach for account sign-in and approval, pairs without exposing the credential in chat, verifies the readiness endpoint, and registers a current-user Windows task. Do not invent success if pairing, task registration, or validation fails. On non-Windows hosts, explain that this installer is Windows-only.

To remove the local task, run `scripts/remove-poller.ps1 -WorkspaceRoot <the same Fieldwork workspace directory>`; it also revokes the poller credential when Reach is available. Do not remove unrelated Windows tasks.
