# Development handover

## Current state

Phases 1–8 are complete, and the user accepted the Phase 8 UI.
Phase 9's deployment procedure and isolated large-backup trial are implemented.
The user explicitly chose isolated local fixtures only for this phase's session.
The owner has since installed the package in staging, configured a real SQL
Server destination, and delivered a backup visible in SSMS. Restore header
inspection prompted the 0644 publication change documented below. The owner's
Windows OpenSSH trial then exposed synthetic Unix mode reporting: chmod succeeds
but reports 0600. The Windows ACL compatibility follow-up below adds an explicit
server-managed ACL setting while retaining strict Unix defaults. Deploying that
update and verifying actual Windows ACLs, delivery, and database-service reads
remain operator acceptance steps.
The user confirmed manual acceptance of phase 3, including destination deletion.
The package is at `packages/projectsend-sftp`, with its own Git repository and
GitHub origin `https://github.com/pantsmanuk/projectsend-sftp.git`. The owner has
confirmed deployment as a Git checkout and completed transfers to both Windows
targets. The completed-transfer cleanup follow-up below is implemented locally;
its verification and deployment requirements are recorded below. Host source, Composer files, caches, and `.env`
are unchanged.

## Verification

### Task 1: six-month transfer history (2026-10-26)

Implemented on `feat/six-month-transfer-history` for review. PLAN.md now labels
UAT follow-ups Tasks 1, 2, and 3; only Task 1 is implemented. My transfers filters
completed/failed requests older than six calendar months before pagination.
The exact cutoff is included and month subtraction clamps to the target month's
last valid day. Older queued, running, and uncertain work stays visible. All
records remain stored and requester-authorised detail/status URLs still work.
No migration, scheduler, host change, or SFTP operation is required.

Focused verification:

- `php tests/host.php ../..`: passed. New actual-host HTTP checks cover the
  cutoff at month end, one second before/after it, unresolved states, requester
  isolation, pagination, empty history, stale pages, retention and detail access.
  Before the query change this failed on `old-completed`, proving the regression.
  Logs: `/tmp/projectsend-history-before.log`, `/tmp/projectsend-history-host.log`.
- `php tests/run.php ../../vendor/autoload.php`: passed.
  Log: `/tmp/projectsend-history-package.log`.
- `php tests/http.php ../../vendor/autoload.php`: passed.
  Log: `/tmp/projectsend-history-http.log`.
- `php -l src/TransferController.php` and `php -l tests/workflow.php`: passed.
- `composer validate --strict --no-check-publish`: passed.
  Log: `/tmp/projectsend-history-composer.log`.
- `git diff --check`: passed. Reviewed the full diff.

No browser, SFTP or large-data rerun: templates, transport and streaming are
unchanged. Tests use isolated SQLite and generated fixtures, with no real
credentials, servers or backups. Tasks 2 and 3 remain pending.

### UAT follow-up backlog (2026-10-26)

Recorded three requested tasks in PLAN.md: six-month UI history visibility with
database retention, an operator `sftp:cancel TRANSFER_UUID` removal workflow for
unwanted failed attempts, and removal of destination-administration navigation
from transfer pages. Cancellation design must preserve worker fencing and safely
resolve uncertain outcomes before removing transfer artifacts. These are backlog
items only; no runtime behavior or host integration changed.

Verification: `git diff --check` passed. Reviewed the documentation diff against
the three requests. Runtime checks were not run for this documentation-only edit.
Next work is implementation of the follow-up task selected by the owner.

### Completed-transfer staging cleanup (2026-10-23)

The owner requested deletion of the successful temporary folder ahead of v1.0.0.
The new queued-SFTP assertion reproduced the previous retention behavior before
implementation: delivery completed but its `.projectsend-ATTEMPT_UUID.upload`
directory remained. Output: `/tmp/projectsend-cleanup-before.log` (expected
exit 1). No production data or remote server was used.

`TransferRunner::complete` now registers cleanup with Laravel `DB::afterCommit`,
so both normal publication and reconciliation retain their receipts until the
outermost completion/accounting transaction commits. Cleanup reloads the record,
requires Completed and a known attempt UUID, and compares current destination
identity with the recorded endpoint before connecting. It does not require fresh
requester/source eligibility or an enabled destination, because it never sends
another backup. It never mutates the completed row, timestamps, error or accounting.
Failures produce a fixed warning with only the transfer UUID; even a logging
failure cannot escape the after-commit callback.

`SftpTransport::cleanupCompleted` verifies the manifest and entire known directory
shape before using non-recursive deletes/rmdirs. It handles the ordinary writer
receipt and the fenced/sealed successful-publication layout. A remaining payload,
unknown file, mismatched identity/receipt, or symlink refuses cleanup without
recursively sweeping content. The manifest is deleted last; empty directories
and an already-absent root are valid resumable cleanup states. No final backup
path is read or modified. The successful attempt's root can be removed because
publication and accounting are durable; earlier abandoned-attempt barriers must
remain, even after a newer UUID completes, to fence stale workers.

An explicitly targeted `sftp:recover TRANSFER_UUID` now queues cleanup for an
already-completed record, including successful folders retained by older
releases. `--retry` cannot re-upload a completed record. Duplicate completed jobs
also only attempt cleanup. Untargeted recovery scans still skip completed history.
No new schema, queue payload fields, command, setting, frontend or dependency.
Deploy with dedicated workers gracefully stopped and restart them afterward.
The owner has separately confirmed successful Windows delivery, production Git
checkout replacement, and manual SQL-backup-folder junction setup on both Windows
targets; this cleanup update has not been deployed or tested on those servers.

Focused verification from the package directory:

- `php tests/host.php ../.. --sftp`: passed. Covers actual staff HTTP-to-queue
  completion cleanup, outermost-commit timing, refusal of unexpected content,
  changed-endpoint refusal before connecting, unchanged Completed rows and
  accounting, disabled-destination cleanup, explicit cleanup retries, Windows
  partial-rmdir failure and successful retry, retention of receipts after
  accounting rollback, cleanup of reconciled publication, retained earlier
  fencing barriers, and existing duplicate/stale/hard-killed worker tests.
  Output: `/tmp/projectsend-cleanup-host-sftp.log`.
- `php tests/sftp.php ../../vendor/autoload.php`: passed. Covers successful
  recovered-attempt metadata removal without touching the final empty backup,
  symlink/root/child refusals, retained-payload and mismatched-manifest refusal,
  entire-shape validation before deletion, resumed empty-root cleanup,
  idempotence, plus existing transport, Windows-permission, collision, receipt,
  checksum and real-disconnect checks. Output: `/tmp/projectsend-cleanup-sftp.log`.
- `php tests/host.php ../..`: passed, including routine-scan exclusion of
  completed history, explicit completed UUID cleanup dispatch, legacy completed
  records without guessable paths, host boot/gates, credential exclusion and
  source/workflow/queue regressions. Output: `/tmp/projectsend-cleanup-host.log`.
