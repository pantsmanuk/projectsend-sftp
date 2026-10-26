# Development plan

## Agreed scope

Staff select one existing backup and one approved destination. A backend worker
reads the authorised file from ProjectSend storage and copies it over SFTP.
Staff see completion or failure. The module does not restore databases.

Routine destination and credential configuration belongs in administration
screens, not `.env`. Keep the package independently distributable. Its source
repository is public. Use module-owned screens, access rules, and transfer history.
Do not change the host file toolbar, role editor, or audit vocabulary in the
first release. Preserve existing file access, availability, and download rules.

Destination configuration and the staff transfer workflow are separate surfaces.
Configuration requires the existing `edit_settings` permission. All active,
unexpired staff are eligible to use transfers; their existing file permissions
and client-scoped library restrictions still apply. Transfer choices expose only
enabled destination IDs and names, never connection settings or credentials.

## Delivery status

Phases 1–8 and UAT Tasks 1–3 are implemented and merged. No UAT development task
remains open. Phase 9's deployment guide and isolated generated-data checks are
implemented; environment-specific deployment acceptance is still operator-run.
Repository: https://github.com/pantsmanuk/projectsend-sftp.

## Original phases and acceptance

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

All three tasks are merged into `main`.

1. **Task 1.** [PR #1](https://github.com/pantsmanuk/projectsend-sftp/pull/1):
   My transfers shows the most recent six calendar months plus older queued,
   running and uncertain work. Older completed/failed records stay in the database
   and remain accessible through authorised detail/status URLs.
2. **Task 2.** [PR #2](https://github.com/pantsmanuk/projectsend-sftp/pull/2):
   `php artisan sftp:cancel TRANSFER_UUID` removes a failed attempt and verified
   unpublished staging artifacts. Remote fence removal requires stopped workers
   and terminated SSH sessions, confirmed with `--workers-stopped`. UUID guards
   prevent form replay; incomplete legacy histories refuse removal. Source and
   published backups, unrelated files and accounting are preserved.
3. **Task 3.** [PR #3](https://github.com/pantsmanuk/projectsend-sftp/pull/3):
   Transfer pages do not link to destination administration, including for
   administrators. The separate authorised ProjectSend sidebar entry and approved
   destination picker remain available.

## Remaining operational work

- Deploy the merged updates with dedicated workers stopped, apply all five
  package migrations, then restart and smoke-test using DEPLOYMENT.md.
- Record actual Windows ACL, database-service read, no-overwrite and recovery
  acceptance on the chosen servers. Previous successful Windows deliveries do
  not establish these remaining checks for every update.
- Complete the representative staging trial with the actual storage backend,
  production SQL engine, real staff login, agreed resource limits and checked
  interruption/recovery. Isolated fixtures alone do not close Phase 9.
- Prepare the owner-approved release/tag after deployment acceptance. No release,
  registry publication, host edit or live deployment is authorised by this plan.

## Session boundaries

Read this file and HANDOVER.md first. Implement only the requested task or phase.
Run focused checks and update the handover with exact results and the next task.
Multiple phases may share a session when explicitly agreed; preserve each acceptance gate.
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
