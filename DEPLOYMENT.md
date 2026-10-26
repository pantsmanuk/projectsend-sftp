# Deploy and trial SFTP transfers

Use an owner-approved disposable staging installation first. This procedure
changes the host's dependencies, database, queue configuration, and process
supervisor. Package tests do not authorise changes to production. Keep the
accepted staff screens and normal host file permissions. Phases 1–8 and all
three UAT follow-ups are merged. This guide covers the current package, including
cancellation support; merged code alone does not establish deployment acceptance.

## Prepare the release

1. Record the ProjectSend release, PHP version, database engine, source storage
   backend, and package revision in the deployment ticket. The tested baseline
   is PHP 8.4 and Laravel 12. Test the actual database and SFTP server in staging.
2. Choose a synthetic backup with representative size and entropy, an eligible
   test staff account, and a dedicated incoming directory. Agree the time and
   resource budgets before the trial. Never use customer backups for fixtures.
3. Back up the host database, Composer files, and deployed release. Preserve
   `APP_KEY` and any previous encryption keys securely with the normal recovery
   process. Losing the key makes stored destination credentials unreadable.
4. Build the package into the host release using a pinned Composer repository
   revision and commit the resulting host lock file. The source repository is
   https://github.com/pantsmanuk/projectsend-sftp; do not deploy a floating branch.
   For a disposable path-based staging checkout, use the commands in
   [Install into a development application](README.md#install-into-a-development-application).
   Deploy with the host's normal locked `composer install` procedure. Do not
   run a broad dependency update on a production release.
5. Verify the locked release in staging with package checks from README.md,
   `php artisan package:discover`, and `php artisan migrate:status`. Never serve
   `tests/preview.php` or `tests/workflow-preview.php`. No frontend build or
   package asset publishing is required.

## Windows ACL setup and acceptance

Windows OpenSSH exposes synthetic Unix modes and does not enforce NTFS ACLs
through chmod. The explicit **Windows ACLs managed on server** destination
setting skips chmod for uploads and recovery. It leaves Linux/Unix enforcement
unchanged. Use it only after the server administrator verifies the ACLs below.
The package cannot verify those ACLs over SFTP.

1. Stop SFTP submissions and gracefully stop all dedicated workers before
   upgrading. Deploy the package and run the host's normal migration procedure.
   `2026_10_23_000000_add_windows_acl_policy_to_sftp_destinations` adds one boolean
   column, false by default. No host source change, dependency install, frontend
   build, or change to SSH authentication is required.
2. Inspect the incoming directory and existing descendants with elevated
   PowerShell `icacls`. Back up ACLs before modifying them. Restrict access to
   SYSTEM, Administrators, the dedicated SFTP account, and any explicitly
   trusted database service account. Remove inherited broad grants and review
   explicit grants and protected child ACLs too. Do not change the chroot root's
   ownership or grant the upload account write access to that root.

   For a dedicated incoming directory with the inherited ACLs shown in this
   investigation, the following is an example to review and run on Windows.
   It adds explicit trusted grants before removing inherited grants. Adapt the
   path/account and inspect every command's result. It does not remove existing
   explicit grants or repair protected descendants automatically.

   ```powershell
   $incoming = 'C:\srv\sftp\projectsend-upload\incoming'
   $uploadAccount = "$env:COMPUTERNAME\projectsend-upload"
   $aclBackup = Join-Path $env:TEMP 'projectsend-incoming-acls.txt'
   icacls $incoming /save $aclBackup /t /c
   icacls $incoming /grant:r '*S-1-5-18:(OI)(CI)(F)' '*S-1-5-32-544:(OI)(CI)(F)' "${uploadAccount}:(OI)(CI)(M)"
   icacls $incoming /inheritance:r
   icacls $incoming
   icacls $incoming /t /c
   ```

   SYSTEM and Administrators use well-known SIDs to avoid localised group names.
   The upload account needs inherited modify access to create, read, rename,
   and delete its staging files and recovery markers. Other local users must
   not be able to read backup bytes or modify manifests/receipts/barriers.
   Preserve the ACL backup securely. To restore this example's saved DACLs,
   run `icacls 'C:\srv\sftp\projectsend-upload' /restore $aclBackup /c` and
   inspect the result. Test ACL rollback on a disposable directory first.
3. Identify the database service identity with `Get-CimInstance Win32_Service`.
   SQL Server running as LocalSystem is covered by the SYSTEM grant. For a
   different service identity, explicitly grant and verify inherited read and
   directory traversal access. A same-volume rename retains staged ACLs, so a
   trusted database reader may also read staging files. Do not opt in if that
   account must be isolated from incomplete payloads; that requires a separate
   server-side publication/ACL design.
4. Create a disposable directory and small synthetic file through SFTP. Verify
   their actual Windows ACLs with `icacls`, including all inherited grants.
   Confirm an unrelated local account cannot read the file or alter the
   directory. Verify the upload account can rename files and directories and
   delete its disposable test data. Do not use a package attempt directory
   for manual probes. Test no-overwrite through the package in step 7; an
   interactive client's rename command may use an overwriting extension.
5. In destination administration, enable **Windows ACLs managed on server**,
   retaining the same host, port, username, SFTP directory and trusted host pin.
   The checkbox explicitly accepts server responsibility for staging privacy
   and completed-file readability. Save and restart the dedicated workers.
6. Retry the original chmod failure from the ProjectSend host root:

   ```sh
   sudo -u www-data php artisan sftp:recover TRANSFER_UUID --retry
   ```

   Use the installation's actual worker account. Recovery first fences the
   previous attempt and removes its known partial payload. It restarts from
   byte zero with a new attempt ID and accounts only confirmed delivery.
   Leave `.projectsend-*` markers intact. If a Windows sharing lock prevents
   fencing, recovery remains uncertain and queues no new upload. Terminate the
   old worker/session through normal operations, then repeat recovery.
7. Verify Completed status, the original final filename, exact size/hash, and
   one download/audit entry. Confirm SQL Server can read a synthetic backup
   with `RESTORE HEADERONLY`; do not perform a restore for this acceptance
   check. Seeing a filename in SSMS's browser proves listing only. Repeat
   with an existing destination filename and verify it is not overwritten.
   Exercise interruption/recovery on synthetic data against the actual Windows
   server before accepting the deployment.

The automated Linux fixtures emulate Windows permission replies, not NTFS ACLs
or sharing locks. These Windows acceptance steps remain operator-run. To roll
back, stop workers and resolve outstanding attempts first; disable affected
Windows destinations before dropping the policy column. Preserve transfer rows,
remote receipts/barriers, and the provisioned ACLs. Do not mix old and new workers.

## Configure the queue and dedicated worker

The web process submits to `config('queue.default')`, on the fixed `sftp` queue.
Recovery uses that same connection. A worker pointed at a different connection
will leave transfers queued. Use an asynchronous database, Redis, SQS, or
Beanstalkd connection. Do not change the default queue name to `sftp`.

1. Set reservation or visibility on that connection above 3,600 seconds.
   Use 3,660 seconds as a starting value. For this host's database connection,
   set `DB_QUEUE_RETRY_AFTER=3660` in the deployment configuration. Redis uses
   `REDIS_QUEUE_RETRY_AFTER`; Beanstalkd uses `BEANSTALKD_QUEUE_RETRY_AFTER`.
   For SQS, provision the `sftp` queue and set its visibility timeout on AWS.
   Verify the effective cached configuration in both web and worker releases.
   Keep endpoints and credentials out of verification output.
2. Keep ordinary workers on their existing queues. Start one dedicated SFTP
   worker initially. Increasing concurrency also multiplies memory, SSH
   sessions, storage reads, and bandwidth. The host does not reserve download
   allowance across concurrent deliveries.
3. Enable PHP CLI `pcntl` for Laravel's job timeout. Use a PHP memory limit as
   well as Laravel's `--memory`: Laravel checks its memory flag between jobs.
   The trial uses 128 MiB of PHP memory. Budget at least 256 MiB of process RAM
   initially, then measure the actual source adapter and server combination.
4. Supervise this command from the installed host root, using the same account,
   encryption keys, database, storage access, and configuration as the web app:

```sh
php -d memory_limit=128M artisan queue:work --queue=sftp --sleep=3 --tries=1 --timeout=3600 --memory=128 --max-time=7200
```

The job itself sets one attempt, a 3,600-second timeout, and fail-on-timeout.
Raising only the worker flag does not extend that job timeout. Backups that
cannot finish within one hour need a reviewed code/policy change and another
trial before rollout. The 30-second SFTP operation timeout is separate.
Source adapter connection/read timeouts must also be finite. Recovery can
read and hash the entire remote file, so measure its worst-case duration too.

For systemd, adapt this unit to the installed paths and service account. It is
an example to review, not an installer:

```ini
[Unit]
Description=ProjectSend SFTP worker
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=projectsend
WorkingDirectory=/srv/projectsend/current
ExecStart=/usr/bin/php -d memory_limit=128M artisan queue:work --queue=sftp --sleep=3 --tries=1 --timeout=3600 --memory=128 --max-time=7200
Restart=always
RestartSec=5
KillSignal=SIGTERM
KillMode=mixed
TimeoutStopSec=3700
UMask=0077

[Install]
WantedBy=multi-user.target
```

Use the equivalent Supervisor `stopwaitsecs=3700` and graceful termination
policy if that is the host's supervisor. Verify permissions and the CLI PHP
path. Keep logs access-controlled and use normal log rotation. Preserve the
host's environment-loading convention. Never put a destination private key or
passphrase in process arguments or supervisor configuration.

Changing reservation settings on a shared connection also delays redelivery
of crashed ordinary jobs. Record that operational tradeoff. The package does
not select a separate queue connection. A deployment that requires independent
reservation policy needs a reviewed connection-selection change first.

## Install and smoke-test in staging

1. Stop new SFTP submissions during maintenance and gracefully stop every SFTP
   worker. Disabling destinations stops new submissions but does not cancel
   an active upload. Let active jobs finish before switching releases. If one
   is killed, retain its records and remote markers for reconciliation.
2. Deploy the locked host release and run `php artisan migrate --force` using
   the host's normal reviewed migration process. All five package migrations
   must appear as applied. Rebuild host caches with the normal release procedure.
3. Start the dedicated worker. Verify the process and its queue connection
   directly through the supervisor. Confirm ordinary workers exclude `sftp`.
   Never mix workers from different package protocol versions.
4. Log in through the real browser login as an administrator. Open **SFTP
   destinations** through the sidebar. Configure the test destination there,
   using a host fingerprint verified through a trusted independent channel.
   Use a restricted SFTP account with access only to its incoming directory.
   Confirm server rename, permissions, no-overwrite, and recovery semantics
   against that server before accepting it.
   Published backups use 0644 so a database service running under a different
   account can read them. Give that service traversal access to each parent
   directory and read access under any server ACL or security policy. Restrict
   directory access to the intended accounts because 0644 allows other local
   accounts to read files they can reach. Staging directories remain 0700.
   For Windows, complete [Windows ACL setup and acceptance](#windows-acl-setup-and-acceptance)
   before explicitly enabling **Windows ACLs managed on server** instead.
5. Log in as ordinary eligible staff. Open **SFTP transfers** through the
   sidebar, select a permitted synthetic backup, and send it. Confirm completion
   and requester-only history. Confirm that staff cannot open destination
   administration and an unrelated requester cannot view the transfer URL.
   Repeat transfer navigation as an administrator: transfer pages must not offer
   a Destinations link. The separate sidebar entry must still open administration,
   and approved destinations must still be selectable when sending a backup.
6. Verify the final file's byte count and full SHA-256 on the destination and
   one host download/audit entry for the requester and transfer UUID. Verify
   the file remains absent under its final name until publication. Never
   overwrite a pre-existing final filename for a trial.
7. Use a disposable failed attempt to verify the
   [operator cancellation workflow](README.md#remove-an-unwanted-failed-transfer).
   For remote artifacts, stop every SFTP worker and terminate its SSH sessions
   before confirming `--workers-stopped`. Verify the entry disappears from
   history/detail/status, while source/final backups and accounting stay intact.
   Restart workers afterward. Never change timestamps or statuses in live history
   to manufacture a test case. Six-month history boundaries are covered by the
   isolated HTTP checks; older unresolved work remains visible.

## Run the repeatable large-data check

From this package directory, run the generated-data check on Linux with the
installed host dependencies and OpenSSH fixture prerequisites from README.md:

```sh
php tests/large-backup.php ../.. 256 local
php tests/large-backup.php ../.. 1024 local
php tests/large-backup.php ../.. 1024 s3-mock
```

The optional size is 256–65,536 MiB, default 512. Allow at least twice the
source size plus 256 MiB of free temporary disk. Each run creates fresh random
data in 1 MiB chunks, synthetic users, an isolated database and generated SSH
keys. A separate PHP process runs the actual Laravel database queue worker
under a 128 MiB PHP limit. It delivers to a loopback OpenSSH server. Normal
exit removes the fixture. An externally killed parent may leave a temporary
fixture and SSH process; inspect processes before removing that fixture.

The check fails unless remote size and SHA-256 match, status is completed,
exactly one delivery is accounted, no jobs remain, aggregate staff HTTP response
bodies stay below 256 KiB, PHP peak stays below 128 MiB, and peak RSS stays below
256 MiB. JSON output includes elapsed worker time,
throughput, PHP peak allocation, Linux process peak RSS, and HTTP body bytes.
Compare small and large runs to check that memory does not scale with payload
size. The S3 variant exercises the installed AWS/Flysystem adapter with a
file-backed mock response. It does not measure AWS network behaviour or SDK
HTTP buffering against a real object store. No real browser or login runs in
this harness; HTTP requests use synthetic authentication and real middleware.

## Measure the representative staging transfer

1. Use the approved synthetic backup size and actual configured source backend.
   Record source size/hash, database engine, package revision, worker count,
   timeout/visibility, and the agreed resource budgets. Use a new final filename.
2. Collect worker peak RSS and PHP memory, process CPU, temporary disk growth,
   source read traffic, destination write traffic, and elapsed time through your
   host monitoring. Include any SDK buffering or disk spooling. Keep source
   storage and destination limits under observation. Do not enable SSH packet
   logging or dump HTTP cookies, queue payloads, credentials, or backup bytes.
3. Submit through the real staff browser workflow. Inspect browser network
   requests during selection, send, progress, and history. They must carry
   metadata, form IDs, and status only. No backup download, upload body, or
   signed source URL may appear. The backend worker must perform the transfer.
4. Compare final size and full SHA-256 and the single audit entry as above.
   Check that memory and temporary disk use stay within the agreed limits and
   throughput permits the representative backup to finish below 3,600 seconds
   with operational headroom. Repeat at a second size to expose linear buffering.
5. In staging, exercise worker termination and `sftp:recover TRANSFER_UUID`,
   then explicit `--retry` only after reconciliation proves non-publication.
   Verify no false success, overwrite, or duplicate accounting. Retain the
   remote receipts and barriers. Run recovery of a published large file to
   measure full remote hash verification within the same worker timeout.
6. Record results and owner acceptance in the deployment ticket and HANDOVER.md.
   Mark Phase 9 accepted only after the chosen deployment, real login/sidebar,
   actual storage-to-server trial, and recovery checks pass. Loopback evidence
   alone does not accept an unspecified production environment.

## Monitor and recover

Alert on a stopped dedicated worker, oldest `queued` age above the agreed
dispatch target, any `uncertain` record, `running` records with an expired
`lease_expires_at`, and failed queue jobs on `sftp`. Also track failed transfer
counts, worker restarts, source/remote capacity, and throughput. A running
process alone does not prove jobs are progressing. Compare `bytes_sent` and
`updated_at` over time. The renewable lease is 120 seconds, so a stalled source
read may expire before the one-hour job timeout. Progress at 100% is still
unconfirmed until completion and accounting commit.

Inspect only UUID, status, byte counts, and timestamps in routine monitoring.
Staff status deliberately hides diagnostic internals; operators use controlled
database access and server monitoring. Do not export destination rows or
credential-bearing model attributes. There is no cross-user history screen.

For a stalled transfer, inspect the worker and connectivity first. Use
`php artisan sftp:recover TRANSFER_UUID` on the same release/queue configuration.
Active leases are skipped. A lost dispatch of never-started queued work can
start the original upload. Reconciliation of an existing attempt does not
upload a fresh copy unless `--retry` is explicitly supplied. Inspect the result
before permitting that retry. Never use `queue:retry`, edit transfer status,
delete queue rows, sweep `.projectsend-*.upload`, or remove a final file as a
shortcut. An uncertain outcome is not proof that no file was delivered.
For a Completed transfer, the successful attempt folder is removed after the
completion/accounting commit. Cleanup failure does not change delivery status
or accounting. Inspect credential-safe cleanup warnings and retry leftover
successful-attempt metadata with the same explicitly targeted
`php artisan sftp:recover TRANSFER_UUID`. It performs cleanup only for completed
records, even with `--retry`; routine scans without a UUID exclude completed
history. Interrupted cleanup can be retried without another upload. Original
endpoint identity must still match; cleanup never guesses a replacement path.
Earlier abandoned-attempt barriers remain protected, including after a later
attempt completes. Do not manually sweep them.

The completed-transfer cleanup itself needs no configuration change, dependency
install or frontend build. The later failed-transfer cancellation feature adds
`2026_10_26_000000_add_sftp_cancellation_support`; stop workers and apply it before
restarting. Preserve its UUID replay guards and attempt history on code rollback.
Verify a new completed transfer removes its successful attempt folder
on both Linux and Windows, and that a forced cleanup refusal leaves Completed
status, the final backup, and one accounting entry intact. Windows ACLs must
allow deletion of the upload account's metadata directories.

See [Recover or retry transfers](README.md#recover-or-retry-transfers).
For an unwanted Failed transfer, follow
[Remove an unwanted failed transfer](README.md#remove-an-unwanted-failed-transfer).
Remote artifact removal requires all SFTP workers and their SSH sessions to have
terminated, not just an expired lease. The cancellation command never removes
published backups or Completed records.

## Stop or roll back

1. Stop new submissions and gracefully stop SFTP workers before changing code
   or schema. Verify old processes have exited. Reconcile unresolved work with
   the current protocol and preserve records, keys, and remote markers.
2. Prefer reverting the host release while retaining the additive package
   tables, with the package routes disabled and SFTP workers stopped. Do not
   start old protocol workers against newer in-flight attempts.
3. Do not run a blanket host migration rollback. Package destination rollback
   destroys credentials; transfer rollback destroys pending work and history.
   Schema removal needs a separate reviewed backup and data-retention decision.
   Database rollback never undoes delivered remote files or host audit entries.
   Keep `sftp_cancelled_transfers` UUID guards and `previous_attempt_ids` when
   reverting code. Dropping them permits old form replays and loses cleanup
   identities. Keep submissions disabled on versions that do not enforce the
   cancellation guards. Retire old forms and queued jobs before a reviewed schema
   rollback.

The package cannot cancel an active remote transfer or retract a delivered
backup. Keep all retained recovery markers until operators have proved no old
worker can resume and agreed a separate retention procedure.