- `php tests/run.php ../../vendor/autoload.php`: passed package discovery,
  decorated-console boot, destination/credentials/validation and migrations.
  Output: `/tmp/projectsend-cleanup-package.log`.
- `php tests/http.php ../../vendor/autoload.php`: passed configuration form,
  credential exclusion, CSRF, authorisation and deletion regressions.
  Output: `/tmp/projectsend-cleanup-http.log`.
- `rg --files src tests database/migrations -g '*.php' -0 | xargs -0 -n1 php -l`:
  passed all 42 PHP files. Output: `/tmp/projectsend-cleanup-syntax.log`.
- `python3 -c "from pathlib import Path; compile(Path('tests/sftp-permissions-server.py').read_text(), 'tests/sftp-permissions-server.py', 'exec')"`:
  passed the updated relay's syntax. `git diff --check`: passed.
- `composer validate --strict --no-check-publish`: passed.
  Output: `/tmp/projectsend-cleanup-composer.log`.

No browser or large-data rerun: UI and backup streaming are unchanged. All new
network checks use generated keys, synthetic data, SQLite and loopback SSH.
Native Windows ACL/sharing-lock behavior and deployment remain operator checks.
The owner reviewed the documented change list and authorised committing and
pushing this follow-up. Use `git log -1` for its commit and `git status -sb` for
tracking state. No release/tag, host source/configuration, real database, remote
server, credential, or existing backup was changed.

### Initial repository commit preparation (2026-10-23)

The owner completed LICENSE with copyright 2026 GGP Systems Limited and requested
that all package files be committed. Preserved the owner's licence text and
author credits. Aligned composer.json to `BSD-3-Clause` and README.md to LICENSE.
Updated workspace guidance to distinguish this package's new Git repository
from the parent release installation. No remote is configured.

Checks before the initial commit:

- `php tests/run.php ../../vendor/autoload.php`: passed. Output:
  `/tmp/projectsend-initial-commit-package.log`.
- `composer validate --strict --no-check-publish`: passed with the existing root
  version notices. Output: `/tmp/projectsend-initial-commit-composer.log`.

The staged whitespace check also identified and removed trailing whitespace on
an empty line in `tests/sftp-fixture.php`. `git diff --cached --check` and the
staged-file secret/artifact scan passed. No runtime behavior changed since the
recorded integration checks; browser/SFTP suites were not repeated. The initial commit is requested
as `feat: add ProjectSend SFTP transfer package`. Check `git log -1` for its
result and `git status --short` for the final worktree state.

### Repository initialisation and licence template (2026-10-23)

At the owner's request, `git init .` initialised a repository at the package
boundary. `git rev-parse --show-toplevel` confirms this package directory;
`git status --short` shows the package files as untracked. Nothing is staged or
committed, and no remote is configured. Added the standard BSD 3-clause LICENSE
with `[YEAR]` and `[COPYRIGHT HOLDER]` placeholders for the owner to edit before
requesting the initial commit. Composer's current proprietary licence metadata
and README licensing wording need alignment with the final approved licence
before that commit. No runtime files changed; application tests were not rerun.

### Pre-Git commit hygiene audit (2026-10-23)

At the owner's request, inspected all 67 package files, including hidden files.
No symlinks, binary payloads, local environment files, database/backup artifacts,
or embedded private-key blocks or recognisable GitHub/AWS access tokens were
found. Test credentials are synthetic/generated; fixture databases, SSH keys,
logs and screenshots use temporary directories outside the package. Source,
tests, migrations, design metadata and maintained documentation remain eligible
for version control.

Expanded `.gitignore` while preserving the existing vendor/composer.lock policy
for this standalone library. Added local environment/configuration and private-key
patterns, backup/database payloads and SQLite sidecars, logs, the default generated
premium audit report, Python bytecode/cache directories, and OS metadata.
`.env.example` remains eligible as a template. Public keys and source images
are not blanket-ignored. No existing file needed removal. No repository was
initialised in this package or its parent application.

Checks:

- `python3 /tmp/projectsend-gitignore-check.py`: passed Git's actual ignore
  matcher against 35 sensitive/generated sample paths in a disposable temporary
  repository. All 67 current package files and six source/template examples
  remain eligible. The temporary repository was removed automatically.
- `php tests/run.php ../../vendor/autoload.php`: passed the documented package
  checks. Output: `/tmp/projectsend-gitignore-package.log`. No application
  behavior changed, so browser and SFTP integration checks were not repeated.

### Windows ACL compatibility follow-up (2026-10-23)

The owner reproduced a Windows OpenSSH destination failure after a complete
upload. The server accepted `chmod 0644` but continued reporting 0600, so the
package stopped before the final rename. A separate disposable probe confirmed
rename worked. Actual Windows ACLs granted SYSTEM/Administrators full access
and inherited broad Users access; the reported 0700/0600 modes did not describe
that access. SQL Server runs as LocalSystem in this installation, covered by
SYSTEM. The earlier SSH authentication failure was a separately corrected
server-side authorized-key filename mismatch.

The owner explicitly authorised an administrator-only **Windows ACLs managed on
server** destination setting. The new additive migration
`2026_10_23_000000_add_windows_acl_policy_to_sftp_destinations` adds
`windows_acl_managed`, false by default for existing and new destinations.
SaveDestination validates it as a boolean; the standard create/edit form owns
opt-in/out, permanent help, validation recovery and stale-edit protection.
No banner-based detection or permission-error fallback is used. The shared
transport permission helper skips chmod and Unix mode verification only for
explicitly opted-in destinations, including staging/writer creation and
recovery barriers. Default Unix staging remains verified 0700 and publication
remains verified 0644. All destination mkdir calls omit phpseclib's implicit
chmod; the helper applies and verifies Unix modes explicitly where required.

This setting delegates ACL enforcement to the Windows administrator. SFTP v3
cannot inspect or modify NTFS ACLs. Incoming/staging directories, payloads,
manifests and receipts must inherit ACLs restricted to the upload account,
SYSTEM, Administrators, and explicitly trusted database service identities.
Trusted database readers can also read staging; isolating them until publication
needs a different server-managed design. DEPLOYMENT.md includes ACL inspection,
backup/provisioning examples, child-ACL caveats, retry and rollback steps, and
actual Windows acceptance checks. No Windows ACLs were modified by this work.

Endpoint identity deliberately excludes mutable permission policy, allowing an
existing chmod failure to recover against its same approved endpoint after
administrator opt-in. Destination revisions do change, preserving queued-work
configuration checks. No transfer protocol or accounting changes. Recovery still
refuses to retry if moving the old writer fails, including Windows sharing-lock
failures. Leave attempt directories and barriers in place. Windows destination
filenames additionally reject NTFS alternate-stream syntax, reserved device
names, trailing dots/spaces, invalid UTF-8 and invalid Windows characters before
remote operations. Submission refuses such names without creating queue work.
Linux filename rules are unchanged.

