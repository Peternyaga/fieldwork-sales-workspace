# Token-efficient Fieldwork background work

## Trigger and queue

Fieldwork writes a durable work item in the same database transaction as the action that needs agent attention. Today, saving a lead note does this. A structured `busy` outcome creates a `busy_call` item; other notes create a `review_note` item. Do not queue every click or every ordinary record edit. Extend this event policy only when a specific action needs agent judgement.

Internal follow-up tasks are separate records for a person to do. A due task is not evidence that the call happened and must never be completed by the poller.

## Five-minute poll

The poller runs on the Windows machine that will run Codex. Windows Task Scheduler starts a small deterministic process every five minutes. It calls a lightweight, authenticated HTTPS readiness endpoint on Reach. If no item is ready, it exits after that request: no Codex process, model request, prompt, or token usage. HostAfrica stores the queue but does not try to wake a machine behind Peter's home network.

When work exists, the local dispatcher starts an on-demand `codex exec` run. The agent uses the authenticated Fieldwork tools to fetch the source note, lead, and schedule. A local named mutex prevents overlapping runs on the same PC. The server's work-item state and transaction guards prevent duplicate writes on a retry. The first version supports one paired Windows computer per workspace; a distributed lease is needed before multiple computers can dispatch the same queue efficiently.

## Agent handling

For each item, the agent checks the current lead state and pending follow-ups. It can apply explicit scheduling rules or a callback time supplied by the lead. If timing or ownership is ambiguous, it marks the item `needs_review` with a reason. It cannot infer a confirmed appointment or make a call. Scheduling a task, recording the report, and resolving the source item happen in one transaction, so retries cannot create duplicates.

The poller waits before retrying a run: fifteen minutes after a successful Codex exit and thirty minutes after a failed one. The item stays pending until it has a durable `done` or `needs_review` result. The report remains in the Agent activity view. A user can queue one reversal request for a completed item; `reverse_agent_work` cancels only an unchanged, pending follow-up created by that item. Changed or completed follow-ups require human review. Reversal requests and outcomes remain in the report history.

## Installation and worker location

The local poller and Codex worker require Peter's PC to be on, connected, and signed in to Codex. A hosted queue item remains pending while the PC is off and is picked up at the next successful check. Plugin installation installs the plugin's files and MCP connection; its onboarding setup skill then invokes the Windows installer after explaining the permissions. The installer registers a current-user task, and `remove-poller.ps1` removes it.

The five-minute check uses a dedicated polling credential, separate from Codex's OAuth token. A ten-minute pairing flow opens Reach for owner approval and passes the credential directly to the local installer without displaying it in chat. The credential is stored with Windows user-scope DPAPI. The readiness endpoint returns only a boolean, not lead notes or personal data. Removing the poller revokes the credential; the user can also revoke it under Connected apps.

## Current implementation status

The source tree contains the authenticated readiness and pairing endpoints, Windows installer and poller, reporting view, report-aware MCP tools, and guarded reversal tool. The local backend integration test confirms the pairing, empty/ready responses, report, reversal, and token revocation. The scripts have passed syntax checks. The Reach release was deployed on 2026-10-09, the hosted schema is current, the Agent activity view loads, and the readiness endpoint returns 401 without a credential. The plugin was refreshed to 0.3.0 in Peter's Windows profile. The Windows task registered with sign-in and five-minute triggers; both a manually started and a subsequent automatic scheduled run returned 0 after an empty readiness check without starting Codex. A fresh write-scoped OAuth grant was completed and a local Codex run read the pending queue successfully. The first real Codex wake for a newly queued work item remains untested on production. Report and guarded reversal behavior passed local backend integration tests.
