# ProjectSend SFTP transfers

Private package for staff-initiated copies of backups from ProjectSend storage
to approved SFTP destinations. Backups do not pass through staff computers.
Database restoration stays in the existing operational workflow.

Phases 1–8 provide destination administration, authorised source streams, SFTP
transport, queued execution with recovery, and staff selection/progress/history
screens. Phase 9 adds the [deployment and trial procedure](DEPLOYMENT.md) and a
repeatable generated large-backup trial. Live deployment remains owner-operated.

The provisional Composer name is `inhouse/projectsend-sftp`. The package uses
the [BSD 3-Clause License](LICENSE), copyright 2026 GGP Systems Limited.
Repository hosting remains to be configured; the owner must choose a public
package name before publication.

## Run the package checks

Use PHP 8.4 or later and Laravel 12. From this directory, reuse the current
ProjectSend installation's dependencies:

```sh
php tests/run.php ../../vendor/autoload.php
php tests/host.php ../..
php tests/host.php ../.. --sftp
php tests/http.php ../../vendor/autoload.php
php tests/sftp.php ../../vendor/autoload.php
php tests/large-backup.php ../.. 256 local
php tests/large-backup.php ../.. 1024 local
php tests/large-backup.php ../.. 1024 s3-mock
composer validate --strict --no-check-publish
php -l src/SftpTransferServiceProvider.php
php -l src/Destination.php
php -l src/SaveDestination.php
php -l src/SftpTransport.php
php -l src/SftpTransferException.php
php -l tests/sftp.php
php -l src/TransferController.php
php -l tests/workflow.php
php -l tests/workflow-preview.php
php -l tests/large-backup.php
php -l src/Transfer.php
php -l src/TransferStatus.php
php -l src/RequestTransfer.php
php -l src/TransferBackup.php
php -l src/TransferRunner.php
php -l src/RecoverTransfers.php
php -l tests/transfers.php
php -l tests/queued-sftp.php
php -l tests/cleanup-queued-sftp.php
php -l tests/cleanup-sftp.php
php -l tests/sftp-fixture.php
php -l tests/windows-sftp.php
php -l tests/windows-queued-sftp.php
php -l database/migrations/2026_10_23_000000_add_windows_acl_policy_to_sftp_destinations.php
php -l tests/host-bootstrap.php
php -l tests/recovery-worker.php
php -l tests/recovery-sftp.php
php -l tests/recovery-workers.php
php -l database/migrations/2026_10_21_000002_add_sftp_recovery_state.php
php -l database/migrations/2026_10_21_000001_create_sftp_transfers_table.php
php -l database/migrations/2026_10_21_000000_create_sftp_destinations_table.php
php -l tests/run.php
php -l tests/destinations.php
php -l tests/host.php
php -l src/DestinationController.php
php -l routes/web.php
php -l tests/http.php
php -l tests/fixture.php
php -l tests/preview.php
```

Expected result: package loading, destination storage, access, validation,
credential protection, migration rollback, navigation, HTTP forms, source streams, and host
integration checks pass. The HTTP suite exercises the web middleware, including
real CSRF checks, uploads, forbidden requests, stale forms, and secret handling.
The standalone harness creates and removes a temporary Laravel application.
The host check boots the actual ProjectSend providers with an in-memory SQLite
database and temporary caches and storage. Only isolated test jobs run. Neither test
loads ProjectSend's `.env`, accesses its database, or changes its caches.
It runs the real host migrations against its isolated database and checks
the real file scopes, download limits, and staff picker endpoints. It also tests
staff review/submission/history through host HTTP middleware, real CSRF checks,
forged IDs, requester isolation, duplicate submissions and safe progress JSON. Its S3 test
uses the installed AWS/Flysystem adapter with a mocked AWS command handler;
no object-store network request is made. Live S3/GCS connectivity is not tested.
The host check also exercises real database queue dispatch and execution-time
refusals without a network connection. Add `--sftp` to exercise queued transfers
from local storage and the mocked S3 adapter to the local SSH server, including
progress, accounting rollback, changed source bytes, interruption, recovery,
and concurrent independent PHP workers. It includes a staff HTTP submission
through to real SFTP delivery, matching bytes and one audit entry. Add `--workers-only` alongside `--sftp`
to focus on lease takeover, stale-worker resumption, and hard-kill recovery. The `--sftp` fixture uses its own
temporary SQLite file so those workers can share test state; the ordinary host
check uses SQLite in memory.