Tests add an optional real-SFTP protocol relay, `tests/sftp-permissions-server.py`,
launched only for Windows-permission fixtures. Python 3 is a test requirement,
not a runtime dependency. It simulates accepted no-op chmod, denied chmod, and
refused rename over generated-key loopback OpenSSH. It does not emulate NTFS or
actual Windows sharing locks. Both direct and actual-host HTTP/queue paths
exercise opt-in, publication, recovery, retry, no-overwrite and exactly-once
accounting. A before/after synthetic probe using the saved original transport
failed with the original permission error before the change and publishes the
same bytes after it. Scripts/logs are under `/tmp/projectsend-windows-*`.

Commands from the package directory and results:

- `php tests/run.php ../../vendor/autoload.php`: passed, including existing-row
  migration defaults, policy round-trip/preservation/opt-out, validation,
  credential protection, decorated-console boot, and migration rollback/reapply.
  Output: `/tmp/projectsend-windows-package.log`.
- `php tests/http.php ../../vendor/autoload.php`: passed, including administrator
  opt-in/out through real forms, validation input preservation without secrets,
  unauthorised policy writes, existing stale edits, CSRF, deletion and connection
  label regressions. Output: `/tmp/projectsend-windows-http.log`.
- `php tests/host.php ../..`: passed actual-host boot, gates, web credential
  exclusion, source access, staff workflow, queue and migrations.
  Output: `/tmp/projectsend-windows-host.log`.
- `php tests/host.php ../.. --sftp`: passed with the final implementation,
  including strict no-op chmod failure, administrator opt-in, refused fencing,
  checked retry of the original attempt, exact publication and one accounting
  entry, Windows alternate-stream rejection before queue insertion, staff
  HTTP-to-queue Windows delivery, plus existing Linux 0644, S3 mock, collisions,
  checksum recovery, concurrent workers, stale-worker resumption, hard kill,
  and interrupted upload checks.
  Output: `/tmp/projectsend-windows-host-sftp-final.log`.
- `php tests/sftp.php ../../vendor/autoload.php`: passed with the final
  implementation, including the no-op chmod reproduction, explicit ACL policy,
  no implicit/explicit chmod in opted-in upload/recovery, mode mismatch and
  denied chmod refusals under strict policy, Windows filename validation,
  refused rename/fencing, safe recovery/retry, no-overwrite, host pinning,
  existing 0700/0644 checks and real server disconnect.
  Output: `/tmp/projectsend-windows-sftp-final.log`.
- `SFTP_BROWSER_ORIGIN=http://127.0.0.1:8768 node tests/browser.mjs ../../vendor/autoload.php`:
  passed against `tests/preview.php` served with
  `SFTP_TEST_DIRECTORY=/tmp/projectsend-windows-ui-fixture php -S 127.0.0.1:8768 tests/preview.php`.
  Firefox used a separate temporary profile. Checked default-off policy,
  associated help, keyboard opt-in, persisted save and opt-out, existing
  credential/validation/dialog/deletion flows, and narrow light/dark layouts.
  Inspected desktop and mobile-dark screenshots; shared tokens are unchanged.
  Output: `/tmp/projectsend-windows-browser.log`. Preview/Firefox stopped.
- `php /tmp/projectsend-windows-regression.php before`: expected exit 1 with
  `Could not set permissions on the uploaded backup.` using the saved original
  transport. `php /tmp/projectsend-windows-regression.php after`: exit 0 with
  exact published bytes against the same generated-key/no-op-chmod fixture.
  Outputs: `/tmp/projectsend-windows-regression-before.log` and
  `/tmp/projectsend-windows-regression-after.log`.
- `rg --files src tests database/migrations -g '*.php' -0 | xargs -0 -n1 php -l`:
  passed all 40 PHP files. Output: `/tmp/projectsend-windows-syntax.log`.
- `python3 -c "from pathlib import Path; compile(Path('tests/sftp-permissions-server.py').read_text(), 'tests/sftp-permissions-server.py', 'exec')"`:
  passed. `node --check tests/browser.mjs`: passed.
- `composer validate --strict --no-check-publish`: passed, with the existing
  missing-root-version notices. Output: `/tmp/projectsend-windows-composer.log`.
- `python3 /home/murrayc/.codex/plugins/cache/openai-curated-remote/frontend-design-premium/1.4.0/skills/frontend-design-premium/scripts/audit_project.py . --mode strict --output /tmp/projectsend-windows-premium-audit.json`:
  passed with zero findings. DESIGN.md token frontmatter is unchanged; its
  separate designmd schema CLI remains absent and was not installed or run.

The package baseline and reviewed source diff are retained at
`/tmp/projectsend-windows-before` and `/tmp/projectsend-windows-source.diff`.
No Git metadata or commit is available in this release installation.

No host source, Composer dependencies, `.env`, live database, production keys,
real backups, or remote server changed. The new migration must be applied by the
operator, Windows ACLs secured, the destination opted in, and dedicated workers
restarted before retrying the retained attempt. Actual Windows ACL/readability,
sharing-lock recovery, and live MySQL/PostgreSQL migration acceptance remain
operator-run; local checks use SQLite and synthetic data only.

### Delivery duration UX follow-up

At the owner's request, Delivery status now shows a seconds-ticking
hours:minutes:seconds elapsed timer during execution, then a fixed completed
duration. Finished rows in My transfers retain the recorded duration too.
`TransferController::summary` derives it from existing `started_at` and
`finished_at` columns. No migration, worker change, new dependency, or frontend
build is needed. Initial queue wait is excluded. Since `started_at` is retained
across retries, elapsed delivery time includes checks, recovery, and gaps after
the first execution start. It is not a measurement of network-only upload time.

The shared duration partial and existing status poller own rendering and live
updates. A monotonic browser clock ticks between server polls, without extra
requests or browser storage. Terminal states and polling errors/session changes
stop it. Queued records without a start show no duration. Failed/uncertain
records show elapsed time only, without implying confirmed delivery. Server
rendering preserves timing without JavaScript. The timer sits outside live
regions and reserves one line with tabular digits. DESIGN.md and UX-CONTRACT.md
record these rules. The accepted layout and theme tokens are retained.

Checks from the package directory:

- `php tests/host.php ../..`: passed, including queued/running/completed/failed/
  uncertain timing, no start, queue-time exclusion, fixed end times, durations
  exceeding 24 hours, and completed duration in detail/history. Existing access,
  status whitelist, form/queue and configuration regressions also passed.
  Output: `/tmp/projectsend-duration-host.log`.
