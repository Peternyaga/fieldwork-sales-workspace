# Human review of agent work

Agent activity puts **Needs your review** first, with a linked lead, agent reason,
source note, full report and, for reversals, the original work report. Reversal
requests now have their own activity cards so their review actions are reachable.

- **Acknowledge & close** requires a human decision note. It preserves the agent
  report and records the actor, user ID, UTC time and decision in both the work
  result and lead activity history. It does not change a follow-up task.
- **Request more work / correction** requires instructions. It closes the review
  and atomically queues a separate `review_note` work item with the original
  report, source reference and instructions. It does not blindly repeat a
  reversal or create a follow-up task. Future review decisions can form a chain
  through `human_review.next_work_id` and the new source's `reviewed_work_id`.
- **Review closed** is terminal for that item. The poller and both queue readers
  select only `pending`; agent write handlers reject any non-pending item.

POST `/api/agent-work/{id}/review` is session-only and uses the existing CSRF
protection and active-account checks. It accepts `action` (`close` or
`request_correction`), required `reason` (up to 1000 characters), `review_token`
(SHA-256 of the report supplied by `/api/agent-reports`) and `request_id` (16–64
ASCII letters/digits/hyphens). Owners and managers may review workspace items;
salespeople may review only assigned leads; collectors cannot review. Workspace
scope and row locking precede transitions. A stale report, competing decision,
or non-review status returns 409. An exact retry by the same user returns the
saved outcome without another audit or queue item. Report writes, new work and
audit creation share one transaction. No schema migration is needed for this
feature: status is already VARCHAR(20), and review metadata lives in result JSON.

## Verification on 12 October 2026

`php tests/agent-automation.php`: 38 checks passed, including cross-workspace
and role denial, CSRF, input validation, stale/competing decisions, idempotent
close/correction, retained reports and audits, unchanged follow-up tasks and
poller/MCP exclusion of closed items. Existing integration and lead-profile
suites passed. PHP syntax checks, frontend build and hosted packaging passed.
Competing decisions are tested sequentially; a true concurrent multi-worker
load test was not performed.

Browser QA used `tests/agent-review-fixture.html` with synthetic reports and a
mock API; backend behavior was checked separately with the integration suite.
Verified close and correction outcomes, required notes, reviewer display, no
console errors and desktop layout. At 390×844, action buttons stack with 44px
minimum targets and text wraps. `tests/agent-review-mobile.jpg` records the
mobile layout. Run Vite and open the fixture to repeat visual QA; it is not part
of the production frontend build.

## Hosted comparison and deployment

The live page at https://reach.vumbualabs.com/ served
`/assets/index-CTlIbO1s.js` during this check. The new packaged frontend is
`/assets/index-kFG4TieK.js`, so the deployed frontend does not match this build.
This was the pre-deployment comparison. Production delivery is recorded below.

The uploadable archive is `publish/fieldwork-reach-upgrade.zip`. Follow
`deploy/README.md`: upload it outside public_html under the domain directory,
then run the existing backup-first installer:

```bash
cd /home/supashop/domains/reach.vumbualabs.com && unzip -oq fieldwork-reach-upgrade.zip -d fieldwork-upgrade && bash fieldwork-upgrade/install-upgrade.sh
```

After deployment, verify the new asset, an authenticated Agent activity review,
a closed item staying out of readiness, a correction appearing once in the
queue, audit history and the OAuth MCP connection. Preserve installer backups.
Plugin/marketplace updates are unnecessary for this session-only UI endpoint:
existing agent tools already read the new source context and reject closed work.

## Verified production delivery

Commit `5209cc8` was pushed to `origin/main` and deployed on 12 October 2026
at approximately 15:56 Africa/Nairobi using `fieldwork-review-5209cc8.zip`.
The uploaded archive SHA-256 matched the local package:
`199ece5252a32a92d596b1e32e93cf39611a65d916b5870c51d666c8defa5343`.
Before deployment, production bootstrap, schema, hosted write handlers and
migrations matched the local source; production CLI PHP was 8.3.35.

The backup-first installer reported success and current schema. Its retained
backup is `/home/supashop/domains/reach.vumbualabs.com/fieldwork-backups/20261012T125603Z/`.
Verified the nonempty database dump and saved backend router. Installed router,
review handler and frontend asset checksums matched the packaged files.

The authenticated live Agent activity view now shows both review controls on
the existing Nevada Palace Hotel and Nyamu Nyaga reports. Both forms open,
require a note and disable submission while empty. The linked Nevada Palace
lead profile loads. No review decision was submitted on customer records.
No browser console errors were captured during these checks.
The authenticated OAuth `list_agent_work` tool returned an empty pending queue.
Unauthenticated readiness and MCP data tool calls returned 401; public MCP tool
discovery remains available by design. Local integration tests verify the actual
close/correction mutations and audit/poller behavior; production verification
was read-only apart from installing the release.