The database harnesses require the SQLite extension. The SFTP tests require
OpenSSH `sshd`, `ssh-keygen`, and `sftp-server`, an ordinary non-root account,
and permission to bind a loopback port. Python 3 is also required for the
Windows-permission protocol relay. It generates temporary host/login keys
and starts its own unprivileged SSH server on 127.0.0.1. It changes no account
keys or system SSH configuration and connects to no external server. Its files
and server are removed on normal exit. Interruption testing can take a minute
because it exercises a real connection timeout.

Both SFTP suites also exercise explicit Windows ACL policy against a loopback
relay over real OpenSSH. The relay accepts chmod without changing permission
bits, or rejects chmod/rename on demand. It reproduces the observed 0600 mode
mismatch, checks strict Unix failures, publication, recovery fencing, retry,
collision protection, and HTTP-to-worker accounting. It does not emulate NTFS
ACLs, Windows sharing locks, or establish live Windows acceptance. See
[Windows destinations](#windows-destinations) for the server-side checks.

The Phase 9 large-backup commands require temporary space for two full copies
plus 256 MiB. They run a separate 128 MiB PHP queue worker and report peak memory,
elapsed time, throughput, exact-byte/hash verification, accounting, and metadata
HTTP response size. The S3 variant uses a file-backed mock AWS response, without
object-store network access. See [Run the repeatable large-data check](DEPLOYMENT.md#run-the-repeatable-large-data-check)
for limits and the separate live-environment acceptance procedure.

To test a separate package checkout without ProjectSend:

```sh
composer install
composer test
```

Laravel is a development dependency for this standalone harness. Runtime
dependencies include Laravel database, encryption, validation, and support
components. phpseclib validates SSH private keys in phase 2 and supplies SFTP
transport in phase 5. The current host already has these dependencies installed.

## Inspect the screens locally

For staff selection, review, progress and history, start the actual-host fixture
from this package directory:

```sh
php -S 127.0.0.1:8766 tests/workflow-preview.php
```

Open `http://127.0.0.1:8766/sftp/transfers`. It supplies synthetic staff, files
and a generated-key dummy loopback destination. **Send backup queues a fixture
record only; this preview runs no worker and makes no SFTP connection.** Browser
tests simulate progress through a CSRF-protected fixture-only endpoint. Confirmed
real SFTP delivery is exercised separately by `tests/host.php ../.. --sftp`.

Use `?preview_role=admin` to inspect destination administration, or
`?preview_role=staff` to return to ordinary staff. `?preview_role=empty` shows a
staff account without file permission. The choice persists in a fixture cookie.
State and generated keys live under `/tmp/projectsend-sftp-workflow-preview`,
separate from existing configuration preview values. Set `SFTP_WORKFLOW_DIRECTORY`
to a new temporary directory for a fresh workflow session; restarting the server
does not clear it. Use generated test keys only. Do not deploy this test router.
It bypasses login for local inspection and must stay bound to loopback.

The older configuration-only fixture remains available:


```sh
php -S 127.0.0.1:8765 tests/preview.php
```

Open `http://127.0.0.1:8765/system/settings/sftp`. The preview supplies a synthetic
administrator and stores test data under `/tmp/projectsend-sftp-preview`.
Use test SSH keys only. This entry point refuses non-local requests and runs only
under PHP's development server; do not deploy or expose it as an application.
Stop the server with Ctrl+C. The real installation is not changed.

For automated browser checks, use Node 22 or later and Firefox. In another
terminal, create a temporary Firefox profile directory and run:

```sh
mkdir -p /tmp/projectsend-sftp-browser
firefox --headless --no-remote --profile /tmp/projectsend-sftp-browser --remote-debugging-port=9222 about:blank
```

With both local processes running, run:

```sh
node tests/browser.mjs ../../vendor/autoload.php
node tests/workflow-browser.mjs
```

The browser script uses Node's native WebSocket and Firefox WebDriver BiDi.
It installs no npm dependencies. Run the two browser scripts sequentially.
The configuration script exercises a generated test key upload,
create/edit/enable/disable, keyboard access, secret visibility, unsaved changes,
validation recovery, and narrow light/dark layouts. Screenshots are written to
`/tmp/projectsend-sftp-*.png`. The workflow script exercises search/clear,
review validation, native selection, submission, simulated progress, uncertain
and failed outcomes, session/network failure and narrow themes. Its screenshots
use `/tmp/projectsend-sftp-workflow-*.png`. The platform owns the native select
popup; headless screenshots may omit that OS window. Stop Firefox after testing.
For configuration regressions without touching saved preview values, start its
server with `SFTP_TEST_DIRECTORY=/tmp/projectsend-sftp-config-check`.
`SFTP_BROWSER_ORIGIN` and `SFTP_WORKFLOW_ORIGIN` can override the scripts' default
loopback origins if using different ports.

For a manual inspection, check labels, navigation, narrow layout, validation
errors, and credential replacement. Saving does not connect to a destination.

To remove a destination, open **Edit**, choose **Delete destination**, and review
the separate confirmation page. **Keep destination** cancels. Confirmed deletion
removes that destination's database row, including its encrypted private key and
passphrase. The remote server, its authorised keys, and its files are unaffected.
Existing database backups and copies of key files are not erased. Disable a
destination instead if you want to retain its configuration.
Deletion is refused while the destination has queued, running, or uncertain
transfers. Completed and failed transfer records retain their destination name
after configuration deletion; their destination reference becomes null.

## Install into a development application

Use a disposable ProjectSend development checkout. Do not run dependency updates
against a production release installation.

1. Place or link this directory at `packages/projectsend-sftp` in that checkout.
2. From the application's root, run:

```sh
composer config repositories.projectsend-sftp '{"type":"path","url":"packages/projectsend-sftp","options":{"symlink":true,"versions":{"inhouse/projectsend-sftp":"dev-main"}}}'
composer require inhouse/projectsend-sftp:dev-main
php artisan package:discover
```

Composer links the package under `vendor/inhouse/projectsend-sftp`. Laravel
discovers its provider automatically. No `.env` or `bootstrap/providers.php`
changes are required. These commands intentionally change the development
application's `composer.json` and lock file.

The provider registers destination, transfer, and recovery-state migrations. Run the application's
normal `php artisan migrate` step when installing this phase into a development
checkout. Rolling back the destination migration deletes its configuration and
credentials. Rolling back the transfer migration removes its history and pending
execution records, but does not remove remote files or host audit entries. Stop
SFTP workers before a rollback. No migration has been run against this
workspace's real database.

After installation, eligible staff see **More → SFTP transfers** in the
ProjectSend sidebar. Staff with `edit_settings` also see **SFTP destinations**.
Direct access is `/sftp/transfers` or `/system/settings/sftp`, respectively. The package uses
Blade pages with bundled inline styles and behaviour; no npm build, published
assets, or `.env` changes are needed. Inertia navigation performs a full-page
same-tab handoff, and the package header links back to ProjectSend. Host source
and the existing Settings submenu are untouched.

## Staff selection and source access

Destination administration requires `edit_settings`. The separate transfer
workflow is available to all active, unexpired staff through `sftp.transfer`.
That eligibility grants no additional ProjectSend file access. Clients and guests
cannot use the transfer workflow.

These authenticated, session-based JSON picker endpoints remain available:

| Endpoint | Result |
| --- | --- |
| `GET /sftp/destinations` | Enabled destination IDs and names only. No hostnames, paths, fingerprints, credential-presence flags, or secrets. |
| `GET /sftp/files?q=backup&page=1` | Staff-visible, available file metadata, 25 rows per page, newest IDs first. Optional `q` searches display and original filenames. |

File rows contain `id`, `name`, `original_name`, `size`, `selectable`, and
`download_allowance`. Pagination metadata contains `current_page`, `last_page`,
`per_page`, and `total`. Download-limited files remain visible with
`selectable: false`, matching the host's distinction between seeing a file and
taking a copy. Pending, quarantined, known-missing, and deleted files are excluded.
No file bytes, storage paths, disk names, or signed URLs are returned.

`TransferSource::withStream($actor, $fileId, $consume)` is the internal backend
read boundary. It rechecks transfer eligibility, the current ProjectSend library
scope, file availability, and download allowance. The callback receives a
readable stream and the exact selected `File` model. The method closes the
stream after success or failure. The caller cannot supply a storage path or disk.
Unexpected missing objects or storage errors fail with a generic source-read
error rather than exposing backend details. There is no HTTP source-byte route.

Selection and source reading do not log a completed download, spend an allowance,
or open an SFTP connection. The queued job accounts for confirmed delivery and
rechecks the requester, file, and enabled destination when it executes. Do not
bind `TransferSource` as a singleton across requests or queue jobs.

These backends require the actual ProjectSend host file models and services.
The configuration-only local preview uses a synthetic identity and is not a
fixture for the new file-selection endpoints. Run `php tests/host.php ../..` to
exercise them against the isolated real-host application.

## Backend SFTP transport

`SftpTransport::upload($destination, $stream, $filename, $expectedBytes,
$expectedSha256, $progress = null)` consumes a readable stream from its current
position. The caller retains ownership of the stream. It returns `path`, `bytes`,
and `sha256` after successful publication. Progress reports bytes sent, before
publication, and does not mean a transfer has completed.

The phase 6 worker calls it inside `TransferSource::withStream`, using the
selected file's byte count and SHA-256 checksum. Missing or invalid checksums
fail rather than bypass verification. The transport itself performs no database
writes; the queued job owns progress, completion, and accounting.

The transport verifies the pinned host fingerprint before decrypting login
credentials or authenticating. It tries the server's offered modern Ed25519,
ECDSA, or RSA SHA-2 host-key algorithms so a pin can identify any of those keys.
Legacy SHA-1 RSA signatures and DSA are unsupported. Each network operation has
a 30-second timeout; deployment still needs a suitable overall worker timeout.

The incoming directory must already exist and be writable by the SFTP account.
Filenames must be a single safe path segment of at most 255 bytes. Uploads stream
into a randomly named private `.projectsend-*.upload` directory. The transport
checks the source byte count and SHA-256 and the remote file length, sets file
permissions to 0644, then uses SFTP v3 rename to publish without overwriting.
The upload account retains write access; database service accounts can read the
published backup if directory traversal and server access policies permit it.
Other local accounts with directory access can also read it. Staging directories
remain 0700. These are the default Linux/Unix rules. An explicitly opted-in
Windows destination instead relies on server-managed ACLs as described below.
Existing delivered files are not changed by a package update.
It never uses the overwriting POSIX rename extension. The destination server
must honour SFTP v3 no-overwrite semantics. Existing targets, including targets
created during upload, cause failure. Uploads are not resumed or retried.

Memory use is bounded by stream chunks; no complete backup is buffered or saved
locally by the transport. SHA-256 verifies the bytes read from the source; SSH
provides transport integrity. A normal upload does not reread remote disk contents. Recovery streams the
remote final file to verify its full SHA-256 before recording an uncertain
publication as delivered. The fixture independently checks remote bytes/hash.

Failures return fixed credential-safe exceptions without an underlying exception
chain. Unmanaged transport calls remove staging when the connection permits. Queued
calls retain recovery evidence until publication and accounting commit, then
remove their successful attempt metadata. Unconfirmed attempts retain evidence
and let reconciliation remove abandoned payloads.
A crash or lost connection can leave hidden staging artifacts. A lost rename
acknowledgement can leave a complete final file even though the call failed.
The transport never removes a final file or blindly retries an uncertain result.
The queued job records uncertain outcomes with automatic retries disabled.
Recovery reconciles these outcomes before an explicit retry. Laravel automatic
job retries remain disabled.

## Queued execution

`RequestTransfer::submit($actor, $fileId, $destinationId)` is the internal
submission boundary. It reloads the actor, checks source access and allowance,
validates the original filename and checksum, and locks the enabled destination
while creating a transfer. It snapshots source disk/path, size, checksum, and
filename, plus the destination revision and display names. It stores no copy of
the destination credentials. A UUID identifies the transfer; each execution that
may upload gets a separate persisted attempt UUID.

Dispatch occurs after the database transaction commits. Job payloads contain the
transfer ID, explicit-retry flag, and queue policy only. An existing asynchronous database, Redis,
SQS, or Beanstalkd connection is required; sync and null drivers are refused.
The fixed queue name is `sftp`, with one allowed attempt and a 3600-second job
timeout. A separate worker must listen to that queue. Queue reservation or
visibility timeouts must exceed the job timeout; the host's default 90-second
database `retry_after` is insufficient. No host configuration or `.env` has been
changed by the package. Follow [Configure the queue and dedicated worker](DEPLOYMENT.md#configure-the-queue-and-dedicated-worker)
before enabling transfers in an installed application.

The worker first claims a 120-second renewable database lease with a unique
execution token. Active leases exclude duplicate workers. Progress, completion,
and failure updates require that token; expired owners cannot renew it. The
worker clears the host permission and library-scope caches and reloads the
requester. It checks current account eligibility, file access, scanning state,
allowance, and enabled destination. Changed source identity or destination
revision refuses execution. Settings and key edits count as a changed revision.
An in-flight upload uses the destination values loaded when execution starts;
disabling a destination prevents queued work, but does not cancel an active
SSH session.

Transfer states are:

| State | Meaning |
| --- | --- |
| `queued` | Accepted and awaiting worker execution. |
| `running` | A worker claimed the attempt; progress is bytes sent, not confirmed delivery. |
| `completed` | Remote publication was acknowledged and completion plus download accounting committed. |
| `failed` | Preparation failed, or reconciliation fenced an unpublished attempt and removed its partial payload. Explicit retry still rechecks access. |
| `uncertain` | Execution or accounting did not confirm completion. Reconciliation is required. A killed process can remain `running` until recovery claims its expired lease. |

Progress is persisted at most once per second, plus the final byte count.
Confirmed completion and the host's `FileDownloaded` entry commit in the same
database transaction. The entry explicitly names the requesting staff member
and includes `delivery: sftp` and the transfer ID. This preserves the host's
download counts, per-user limits, and uploader exemption without new audit
actions. Source reads and unconfirmed uploads do not spend allowance. The host's
existing concurrency race for download limits also applies here; this phase
does not reserve allowance across concurrent transfers or browser downloads.

Internal storage paths, checksums, destination revisions, and remote paths are
hidden from model JSON. Staff detail/status responses use a separate explicit
whitelist and enforce requester ownership. Use the workflow preview for staff
screens; the older configuration preview remains configuration-only.

## Windows destinations

In destination administration, **Windows ACLs managed on server** explicitly
delegates access control to the Windows administrator. It is off for every new
and existing destination until an administrator enables it. There is no server
banner detection or automatic fallback after a permission failure.

With the setting enabled, upload and recovery send no chmod requests, including
implicit chmod calls during directory creation, and do not compare Unix mode
bits. Windows OpenSSH chmod only changes the read-only attribute, not NTFS ACLs;
a successful chmod 0644 can still report 0600. SFTP v3 cannot inspect or enforce
Windows ACLs. The setting is an administrator acknowledgement of that limit,
not proof that a destination is secure. See the
[Windows ACL deployment procedure](DEPLOYMENT.md#windows-acl-setup-and-acceptance).

Before enabling it, restrict the incoming directory and its descendants to the
dedicated upload account, SYSTEM, Administrators, and any explicitly trusted
database service account. Remove broad inherited and explicit grants such as
Users, Authenticated Users, and Everyone. Ensure newly created staging/writer
directories, manifests, receipts, and payloads inherit those restrictions.
Completed files keep their staged ACLs when published; database read access
must already be provisioned. A trusted database account may therefore also read
staged bytes. Systems requiring database access only after publication need a
server-managed ACL workflow and are not covered by this setting.

Use the SFTP-visible path, such as `/incoming`, rather than a Windows drive path.
Windows destinations reject NTFS alternate-stream syntax, reserved device
names, trailing dots/spaces, and other invalid Windows filenames. Ordinary
Linux/Unix filename rules remain unchanged.

Size/hash checks, pinned SSH host verification, no-overwrite publication,
leases, receipts, fencing, and accounting remain in force. Recovery must still
move an old writer out of the way before retrying. Windows may refuse that move
while an old session holds a file open. Let the old worker/session terminate,
then repeat checked recovery; do not delete markers or edit transfer status.

Changing this policy leaves endpoint identity unchanged, so a failed chmod
attempt can be reconciled and retried against the same approved endpoint.
It does change the destination revision: already queued attempts that have
never started are refused after a configuration change and need a fresh request.

## Recover or retry transfers

From an installed development application, queue reconciliation for one transfer:

```sh
php artisan sftp:recover TRANSFER_UUID
```

The command queues work on `sftp`; it performs no SFTP work in the command
process. A worker must be listening. Active leases are skipped. An explicitly
selected completed transfer receives staging cleanup only; it is never uploaded
or accounted again, even with `--retry`. Omit the UUID to scan queued, uncertain,
and expired running records, excluding completed history,
including committed records whose dispatch was lost. A newly queued transfer
that never started runs its original authorised upload; an existing attempt is
reconciled first. No scheduler is registered.
Run recovery explicitly or arrange an operator-controlled schedule at deployment.

Reconciliation alone does not upload another copy for an existing attempt. To
permit a fresh attempt after proving the previous writer cannot publish, run:

```sh
php artisan sftp:recover TRANSFER_UUID --retry
```

With `--retry`, failed records are also eligible. Retrying rechecks the original
requester's current eligibility, source access, availability, allowance, and
unchanged source identity. A disabled destination refuses new uploads. Each
retry uses a new remote attempt directory; uploads restart from byte zero.
There is no cancellation or resume feature. Avoid direct queue replay or manual
status edits; use recovery so the remote checks always precede a retry.

Before the first SFTP operation, the database stores the attempt UUID and approved
host, port, account, incoming directory, and host fingerprint. Recovery requires
the current destination to match that endpoint. Credential replacement is allowed
for recovery against the same endpoint. A changed host/path/fingerprint remains
unresolved until the original approved endpoint is restored; recovery never
silently contacts the replacement. No copy of credentials enters history or jobs.

Managed uploads reserve `.projectsend-ATTEMPT_UUID.upload` with a manifest and a
private `writer` subdirectory. A receipt is written only after source size/hash,
remote length, and the configured permission policy pass, immediately before
publishing by rename. For Windows ACL destinations, the administrator's
server-side ACL provisioning replaces Unix permission enforcement.
Recovery moves `writer` to `sealed` and permanently occupies the old `writer`
path with a small regular file. That barrier stops a stale worker's remaining
writes or rename from publishing, including a worker paused before creation.
An active handle may still write to an isolated/unlinked payload, but cannot
publish it at the original path or change the recovered database record.

A valid receipt plus a missing staged payload proves that this attempt renamed
its file. Recovery then verifies the final file's size and full SHA-256 and commits
completion plus one download entry atomically. An unrelated existing file is
never claimed as this delivery, even if its contents match. Missing/changed
files, invalid receipts, unexpected objects, and failed inspections remain
unresolved. Final files are never deleted or overwritten.

If the attempt did not publish, recovery records a permanent non-publication
receipt before removing its known isolated partial payload. That receipt prevents
a later inspection from mistaking cleanup for a successful rename. These earlier
abandoned attempts retain small manifests, receipts, and barrier directories,
even if a newer attempt eventually completes. **Do not sweep those barriers
while any old worker could still resume.** Removing the original reserved names
would remove the protection against a stale worker. Retained markers contain no
backup bytes or credentials.

After completion and accounting commit, the worker removes the successful
attempt's own `.projectsend-ATTEMPT_UUID.upload` directory. This also applies to
an already-published attempt completed through reconciliation. Cleanup validates
the manifest and the entire directory shape before deleting only known metadata;
it never recursively removes unknown files, staged payloads, or symlinks, and
never reads, deletes, or overwrites the published backup. Earlier abandoned
attempt barriers are not part of that cleanup.

Cleanup is best effort and runs after the outermost database commit. Connection,
permission, sharing-lock or inspection failures leave delivery Completed with
its accounting unchanged. A credential-safe warning contains only the transfer
reference. If the worker exits after committing or cleanup stops partway through,
use `php artisan sftp:recover TRANSFER_UUID` for that completed record to retry
cleanup on the dedicated queue. It also cleans successful attempt folders left
by earlier releases when their recorded identity is available. Repeating cleanup
converges to an absent directory without recreating it or sending another backup.
There is no automatic history sweep. A changed/deleted destination or missing
legacy attempt identity cannot be guessed at safely.

Reconciliation accounts for a proven delivery even if the requester/file was
later deleted or disabled or its allowance is now spent: those bytes were
already delivered. It uses recorded actor/file identity, and never opens the
source for that accounting step. New uploads always require fresh access checks.
A crash during accounting rolls back the log and completed state together, so
another reconciliation cannot duplicate the entry.

Older phase 6 records that started without remote attempt identity cannot
be guessed at safely. Recovery leaves them uncertain for operator investigation.
Older queued records that never started can use the new protocol. Stop old
workers and migrate before using the new code; a mixed phase 6/7 worker fleet
cannot provide the new protocol's guarantees. Rollback of recovery columns must
also happen only with workers stopped and unresolved work dealt with.

## Send and track a backup

Staff use **SFTP transfers** in navigation, or `/sftp/transfers`. Choose **Send
backup**, search the permitted file library, choose a backup, then review its
approved destination and select **Send backup**. No backup bytes pass through the
browser. An asynchronous dedicated SFTP worker is required as described above.
Configuration remains at `/system/settings/sftp`, restricted to staff with the
existing `edit_settings` permission.

Delivery status shows an elapsed hours:minutes:seconds timer while execution is
running, then a fixed duration on completion. My transfers retains completed
durations. Timing excludes the initial queue wait and includes final checks and
any retries or reconciliation after the first start. Live timing pauses with
status updates on errors; Refresh retrieves the current recorded duration.

My transfers lists only the current requester's records, including when that
requester is an administrator. Detail pages show bytes sent, delivery status and
a reference for support. Pages are not cached. Active status polls every five
seconds, pauses in hidden tabs, and stops after 180 requests, an error, a session
change or a terminal state. **Refresh status** always works without JavaScript.
100% sent is not confirmation; only **Completed** confirms delivery.

Failed or unconfirmed transfers require an administrator to use the checked
recovery procedure above before sending again. The staff screens expose no retry
button. Repeating the same submitted form returns the same transfer without
queueing again; opening a fresh review creates a new request. Neither flow
restores a database or replaces an existing remote file.

## Obtain the trusted host fingerprint

**Trusted host fingerprint** identifies the destination database server's
SSH/SFTP host key. It is separate from the private key used to log in.

On the destination server, through an already trusted SSH session or its console,
run:

```sh
sudo ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub -E sha256
```

The output has this format; the fingerprint below is abbreviated:

```text
256 SHA256:AbCdEf... server-name (ED25519)
```

Copy only the complete `SHA256:...` value into **Trusted host fingerprint**.
Do not copy the key size, server name, or key type.

If the Ed25519 public key file does not exist, list available host public keys:

```sh
ls /etc/ssh/ssh_host_*_key.pub
```

Run the same `ssh-keygen` command against the appropriate public key file.
Use the server's host key, not an account's login key or `authorized_keys` file.
Only the public host key is needed; do not copy the server's private host key.

Obtain the value from the server itself or its administrator through a trusted
channel. `ssh-keyscan` alone reports what a network endpoint presents; it does
not establish that you reached the correct server. If a fingerprint changes,
verify the reason with the server administrator before replacing the saved value.

Saving configuration validates and stores the fingerprint without connecting.
The SFTP transport checks it against the remote host key before authentication.

## Destination configuration contract

`ProjectSendSftp\SaveDestination::save($actor, $input, $destination = null)` is
the configuration write boundary for the phase 3 controller. It checks the
`sftp.manage-destinations` gate before validating or writing. That gate requires
an active, unexpired staff account with the existing `edit_settings` permission.
Use the same gate before listing or displaying configuration.

Creation requires a name, hostname or IP address, username, absolute directory,
trusted OpenSSH SHA-256 host fingerprint, and SSH private key. The port defaults
to 22 and destinations start disabled. An optional passphrase unlocks the key.
Updates accept a subset of these fields. Unknown fields are discarded.

Blank or omitted credential fields preserve the saved pair. A replacement key
uses its submitted passphrase, or no passphrase when blank or omitted. This
allows rotation from an encrypted key to an unencrypted key without retaining
the old passphrase. Invalid keys or mismatched passphrases leave saved data
unchanged. Updates reload the destination under a database transaction lock so
stale edits do not overwrite a rotated credential pair.
The edit form also submits a revision token. A changed destination rejects a
stale form before writing, and asks the administrator to review their values.

The model encrypts keys and passphrases with the host application's existing
encryption key. Preserve that key with database backups. Normal model responses
hide both secrets and expose `has_private_key` and `has_private_key_passphrase`
flags. The provider prevents credential input flashing and passphrase trimming.
It emits no credential logs. Transfer queue payloads contain record IDs rather
than credential values.

Saving configuration validates it locally. It does not establish connectivity
or verify the remote server's identity. The transport performs that verification
when called; never treat a syntactically valid fingerprint as proof that a server
is trusted. Staff eligibility and source access have separate checks above.

Both loading tests exercise Laravel discovery with fixture installed-package
metadata. They do not perform a Composer installation. The host check boots the
actual application, its providers, and the real navigation hook. The separate
HTTP suite and browser preview use a synthetic identity, not a real ProjectSend
login. Verification results are recorded in [HANDOVER.md](HANDOVER.md).

Keep this directory independently distributable. Connect this package repository
to a separate private remote when repository hosting is arranged. See [PLAN.md](PLAN.md) for scope
and [HANDOVER.md](HANDOVER.md) for the next task.