- `SFTP_WORKFLOW_ORIGIN=http://127.0.0.1:8767 node tests/workflow-browser.mjs`:
  passed against a separate synthetic preview directory at
  `/tmp/projectsend-sftp-duration-preview`. Covered live ticking, no timer before
  start, completion stop, refresh/history persistence, offline freeze, live-region
  separation, and existing workflow/focus/narrow light/dark checks. Inspected
  completed desktop and running mobile-dark screenshots. Output:
  `/tmp/projectsend-duration-browser.log`. No real SFTP connection was made.
- PHP syntax checks passed for the controller, workflow HTTP checks, and
  preview router. Node syntax check passed for the browser script; the browser
  run exercised the inline JavaScript. Premium strict audit reports zero
  findings at `/tmp/projectsend-sftp-duration-audit.json`. DESIGN.md runtime
  tokens were preserved; the designmd CLI remains absent, so its separate
  schema lint was not run.

No host source/configuration, real database, remote server, or existing backup
was changed. The user reports that the already-delivered staging backup's
permissions were changed during their own SQL Server tests. Next, deploy the
package UI update and verify the timer on a representative staging transfer.

### Staging backup permissions follow-up

The owner requested 0644 publication so SQL Server, running as another account,
can read delivered backups without an administrator changing each file.
`SftpTransport` now sets and verifies 0644 on the verified staged payload before
rename. Failure to set that mode prevents publication. Private staging and
recovery directories remain 0700. No new setting, migration, host change, or UI
change is required. Deploy the package update and gracefully restart dedicated
SFTP workers so new jobs use it. Existing delivered files are untouched and
need a one-time permission change if database access is required.

0644 permits reads by any local account able to traverse the destination path.
Operators must restrict directory access to intended accounts and give the
database service traversal access through each parent directory. Server ACLs,
container mappings, and security policies can still restrict reads. README.md
and DEPLOYMENT.md now document the publication mode and this directory setup.
This supersedes the historical 0600 publication notes in Phase 5 below.

Regression coverage checks the final 0644 mode for direct and actual staff
HTTP-to-queue-to-SFTP uploads. A managed-upload check inspects the ready payload
before rename, proving it is 0644 while both staging directories remain 0700.
The new direct-upload assertion reproduced the original 0600 mismatch before
the transport change. No real backups, server credentials, or remote staging
server were used in these tests.

Checks from the package directory:

- `php tests/sftp.php ../../vendor/autoload.php`: passed direct and managed
  0644 publication, 0700 staging, existing transport/receipt/collision checks,
  and server disconnect. Output: `/tmp/projectsend-permissions-sftp.log`.
- `php tests/host.php ../.. --sftp`: passed, including 0644 staff publication,
  checksum/accounting, duplicate workers, fencing, explicit retry, hard-kill
  recovery, and interrupted-upload checks. Output:
  `/tmp/projectsend-permissions-host.log`.
- PHP syntax checks passed for `src/SftpTransport.php`, `tests/sftp.php`, and
  `tests/queued-sftp.php`.

Staging installation follow-up: Composer resolved and symlinked the package on
the owner's server, but its post-autoload `package:discover` failed because
Collision's console exception-handler decorator was passed to a callback typed
as Laravel's concrete Handler. The provider now registers its secret-input
configuration against the concrete Handler, matching Laravel's own exception
configuration hook. Collision is console-only; web validation input exclusion
remains covered by host and HTTP tests. No host dependency changes are required.
`tests/run.php` now resolves a decorated handler before boot and checks that boot
preserves it. It uses the actual Collision wrapper when available, or a minimal
contract decorator when development dependencies are absent.

Focused checks after this fix, from the package directory:

- `php tests/run.php ../../vendor/autoload.php`: passed, including the boot
  regression. Output: `/tmp/projectsend-handler-package.log`.
- `php tests/host.php ../..`: passed, including real-host credential exclusion
  from validation input. Output: `/tmp/projectsend-handler-host.log`.
- `php tests/http.php ../../vendor/autoload.php`: passed, including secret-safe
  form validation recovery. Output: `/tmp/projectsend-handler-http.log`.
- Repeated `tests/run.php` after loading the actual Collision ExceptionHandler
  from a local Composer archive into `/tmp`: passed. Output:
  `/tmp/projectsend-handler-collision.log`. No development packages were installed
  and vendor was unchanged. PHP syntax checks passed for the provider, run
  harness, and decorator fixture.

After copying this package fix to staging, rerun `composer dump-autoload` from
the host root to repeat the failed discovery step. Then continue with the
deployment procedure. Composer cache permission warnings were separate from
the TypeError and did not prevent dependency installation.

README.md documents fingerprints, transport, queued execution, fixture commands,
and the staff workflow. DEPLOYMENT.md covers Phase 9 operations. Current results
and next steps are recorded below.

Run these commands from the package directory:

- `php tests/run.php ../../vendor/autoload.php`: passed discovery, boot,
  destination round-trip, encrypted storage, hidden credentials, blank-secret
  preservation, key rotation, stale-edit protection, unauthorised writes,
  malformed input, passphrase whitespace, and migration rollback/reapply checks.
- `php tests/host.php ../..`: passed actual ProjectSend boot, administration
  access checks against real user models, and validation redirect checks proving
  that credentials are excluded from session input. This includes guest, client,
  inactive, expired, and staff-without-permission refusals. Database queries use
  in-memory SQLite; caches and storage use a temporary fixture; `.env` is not read.
  The real navigation event adds the SFTP link only for authorised staff.
- `php tests/http.php ../../vendor/autoload.php`: passed the form flow through
  web middleware, key upload, creation, update, enable/disable, invalid CSRF,
  unauthorised read/write requests, stale edits, secret-safe validation recovery,
  and the Inertia 409 location handoff.
- `node tests/browser.mjs ../../vendor/autoload.php`: passed in Firefox 158
  against the loopback fixture. Covered key upload, create/edit/enable/disable,
  passphrase show/hide, Tab navigation, unsaved-change dialog focus, first-invalid
  field focus, blank secrets after errors, and narrow layout without page
  overflow. Desktop and mobile light/dark screenshots were inspected.
- Frontend premium strict audit: zero findings. Report is at
  `/tmp/projectsend-sftp-premium-audit.json`; regenerate with the skill's
  `audit_project.py` and the package root. DESIGN.md documents runtime ownership.
- `composer validate --strict --no-check-publish`: passed. Composer emits a root
  version notice because this directory has no Git tag or version metadata.
- PHP syntax checks on every package PHP file: passed.
- Node syntax checks on the browser script and inline browser behaviour: passed.

No dependencies were downloaded. Both harnesses use installed Laravel code and
fixture Composer metadata; a fresh `composer install` and the documented host
`composer require` remain untested. This package is not installed in the live
application. No migration was applied to its real database. MySQL/PostgreSQL migration execution and concurrent
database sessions are untested; stale edits are tested sequentially on SQLite.
The full real-host login-to-navigation-to-settings flow has not been browser
tested. Host navigation is checked with real user models; HTTP/browser tests use
a separate fixture with a synthetic identity. No frontend rebuild is required.

