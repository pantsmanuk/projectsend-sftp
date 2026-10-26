# SFTP workflow contract

PLAN.md owns feature scope. TransferSource owns file scope, availability and
download allowance. RequestTransfer owns submission; TransferRunner owns delivery
and accounting. DESIGN.md maps shared controls to their runtime owners.

## Access and navigation

- Active, unexpired staff may use transfers with their existing file permissions.
- Destination administration requires `sftp.manage-destinations` separately.
- History, details and status are requester-only, including for administrators.
  Other requesters' direct URLs return 404. No staff web retry is offered.
- Host sidebar visits use the existing Inertia same-tab location handoff.
  Transfer-page navigation joins My transfers and Send backup only, even for
  configuration administrators. Destinations navigation appears only on
  destination-administration pages; administrators enter through the separate
  ProjectSend sidebar entry. The approved destination picker is unaffected.

## Destination permissions

Destination administration offers an unchecked-by-default **Windows ACLs managed
on server** checkbox. It is an explicit administrator acknowledgement, with
persistent help explaining skipped Unix chmod operations and checks, server
responsibility for staging privacy, and database read access. Save, validation
recovery, stale-edit protection, and authorisation follow the existing settings
form. Ordinary staff cannot view or change this policy. The server default
remains strict Unix permissions; no automatic fallback or banner detection.

## Choose and review

- Choose backup is a paginated, searchable table. Only currently available files
  in the requester's library appear; spent allowance disables the Choose action.
  Search is explicit GET submission, not asynchronous typeahead. IME composition
  cannot submit. Query/page remain in the URL; Clear removes both and focuses q.
  Out-of-range pages redirect to the last page. Empty and no-results differ.
- Review reloads source eligibility and offers enabled destination IDs/names only.
  Browser/OS ownership of the native select popup and its keyboard interaction
  is accepted. The shared field owns labels, help, errors, and trigger styling.
  No destinations or unavailable service has an explanation and no Send form.
- Send explicitly confirms a copy only, without restoration or overwrite.
  CSRF and fresh server checks protect submissions. A UUID identifies each send
  form. Repeating that request returns its existing transfer without dispatch;
  using it for another requester, file or destination fails. A new review is a
  new request. Navigating away guards unsaved destination selection. Busy submits
  block repeated clicks. Validation preserves selection and focuses the error.
  Operator cancellation removes the row and retains a UUID-only replay guard;
  old forms cannot recreate it. A fresh authorised request needs a new UUID.

## History and progress

- History is newest first, 20 per page, with display-name snapshots, status and
  request time. Apply the inclusive six-calendar-month request-time cutoff
  before pagination, clamping month ends. Older queued, running and uncertain
  records remain visible. Older completed/failed records remain stored, with
  requester-authorised detail/status access. Server rendering escapes names. Timestamps show the user's
  timezone and the application locale; transport bytes use localised sizes.
- Detail shows a reference, safe fixed messages, and measurable bytes sent.
  100% bytes sent does not mean delivery is confirmed. Only Completed confirms
  delivery. Failed/uncertain outcomes instruct staff to ask an administrator
  before sending again. Checked CLI recovery remains the administrator workflow.
- Delivery status shows elapsed time as hours:minutes:seconds from the first
  recorded execution start, excluding initial queue wait. It includes final
  checks, retries, reconciliation, and any gaps after the first start; it is
  overall delivery duration rather than network-only upload time. No timer is
  shown before a start is recorded. Completed duration uses the persisted finish
  time and appears in My transfers too. Failed/uncertain elapsed time stops at
  the recorded finish, without implying successful delivery. Hours do not wrap
  at 24. Detail ticks once a second using server elapsed time plus a monotonic
  browser clock, and resynchronises with existing status polls. It freezes on
  polling pause, session loss, terminal state, or navigation away. Hidden tabs
  do not repaint; returning rechecks status. Timer text is outside live regions
  to avoid per-second screen-reader announcements. Without JavaScript, server
  rendering and manual Refresh provide the current/final duration.
- Status JSON whitelists display/progress fields. Never render internal errors,
  keys, pins, backend paths, endpoint identity, receipts or lease tokens.
  Pages and status use no-store. Backup bytes never pass through these routes.
- Active detail polls every 5 seconds, with a 10-second timeout and no overlap.
  Hidden tabs pause; returning rechecks. Stop after 180 requests, an error,
  access/session change or a terminal state. Explain pauses and retain manual
  Refresh. BFCache restoration reloads status. No optimistic success or automatic
  upload retry. Live announcements avoid stealing focus; progress remains labelled.

## Operator cancellation

There is no staff web cancel or retry button. Operators can remove a Failed
transfer with `sftp:cancel TRANSFER_UUID`. Remote staging cleanup requires all
workers and SSH sessions to be terminated and `--workers-stopped` confirmation.
Other states, active leases, publication evidence, unknown objects and incomplete
legacy histories refuse removal. Successful cancellation removes history and
returns 404 for detail/status; queued jobs for that UUID do nothing. Source and
published backups and accounting are preserved. See the
[operator procedure](README.md#remove-an-unwanted-failed-transfer).

## Shared behaviour and proof

Use shared Blade layout, field, styles, pagination and scripts for both workflows.
Read the host appearance preference; support narrow screens, system/dark themes,
keyboard focus and horizontally scrollable tables. Retain the existing guarded
administrator deletion flow. No browser drafts or credential persistence.

`tests/workflow.php` exercises real host HTTP, CSRF, queue submission, owner
isolation and invalid IDs. `tests/queued-sftp.php` proves HTTP-to-worker delivery
against generated-key loopback SSH. `tests/workflow-browser.mjs` exercises
selection, validation, progress, failures and responsive screens; its preview
simulates progress only. `tests/http.php` / `tests/browser.mjs` retain configuration
regressions. No production login, live object store or large-backup claim follows
from these isolated fixtures.
