# Development plan

## Agreed scope

Staff select one existing backup and one approved destination. A backend worker
reads the authorised file from ProjectSend storage and copies it over SFTP.
Staff see completion or failure. The module does not restore databases.

Routine destination and credential configuration belongs in administration
screens, not `.env`. Keep the package private initially and independently
distributable. Use module-owned screens, access rules, and transfer history.
Do not change the host file toolbar, role editor, or audit vocabulary in the
first release. Preserve existing file access, availability, and download rules.

Destination configuration and the staff transfer workflow are separate surfaces.
Configuration requires the existing `edit_settings` permission. All active,
unexpired staff are eligible to use transfers; their existing file permissions
and client-scoped library restrictions still apply. Transfer choices expose only
enabled destination IDs and names, never connection settings or credentials.

## Phases and acceptance

| Phase | Deliverable | Acceptance |
| --- | --- | --- |
| 1 | Package, provider, test harness, installation guide | Package tests pass and provider loads in ProjectSend. |
| 2 | Destination model, migrations, validation, encrypted credentials, administration rules | Configuration round-trips; secrets stay encrypted and out of responses; unauthorised changes fail. |
| 3 | Destination administration screen and navigation | Administrator can add, edit, disable, and delete destinations after confirmation without `.env` edits; other staff cannot administer them. |
| 4 | Staff-only file/destination picker backends and source streams | Ordinary staff can select enabled destinations and their permitted files without configuration access; local/object-storage streams enforce access, availability, and download allowance. |
| 5 | SFTP stream transport against a controlled fixture | Bytes match; wrong host keys, interrupted uploads, and filename collisions fail safely; temporary files publish only on success. |
| 6 | Transfer records, dedicated queue, progress, access rechecks, and download/audit accounting | A queued transfer streams an authorised source to an enabled destination, records progress and confirmed completion, and accounts once for delivery. Credentials stay out of job payloads; automatic retries remain disabled. |
| 7 | Crash recovery, duplicate-job protection, and safe retry/reconciliation | Duplicate jobs and interrupted workers cannot create conflicting uploads or false success. Uncertain publication and abandoned staging are reconciled before retries; accounting is not duplicated. |
| 8 | Staff selection, submission, and transfer history screens | End-to-end transfer works; forged file and destination IDs cannot bypass authorisation. |
| 9 | Deployment procedure and representative large-backup trial | Real storage-to-server transfer uses bounded resources and never sends backup bytes through the browser. |

Phase 9 session scope, agreed 2026-10-21: isolated local fixtures only. The
deployment procedure and generated large-data checks are implemented; live
deployment and environment-specific acceptance are deferred. See HANDOVER.md
for measurements and DEPLOYMENT.md for the remaining operational gates.

## UAT follow-up tasks (2026-10-26)

Task 1 is implemented for review. Tasks 2 and 3 remain in the backlog.

1. **Task 1.** Implemented for review: limit My transfers history to the most
   recent six calendar months, retaining older entries in the database. Apply
   the cutoff before pagination. Older queued, running, and uncertain transfers
   remain visible; older completed and failed entries remain accessible through
   their own detail URLs.
2. **Task 2.** Add an operator workflow, initially `php artisan sftp:cancel TRANSFER_UUID`,
   to resolve an unwanted failed attempt by deleting its database record and
   associated transfer filesystem artifacts. Design safe handling of queue jobs,
   active/stale workers, uncertain publication, and retained fencing barriers
   before deleting anything. Cleanup must be retryable after partial failure and
   must not delete source backups, published backups, unrelated files, or delivery
   accounting. Refuse unsafe removal and explain the required resolution.
3. **Task 3.** Remove navigation from transfer pages to destination administration,
   including for users with `edit_settings`. Keep destination administration
   accessible through its separate ProjectSend sidebar entry with existing
   permission checks. Preserve the approved destination picker for transfers.

## Session boundaries

Read this file and HANDOVER.md first. Implement the requested phase, run focused
checks, and update the handover with exact results and the next task. Multiple
phases may share a session when explicitly agreed; preserve each acceptance gate.
Do not expand a phase silently. Never put real credentials or customer data in
fixtures, output, or documentation.

## Known integration constraints

- Laravel 12 and PHP 8.4 are the current baseline, not a promise of compatibility
  with future ProjectSend releases. Test the host services as they are introduced.
- The host's settings keys, role permissions, and activity actions use closed
  enums. Module-owned models and gates avoid extending them.
- Phase 3 uses package-owned Blade pages to avoid a frontend rebuild. The host's
  Inertia navigation hands off through a same-tab location response. Future
  React/Inertia package pages would require rebuilding the host frontend.
- A dedicated worker and appropriate timeout and retry visibility settings are
  deployment requirements. Do not let a long transfer block notification jobs.
- Phase 6 prevents destination deletion while queued, running, or uncertain
  transfers exist. Deleting other destinations preserves transfer history and
  clears its configuration reference.
- Phase 6 reloads the requesting user, checks destination availability and source
  access at execution, and clears host permission/scope caches between jobs.
  Confirmed completion and download/audit accounting commit together; phase 4
  source reads alone do not count as delivery.
- Phase 5 transport never retries. Phase 6 persists attempt identity and
  preserves uncertain outcomes. Phase 7 reconciles lost
  publication acknowledgements and abandoned staging directories before retries.
  A transport error alone does not prove the final file is absent. Phase 7 uses
  renewable execution leases plus retained remote barriers and publication/
  non-publication receipts. Never remove barriers while an old worker could
  resume. Successful attempt metadata is removed only after completion and
  accounting commit; earlier abandoned-attempt barriers remain protected.
  Live rollout and representative backup trials remain phase 9.
- In phase 2, prevent credential leakage through model serialisation, validation
  input flashing, logs, and queues. Encrypted storage alone does not cover these.