## Phase 2 implementation

- `Destination` owns `sftp_destinations`; secrets use encrypted casts and hidden
  attributes. Presence flags support future forms without returning secrets.
- `SaveDestination` owns authorisation, validation, SSH key parsing, and atomic
  writes. Metadata updates and blank credentials preserve the current key pair.
- The provider registers migrations, the `sftp.manage-destinations` gate, secret
  input exclusions, and whitespace preservation. It adds no host enum entries.
- phpseclib became an explicit dependency in phase 2 to validate keys rather than
  accepting unusable credentials until phase 5. Installed 3.0.56 supplies it here.

## Next task

Deploy the completed-transfer cleanup update with dedicated SFTP workers stopped,
then restart the workers. No migrations, credential/settings changes, or frontend
build are needed for this follow-up. Verify a newly Completed transfer removes
its successful attempt directory on Linux and Windows, preserving the final
backup and one accounting entry. Use `sftp:recover TRANSFER_UUID` to retry a
completed record's leftover cleanup if needed; it never re-uploads that record.
Do not sweep older abandoned-attempt barriers. After deployment acceptance,
prepare the requested v1.0.0 release. Automated checks remain fixture-only.
Production deployment and native Windows ACL/sharing-lock acceptance are
operator-run. Do not deploy or create a release tag automatically.

Staff recovery remains administrator-operated through `sftp:recover`; phase 8
adds no staff web retry. Configuration requires `edit_settings`; all eligible
staff may transfer only files already permitted to them. History and detail/status
are strictly requester-only, even for administrators. No cross-user history UI.

## Phase 9 implementation and verification

- Added DEPLOYMENT.md with release preparation, locked Composer integration,
  migrations, shared queue topology, effective reservation/visibility settings,
  a dedicated supervised worker, staging login/sidebar checks, monitoring,
  reconciliation, and rollback/data-retention steps. The default host reservation
  is 90 seconds; it must exceed the fixed 3600-second job timeout. The example
  uses 3660 seconds and allows 3700 seconds for a graceful supervisor stop.
  Reservation changes affect ordinary jobs sharing the default connection.
- Added `tests/large-backup.php`, reusing the isolated host bootstrap and SSH
  fixture. It generates random data in 1 MiB chunks, submits through actual-host
  staff HTTP review/send with CSRF, then launches an independent Laravel
  database queue worker with `memory_limit=128M`. No upload occurs in the HTTP
  process. Both local storage and the installed S3/Flysystem adapter with a
  file-backed mock AWS response deliver to real loopback OpenSSH.
- The trial checks completed status, exact final size and SHA-256, one accounting
  entry naming the requester and transfer UUID, empty queue/failed jobs, HTTP
  response bodies below 256 KiB, PHP peak below 128 MiB, and Linux peak RSS below
  256 MiB. The S3 mock also checks the selected GetObject key and streaming flag.
  Fixture data, database, generated credentials, and SSH server are removed on
  normal exit. README.md links the procedure and trial commands.
- Commands from the package directory:
  - `php tests/large-backup.php ../.. 256 local`: passed, 8.063 seconds,
    31.75 MiB/s, 42,467,328 bytes PHP peak, 70,328 KiB peak RSS, 19,600 total
    staff HTTP response-body bytes.
    Metrics: `/tmp/projectsend-phase9-local-256.json`.
  - `php tests/large-backup.php ../.. 1024 local`: passed, 13.854 seconds,
    73.91 MiB/s, 42,467,328 bytes PHP peak, 70,220 KiB peak RSS, 19,594 total
    staff HTTP response-body bytes. Metrics: `/tmp/projectsend-phase9-local-1024.json`.
  - `php tests/large-backup.php ../.. 1024 s3-mock`: passed, 13.971 seconds,
    73.29 MiB/s, 59,244,544 bytes PHP peak, 86,828 KiB peak RSS, 19,594 total
    staff HTTP response-body bytes. Metrics: `/tmp/projectsend-phase9-s3-1024.json`.
  - `php tests/host.php ../..`: passed actual-host source, HTTP workflow, queue,
    authorisation, credential isolation, recovery command and migration checks.
    Output: `/tmp/projectsend-phase9-host.log`.
  - `php -l tests/large-backup.php`: passed.
  - `composer validate --strict --no-check-publish`: passed, retaining the
    existing Composer root-version notice. No dependency download or install.
  - `php tests/large-backup.php ../.. 1 local`: correctly refused with usage
    and exit 1 before bootstrapping or allocating a fixture.
- Initial trial attempts hit sandbox loopback restrictions, then a fixture
  field-name error. The loopback trial was approved and the fixture corrected
  to use Destination's existing `directory` field before the passing runs.
- Local 256 MiB and 1 GiB trials show payload size exceeding the worker memory
  limit without full-file buffering. Timings include worker execution, not
  fixture generation or the parent's final hash check. Loopback timings are
  measurements of this machine only. AWS HTTP transport/network buffering is
  untested; the mock body is file-backed. HTTP tests use synthetic authentication,
  without a real browser or login. The accepted UI was unchanged and no browser
  retest was needed. Production transport/runner code was unchanged, so existing
  interruption/recovery suites were not rerun in full during this phase.
- No host source, Composer files, `.env`, live database, host caches, dependencies,
  customer data, production server, or real keys changed. No live package install,
  migration, worker deployment, real browser login, MySQL/PostgreSQL concurrency,
  live object-store connection, or representative production-size trial was run.
  Those gates remain in DEPLOYMENT.md for the owner's chosen environment.

## Phase 8 implementation and verification

- Added package-owned Blade staff search/selection, review with native approved
  destination selection, explicit send, progress detail and paginated own history.
  Destinations remain a separate administrator screen. The host navigation event
  now contributes SFTP transfers for eligible staff. No host/frontend rebuild.
- TransferController uses existing TransferSource / RequestTransfer boundaries.
  Direct forged IDs, current scope, availability and allowance remain enforced.
  Detail/status enforce requester ownership separately from configuration access.
  Status JSON explicitly whitelists display/progress fields; internal errors,
  storage paths, endpoints, secrets and recovery tokens never reach these screens.
- A unique form request UUID becomes the transfer primary key. Replayed requests
  return the existing own file/destination transfer without queuing again, even
  after completion. Nonce reuse with another actor/file/destination is rejected.
  Destination locking serialises same-destination requests; UUID uniqueness
  prevents duplicates across destinations. A rejected collision cannot mark the
  existing row failed. No migration or new dependency was required.
- Progress distinguishes bytes sent from confirmed delivery, with fixed safe
  failed/uncertain guidance and no staff retry. Polls are every 5 seconds with
  a 10-second timeout, no overlap, hidden-tab pause, and a 180-request cap.
  Errors/session changes pause with manual Refresh; terminal states stop polling.
  BFCache detail restoration reloads. Pages/status are no-store.
- Extended shared Blade field/layout/styles/scripts/pagination. Review protects
  unsaved selection and busy submissions. Search is explicit GET with IME guard,
  URL paging, clear/reset/focus, and distinct empty/no-results states. Native
  select popup appearance/geometry belongs to the browser/OS. Shared theme setup
  now reads the host's localStorage appearance preference before paint; the old
  cookie-only setup missed that preference in actual host middleware.
- DESIGN.md and UX-CONTRACT.md record ownership, access, polling and recovery
  rules. README.md documents routes, use and previews. No credentials/drafts or
  backup bytes are stored in browser storage; only the existing appearance
  preference is read.
- `php tests/run.php ../../vendor/autoload.php`: passed package checks.
- `php tests/host.php ../..`: passed existing host/backend tests plus real-host
  staff HTTP review, valid/invalid CSRF, requester isolation including admin,
  forged/scoped/unavailable/spent sources, disabled/forged destinations,
  idempotent replay and conflicting UUIDs, search/pagination, own history paging,
  escaped names, validation, safe status whitelist for all states, navigation,
  empty states, no-store and Inertia handoff. `tests/workflow.php` is included by
  the host harness; its fixtures/jobs are removed before backend tests continue.
- `php tests/host.php ../.. --sftp`: passed actual staff HTTP-to-database-queue-
  to-generated-key-loopback-SFTP delivery, exact bytes, one audit entry and own
  completed status/history, plus existing local/S3-adapter, recovery, interruption
  and independent worker regressions. Output: `/tmp/projectsend-phase8-host-sftp.log`.
  This full run preceded only later display/validation/test additions. Transport
  and runner were unchanged; the standalone transport suite was not repeated.
- `php tests/http.php ../../vendor/autoload.php`: passed configuration regressions.
- `node tests/workflow-browser.mjs`: passed Firefox 158 staff search/clear focus,
  first-invalid focus, native select keyboard typeahead, unsaved-change dialog,
  send, simulated live progress, 100%-unconfirmed state, completion/history,
  failed/uncertain guidance, network pause, session stop, stable focus, long
  labels, narrow light/dark and empty library. Desktop/mobile screenshots were
  inspected, including real host appearance preference after reload. The
  platform popup was exercised with Space/Escape; headless screenshots omit the
  OS popup window, so no cross-platform popup visual claim is made.
- `node tests/browser.mjs ../../vendor/autoload.php`: passed all configuration
  browser regressions including deletion. Its click helper now waits for a
  complete document and animation frames before inspecting autofocus. This run
  used `/tmp/projectsend-sftp-phase8-config-preview`; the user's saved preview
  data under `/tmp/projectsend-sftp-preview` was not touched. `SFTP_BROWSER_ORIGIN`
  can override the default loopback origin.
- `composer validate --strict --no-check-publish`: passed with the existing
  root-version notice. All 48 package PHP files passed syntax checks; both Node
  browser scripts and inline workflow JavaScript passed Node syntax checks.
  Premium strict audit reports zero findings at
  `/tmp/projectsend-sftp-premium-audit.json`. DESIGN.md token values and shape were
  retained and inspected; the designmd CLI is absent and was not downloaded,
  so its separate schema lint was not run.
- Added `tests/workflow-preview.php`, a loopback-only actual-host fixture with
  synthetic persisted staff/admin/no-file accounts, file metadata and generated
  dummy destination. Defaults to port 8766 and separate
  `/tmp/projectsend-sftp-workflow-preview`. It never loads `.env`, runs no worker,
  contacts no SSH host, and simulates progress only through a test-only
  CSRF-protected route. `?preview_role=admin|staff|empty` switches fixture identity.
  Both previews retain values until their temporary directories are cleared;
  restarting PHP alone does not clear them. Never deploy either preview router.
- No real database, `.env`, host source, caches, dependencies, keys, customer
  backups, object-store network or production SSH server changed. No migration
  was added/applied to the real DB. Real login/navigation in a browser,
  MySQL/PostgreSQL concurrent sessions, live object storage and representative
  large-file performance remain untested. Phase 9 retains deployment/trial scope.

## Phase 7 implementation and verification

- Recovery columns store the remote endpoint identity, individual attempt UUID,
  attempt count, execution token, lease deadline, and actor snapshot. These
  internal values are hidden from model JSON. Credentials remain solely in the
  encrypted destination row. Migration rollback preserves transfer records.
- `TransferRunner` owns execution, recovery, and atomic accounting;
  `TransferBackup` is the small queue adapter. A transaction claims a renewable
  120-second lease. Active owners exclude duplicate jobs. Heartbeats, completion,
  and failures require the execution token. Expired owners cannot renew. The
  job has no failed callback that could let a duplicate delivery alter an
  active worker. Killed workers retain running state until lease recovery.
- Before SFTP work, the attempt UUID and endpoint are persisted. Managed uploads
  reserve an immutable root, write into `writer/payload.part`, and write a ready
  receipt after verification but before rename. Recovery renames `writer` to
  `sealed`, then occupies the old writer path with a regular-file barrier.
  The original root name is retained. A stale worker cannot reserve it again
  or publish from its original path, including with an open remote file handle.
- Valid ready receipt + absent payload proves the attempt renamed its file.
  Recovery verifies actual remote length and streams its full SHA-256 before
  committing completion and one existing host download/audit entry. Matching
  bytes in an unrelated file are insufficient. A permanent non-publication
  receipt is written BEFORE partial cleanup, so repeated recovery cannot mistake
  that cleanup for a successful publication. Final files are never removed.
- Proven deliveries are accounted using recorded actor/file identity even if
  source/requester was later changed, removed, or disabled. Audit and completed
  state remain one transaction. New uploads require fresh requester, scope,
  availability, allowance, source identity, and enabled-destination checks.
  Recovery permits valid replacement credentials only for the same endpoint.
  Changed endpoint/pin, unknown objects, missing/changed final bytes, bad receipts,
  and inspection failures stay unresolved and never trigger a blind retry.
- `sftp:recover [TRANSFER_UUID]` queues reconciliation and repairs lost dispatch
  of queued work. `--retry` explicitly permits a fresh attempt after fencing and
  proving non-publication. Completed/active leases are skipped; sync/null drivers
  are refused. No automatic scheduler, queue retry loop, cancellation, or resume.
  Original phase 6 attempts that started without recoverable remote identity
  remain uncertain for operator investigation. Do not mix phase 6/7 workers.
- Small private remote manifests, receipts, and barrier names are intentionally
  retained without backup bytes or credentials. Do not sweep them while an old
  worker could resume. This is needed because a database lease alone cannot
  stop a paused process from issuing a later SFTP rename. It avoids remote
  agents, database locks held over a whole upload, and dependencies on a shared
  cache's lock-expiry behaviour.
- `php tests/host.php ../..` passes current host/queue cases, native maximum-
  attempts failure of a duplicate without altering its owner, command input and
  async-queue checks, active-lease skip, legacy refusal, and migration rollback.
- `php tests/host.php ../.. --sftp` passes local/S3-adapter-to-real-SFTP transfers
  plus publication recovery, same-size checksum corruption, repeated accounting,
  rewrapped encrypted login keys, later source/requester/destination ineligibility,
  partial cleanup, source rechecks on retry, distinct retry UUIDs, an unrelated
  identical final file, and lost dispatch. It uses an isolated SQLite file and
  independently booted PHP processes against the same generated-key SSH fixture.
  Tests pause a real uploading process, reject a concurrent duplicate, expire
  its lease, recover from another process, resume the stale process, and prove
  it cannot publish or alter recovered state. Explicit retry then delivers once.
  A separate real SIGKILL leaves running state without callbacks and also
  recovers/retries once. The server-side disconnect regression still passes.
- `php tests/host.php ../.. --sftp --workers-only` runs the focused independent-
  process tests without the preceding receipt cases. Shared host bootstrap was
  extracted for these fixtures; it always avoids `.env` and real services. Test
  disk configuration is shared so child processes read the same temporary data.
- `php tests/sftp.php ../../vendor/autoload.php` passes transport regressions,
  reservation of never-started attempts, stale path reuse refusal, empty backup
  recovery, corrupt receipt refusal, and repeated recovery of a ready-but-
  unpublished filename collision. The latter proves non-publication receipts
  prevent cleanup from becoming false delivery evidence.
- Package loading/configuration checks, configuration HTTP checks, PHP syntax
  checks, and strict Composer validation pass. The added console component is
  already installed; nothing was downloaded. README.md documents CLI operation,
  marker retention, leases, retry access checks, and upgrade/rollback boundaries.
- No UI changes or browser retest, live database/configuration changes, customer
  backups, real keys, or production SSH servers. Linux/OpenSSH and SQLite are
  tested; MySQL/PostgreSQL execution/concurrency, other SFTP server semantics,
  live object-store connectivity, and large-backup performance remain untested.
  The existing host download-limit concurrency race is unchanged.

## Phase 6 implementation and verification

- `sftp_transfers` stores a UUID per attempt, source identity, destination
  revision, display-name snapshots, status, progress, timestamps, safe errors,
  and the confirmed remote path. Source/destination internals are hidden from
  model JSON. Credentials stay only in the encrypted destination row. Host
  account/file IDs are retained after deletion; destination references become
  null on configuration deletion while their names remain in history.
- `RequestTransfer::submit` reloads the actor and uses `TransferSource::select`
  for current eligibility, library access, availability, and download allowance.
  It validates the source filename/size/checksum, locks the enabled destination,
  creates the record, and dispatches after commit. A rolled-back transaction
  queues nothing. Sync/null queue drivers are refused. Immediate broker errors
  leave a fixed safe error and a failed record when the row was committed.
- `TransferBackup` uses the fixed `sftp` queue, one attempt, a 3600-second timeout,
  and fail-on-timeout. Payloads contain only the transfer ID and queue policy.
  A conditional queued-to-running update admits execution. The worker clears
  host PermissionChecker and StaffLibraryScope instances, reloads requester and
  destination, and rechecks source access through `TransferSource::withStream`.
  Changed source identity or destination revision refuses execution.
- Progress writes occur at most once per second plus final bytes. A confirmed
  publication is followed by one database transaction that inserts the existing
  host `FileDownloaded` audit action with explicit requester and SFTP context,
  and marks completion. This preserves existing total/per-user allowance and
  uploader rules. The host's documented concurrency race for download limits
  remains; this phase does not reserve allowance during long uploads.
- Pre-transport refusals become failed. Anything unconfirmed after entering the
  transport becomes uncertain, including an audit-write failure after remote
  publication. A queue failure callback marks running work uncertain; completed
  attempts are not changed. Hard kills may leave running work without callbacks.
  There is no automatic retry, recovery, cancellation, or transfer HTTP route.
- Destination deletion refuses queued/running/uncertain transfers under the
  same destination lock used for submission. Completed/failed history survives
  deletion. Existing HTTP checks cover the refusal and history preservation.
  The preview fixture creates the new table without clearing saved destinations.
- Transport regression testing exposed callback exceptions leaving outstanding
  SFTP replies unread and causing intermittent staging-cleanup failure. Source
  and progress callbacks now record a safe failure, stop reading, let phpseclib
  drain replies and close its handle, then fail before publication. Tests cover
  read/progress failure, no final file, and staging cleanup. Network-loss cleanup
  remains best effort.
- `php tests/host.php ../..` passes with real database queue dispatch/execution,
  after-commit/rollback behaviour, ID-only payloads, sync/null refusal, invalid
  IDs/metadata, fresh requester/permission/scope checks, source/destination
  changes, spent allowance, missing storage, dispatch failure, failure callback,
  history preservation, and transfer migration rollback/reapply.
- `php tests/host.php ../.. --sftp` additionally passes actual queued SFTP from
  local storage and the real S3/Flysystem adapter with a mocked AWS handler.
  It checks exact remote bytes/hash, persisted progress, explicit audit actor,
  one accounting entry, exhausted allowance, sequential redelivery, atomic
  rollback on an injected audit-write failure, changed source bytes, and a real
  server disconnect leaving uncertainty without publication or automatic retry.
- `php tests/sftp.php ../../vendor/autoload.php` passes the transport regressions
  against the shared generated-key loopback fixture. Both real-SFTP commands
  require permission to bind loopback ports outside this environment's sandbox.
- `php tests/run.php ../../vendor/autoload.php`,
  `php tests/http.php ../../vendor/autoload.php`, PHP syntax checks under src,
  tests, and migrations, and `composer validate --strict --no-check-publish`
  pass. Composer retains its existing root-version notice. New direct bus/queue
  dependency declarations use already installed Laravel components; no downloads.
- No UI elements changed and browser checks were not repeated. No customer
  data, real keys, production servers, host configuration, or live database were
  used. PostgreSQL/MySQL execution, concurrent worker sessions, live object-store
  access, deployment, and representative large backups remain untested.

The dedicated worker's reservation/visibility timeout must exceed 3600 seconds.
The host's 90-second database retry_after is insufficient. No live worker was
started or configured. Phase 7 now supplies recovery; use README.md's commands to reproduce the
current isolated checks. Deployment remains phase 9.

## Phase 5 implementation and verification

- `SftpTransport` uses installed phpseclib 3.0.56. It verifies the host's SHA-256
  fingerprint before decrypting credentials or authenticating. It supports pins
  for any offered modern Ed25519, ECDSA, or RSA SHA-2 host key, including RSA on a
  server that prefers Ed25519. No legacy SHA-1 host signatures are enabled.
- The caller supplies a stream, safe filename, expected byte count, and SHA-256.
  Streaming does not seek, buffer a whole backup, or close the caller's stream.
  Source size/hash and remote length must match before publication. Progress is
  bytes sent, not success. Remote content is not reread for a checksum.
- A random staging directory reserves the temporary location. Permissions are
  explicitly verified as 0700 before sending data, because phpseclib's mkdir
  does not propagate chmod failure. Published files must have 0600 permissions.
  SFTP v3 rename refuses existing targets, including concurrent publications.
  No POSIX rename, resume, automatic retries, shell transfer, or new dependency.
- Exceptions have fixed safe messages without underlying exception chains.
  Cleanup and disconnect cannot replace the transfer result. Cleanup is best
  effort after a connection failure. Hidden staging can remain after crashes.
  A lost publication acknowledgement can leave a complete final file despite a
  failure result. Never delete final files or retry these cases blindly.
- `php tests/sftp.php ../../vendor/autoload.php` exercises a private unprivileged
  OpenSSH loopback fixture with generated keys. It verifies actual bytes/hash,
  passphrase-protected login, private permissions, progress, stream ownership,
  RSA pins, wrong pins before authentication, rejected login keys, disabled
  destinations, existing/concurrent filename collisions, checksum/size failures,
  path traversal, empty/non-seekable streams, missing/unwritable directories,
  cleanup, and a real server-side disconnect with no published partial backup.
- Existing `php tests/run.php ../../vendor/autoload.php`,
  `php tests/host.php ../..`, and `php tests/http.php ../../vendor/autoload.php`
  pass. `composer validate --strict --no-check-publish` passes with the existing
  root-version notice. PHP syntax checks under src and tests pass.
- README.md documents the transport contract, fixture command and requirements,
  supported host keys, checksum scope, cleanup, and uncertain publication.
  No UI changed, so browser checks were not repeated. No real credentials,
  customer files, production servers, host configuration, or database were used.
  Live storage-to-SFTP composition and representative large backups remain
  untested until later phases. This phase makes no queued jobs or HTTP uploads.
- Phase 6 must persist attempt identity and uncertain publication outcomes, add
  download accounting, and handle deletion/disable of destinations, changed
  source identity, and host permission/scope cache lifetimes between jobs.
  Phase 7 must reconcile uncertain publication and orphan staging before retries
  and handle worker crashes and duplicate jobs without duplicate accounting.

## Phase 4 implementation and verification

- The user requires separate administration and transfer surfaces. Configuration
  still needs `edit_settings`; new `sftp.transfer` allows all active, unexpired
  staff, subject to their existing ProjectSend file permissions and library scope.
- `TransferSelectionController` exposes JSON `/sftp/files` and
  `/sftp/destinations`. File choices include allowance state; destination choices
  include only enabled IDs/names. Both are authenticated, staff-only, and no-store.
- `TransferSource` uses real `ViewableFileScope`, `FileAvailability`, and
  `DownloadAllowance`. It reads the selected record's disk/path with `readStream`,
  passes the stream to a backend callback, and closes it even when the callback
  throws. It exposes no browser byte endpoint and makes no SFTP connection.
- `php tests/host.php ../..` now includes `tests/sources.php`, runs actual host
  migrations in isolated in-memory SQLite, and checks ordinary/scoped/no-file-
  permission staff, client/guest/inactive/expired refusal, safe destination data,
  file metadata/search/pagination, missing/forged/deleted IDs, scanning states,
  total and per-user caps, uploader exemptions, and availability changes after
  selection. Local reads and real S3-adapter reads with a mocked AWS command
  handler pass. Streams close on both success and consumer failure. Generic
  storage failures expose no exception chain or backend details.
- The host harness now exits nonzero on failures even after Laravel installs its
  console exception handler. Test schema/data errors cannot masquerade as passes.
- No new UI was added; browser tests were not repeated. The configuration-only
  preview remains available, but its synthetic identity is not a source fixture.
  Real object-store network access and large-backup performance remain phase 9.
- Phase 6 must rehydrate the requester and account for host permission-cache
  lifetimes between jobs, destination disable/delete, source identity changes,
  and download/audit accounting. No read is counted as a completed transfer yet.
- Existing package and configuration HTTP suites also pass, as do Composer
  validation and PHP syntax checks. No dependency downloads, host edits, or real
  database changes were required.

## Phase 3 implementation

Deletion was added to phase 3 at the user's request. Edit pages link to a
separate confirmation page naming the destination and focusing Keep destination.
The DELETE endpoint requires the administration gate, CSRF, explicit confirmation,
and a matching revision under a transaction lock. It removes the configuration
and encrypted credential row; remote servers and files are untouched. Phase 6
must account for pending transfers and retained history before extending this.

Deletion checks passed in `php tests/http.php ../../vendor/autoload.php` and
`node tests/browser.mjs ../../vendor/autoload.php`: confirmation GET is harmless,
cancellation preserves the record, unauthorised/invalid-CSRF/stale requests fail,
and confirmed deletion preserves other destinations. Firefox verified focus and
the narrow confirmation layout. Premium strict audit again reports no findings.
Screenshot review caught Blade interpreting `@{{` as an escaped expression in
connection labels. Both list and confirmation now render the connection as one
escaped value; HTTP/browser regression checks cover the displayed hostname.

- Routes use web/auth/staff/administration middleware. The controller uses
  `SaveDestination` for all writes and checks the gate before rendering.
- List/create/edit pages are package-owned Blade templates. This deliberately
  replaces the earlier React assumption because the release workspace has no
  frontend toolchain and independent package installation is useful. There are
  no host changes, asset publishing steps, or extra frontend dependencies.
- The existing navigation hook contributes an internal sidebar link. An Inertia
  409 response converts that visit to the package's full-page Blade response.
- Keys are selected as files, never displayed. Passphrases are masked and never
  repopulated. Non-secret form input survives validation errors. Revision tokens
  protect against stale edits without adding a database column.
- The local preview is `tests/preview.php`, bound to 127.0.0.1:8765. It uses fake
  authentication and generated test data only. It must never be a production
  entry point. README.md contains the browser and manual inspection procedure.

## Workspace facts

This is a release installation with PHP 8.4.1, Composer 2.8.12, Laravel 12, and
production dependencies under the parent application's `vendor/`. The package
now has its own Git repository; the parent's `.git` directory remains empty.
The usual development test tools are absent. Report package Git operations
separately from the parent release installation and do not claim a full host
test suite result. Use the package harness with the host autoloader as documented in
README.md. Do not load the real `.env` or connect to configured services in tests.
