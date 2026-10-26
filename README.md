# ProjectSend SFTP transfers

Copy backups from ProjectSend storage to approved SFTP destinations without
passing backup data through staff computers. Staff select a file and destination,
then follow delivery progress and history. Database restoration remains a
separate operation.

Requires PHP 8.4 or later, Laravel 12, and a compatible ProjectSend installation.
Transfers require an asynchronous queue and a dedicated worker.

## Installation

Follow the [deployment guide](DEPLOYMENT.md) to install the package, apply
migrations, configure workers, and verify your storage and SFTP servers in
staging before production use. The guide also covers Windows ACLs and rollback.
No frontend build or package asset publishing is required.

The Composer package is `inhouse/projectsend-sftp`. Source is available at
[pantsmanuk/projectsend-sftp](https://github.com/pantsmanuk/projectsend-sftp).

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

The provider registers five migrations: destinations, transfers, recovery state,
Windows ACL policy, and cancellation support. Run the application's normal
`php artisan migrate` step when installing or upgrading the package. Rolling
back the destination migration deletes its configuration and credentials. Rolling back the transfer migration removes its history and pending
execution records, but does not remove remote files or host audit entries. Stop
SFTP workers before a rollback. Preserve cancellation UUID guards and previous
attempt IDs. Keep submissions disabled on older code that does not enforce those
guards; see [Stop or roll back](DEPLOYMENT.md#stop-or-roll-back).

After installation, eligible staff see **More → SFTP transfers** in the
ProjectSend sidebar. Staff with `edit_settings` also see **SFTP destinations**.
Direct access is `/sftp/transfers` or `/system/settings/sftp`, respectively.

## Configure destinations

Open **SFTP destinations** in the ProjectSend sidebar.

Creation requires a name, hostname or IP address, username, absolute directory,
trusted OpenSSH SHA-256 host fingerprint, and SSH private key. The port defaults
to 22 and destinations start disabled. An optional passphrase unlocks the key.

Blank or omitted credential fields preserve the saved pair. A replacement key
uses its submitted passphrase, or no passphrase when blank or omitted. This
allows rotation from an encrypted key to an unencrypted key without retaining
the old passphrase. Invalid keys or mismatched passphrases leave saved data
unchanged. If another administrator changes the destination while you edit it,
saving is refused until you review the updated values.

Keys and passphrases are encrypted with the host application's encryption key.
Preserve that key with database backups. Credentials are excluded from staff
transfer pages and queue payloads.

Saving configuration validates it locally. It does not establish connectivity
or verify the remote server's identity. The transport performs that verification
when called; never treat a syntactically valid fingerprint as proof that a server
is trusted.

To remove a destination, open **Edit**, choose **Delete destination**, and review
the separate confirmation page. **Keep destination** cancels. Confirmed deletion
removes that destination's database row, including its encrypted private key and
passphrase. The remote server, its authorised keys, and its files are unaffected.
Existing database backups and copies of key files are not erased. Disable a
destination instead if you want to retain its configuration.
Deletion is refused while the destination has queued, running, or uncertain
transfers. Completed and failed transfer records retain their destination name
after configuration deletion.

## Permissions and file access

Destination administration requires an active, unexpired staff account with
`edit_settings`. All active, unexpired staff can send transfers, subject to their
existing ProjectSend file permissions, library restrictions, file availability,
and download allowance. Clients and guests cannot use transfers.

Staff can select only enabled destinations and do not see their connection
settings or credentials. Pending, quarantined, missing, and deleted files are
excluded. Files with no remaining download allowance cannot be selected.

The worker checks access and availability again before uploading. A changed file
or destination configuration prevents queued work from starting. Disabling a
destination prevents queued uploads but does not stop an active transfer.
Confirmed deliveries count toward ProjectSend download limits and activity
history. Selection and unsuccessful uploads do not consume download allowance.
Concurrent transfers and browser downloads do not reserve allowance in advance.

## Send and track a backup

Staff use **SFTP transfers** in navigation, or `/sftp/transfers`. Choose **Send
backup**, search the permitted file library, choose a backup, then review its
approved destination and select **Send backup**. No backup bytes pass through the
browser. An asynchronous dedicated SFTP worker is required.
Configuration remains at `/system/settings/sftp`, restricted to staff with the
existing `edit_settings` permission. Transfer pages offer My transfers and Send
backup navigation only, including for administrators. Open destination
administration through its separate **SFTP destinations** ProjectSend sidebar
entry.

Delivery status shows an elapsed hours:minutes:seconds timer while execution is
running, then a fixed duration on completion. My transfers retains completed
durations. Timing excludes the initial queue wait and includes final checks and
any retries or reconciliation after the first start. Live timing pauses with
status updates on errors; Refresh retrieves the current recorded duration.

My transfers lists only the current requester's records, including when that
requester is an administrator. The list shows requests from the last six calendar
months, including the exact cutoff, plus older queued, running, or uncertain
transfers that still need attention. Completed and failed requests older than
the cutoff are hidden from the list before pagination; their database records
and authorised detail/status URLs remain available. Month-end cutoffs use the
last valid day of the target month. No scheduled cleanup or database deletion is
performed.

Detail pages show bytes sent, delivery status and
a reference for support. Pages are not cached. Active status polls every five
seconds, pauses in hidden tabs, and stops after 180 requests, an error, a session
change or a terminal state. **Refresh status** always works without JavaScript.
100% sent is not confirmation; only **Completed** confirms delivery.

Failed or unconfirmed transfers require an administrator to use the checked
[recovery procedure](#recover-or-retry-transfers) before sending again. The staff screens expose no retry
button. Repeating the same submitted form returns the same transfer without
queueing again; opening a fresh review creates a new request. Neither flow
restores a database or replaces an existing remote file.

## Delivery and server requirements

The incoming directory must exist and be writable by the SFTP account. The
server must support SFTP v3 rename without overwriting existing files. A filename
collision fails the transfer; existing backups are never replaced or deleted.

The package verifies the saved SSH host fingerprint before authentication.
Ed25519, ECDSA, and RSA SHA-2 host keys are supported. DSA and legacy SHA-1 RSA
signatures are unsupported. Network operations have a 30-second timeout.

Uploads stream through private staging directories with bounded memory use.
Source size and SHA-256, plus remote file length, are checked before publication.
Normal uploads do not reread remote disk contents. Recovery verifies the remote
file's full SHA-256 before confirming an uncertain delivery.

On Unix servers, staging directories use mode 0700 and published files use 0644.
The upload account retains write access. Other accounts can read published files
if directory access permits it. Provision database-service read access on the
server. For Windows, follow [Windows destinations](#windows-destinations).

Automatic retries and upload resumption are disabled. A lost connection can leave
a complete remote file without confirmed delivery. Use checked recovery before
sending again.

## Queue requirements and transfer states

Use an asynchronous database, Redis, SQS, or Beanstalkd connection. Sync and null
drivers are refused. A dedicated worker must listen on the `sftp` queue. Jobs
allow one attempt and have a 3600-second timeout. Queue reservation or visibility
timeouts must exceed that timeout; a database `retry_after` of 90 seconds is
insufficient. Follow [Configure the queue and dedicated worker](DEPLOYMENT.md#configure-the-queue-and-dedicated-worker).

Transfer states are:

| State | Meaning |
| --- | --- |
| `queued` | Accepted and awaiting worker execution. |
| `running` | A worker claimed the attempt; progress is bytes sent, not confirmed delivery. |
| `completed` | Remote publication was acknowledged and completion plus download accounting committed. |
| `failed` | Preparation failed, or reconciliation fenced an unpublished attempt and removed its partial payload. Explicit retry still rechecks access. |
| `uncertain` | Execution or accounting did not confirm completion. Reconciliation is required. A killed process can remain `running` until recovery claims its expired lease. |

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

From the installed application, queue reconciliation for one transfer:

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
There is no resume feature. To remove an unwanted failed attempt, use the
[operator cancellation workflow](#remove-an-unwanted-failed-transfer). Avoid direct
queue replay or manual status edits; use recovery so remote checks precede retries.

Before the first SFTP operation, the database stores the attempt UUID and approved
host, port, account, incoming directory, and host fingerprint. Recovery requires
the current destination to match that endpoint. Credential replacement is allowed
for recovery against the same endpoint. A changed host/path/fingerprint remains
unresolved until the original approved endpoint is restored; recovery never
silently contacts the replacement. No copy of credentials enters history or jobs.

Recovery verifies whether the recorded attempt published a complete backup.
Proven deliveries are marked Completed and counted once. Unpublished attempts
are prevented from publishing before a retry is allowed. Unexpected files,
invalid recovery metadata, or failed inspections leave the transfer unresolved.
Final files are never deleted or overwritten.

Abandoned attempts can retain small hidden staging directories containing
recovery metadata. **Do not remove these while an old worker could resume.**
They prevent stale workers from publishing and contain no backup bytes or
credentials. Use checked recovery or failed-transfer cancellation for cleanup.

After completion and accounting commit, the worker removes the successful
attempt's own `.projectsend-ATTEMPT_UUID.upload` directory. This also applies to
an already-published attempt completed through reconciliation. Cleanup validates
the manifest and the entire directory shape before deleting only known metadata;
it never recursively removes unknown files, staged payloads, or symlinks, and
never reads, deletes, or overwrites the published backup. Earlier abandoned
attempt barriers are not part of that cleanup.

Cleanup runs after delivery is recorded. Connection,
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

Older records without sufficient attempt identity remain unresolved for operator
investigation. Stop workers before upgrading or rolling back, and follow the
[rollback procedure](DEPLOYMENT.md#stop-or-roll-back) for unresolved work.

## Remove an unwanted failed transfer

Run this operator command from the installed host application:

```sh
php artisan sftp:cancel TRANSFER_UUID
```

Only Failed records can be removed. Never-started failures need no remote
connection, even if their destination has been deleted. For uncertain or
interrupted work, run checked recovery first and inspect the resulting status.
A completed delivery cannot be cancelled or retracted.

If an upload attempt was recorded, stop every SFTP worker and suspend any
supervisor that would restart it. Verify that all worker processes and their
remote SSH sessions have terminated, including any paused or disconnected
worker. `queue:restart` alone does not establish this: an existing job can still
be running. Wait for any execution lease to expire. Then run:

```sh
php artisan sftp:cancel TRANSFER_UUID --workers-stopped
```

The flag is your confirmation of that external prerequisite; the command cannot
inspect workers on other hosts or prove remote sessions have ended. Removing
fencing barriers while an old session can resume is unsafe. The command runs
SFTP cleanup synchronously in the CLI, not on the stopped queue. Resume workers
after the command finishes and you have inspected its result.

Cancellation verifies the original endpoint and reconciles every recorded
attempt before removing its known unpublished staging metadata. It never deletes
source backups, final backups, unrelated objects or delivery accounting. Changed
endpoints, unknown files, symlinks, publication evidence, and incomplete legacy
attempt history refuse removal. Restore the original endpoint and working
credentials before retrying. If publication is detected on a Failed record,
use `php artisan sftp:recover TRANSFER_UUID --retry` to reconcile it; a proven
delivery is accounted without another upload. Never edit statuses to force
cancellation.

Remote cleanup precedes database deletion under the transfer's execution lock.
A partial cleanup failure retains the record and recorded attempt identities;
repeat the same command after resolving the failure, with workers still stopped.
Known attempts already removed can be inspected and cleaned again safely.
A missing UUID that was already cancelled succeeds; an unknown UUID fails.

The transfer row is deleted, so history, detail and status stop showing it.
Only its UUID remains in `sftp_cancelled_transfers` to block old form replays.
Pending broker jobs are not purged or replayed; after restart they find no
transfer and do nothing, including jobs that allowed a retry. A fresh authorised
request uses a new UUID and remains possible.

New attempts persist earlier attempt UUIDs before starting another upload. Older
single-attempt records can be removed when their identity and remote metadata
are verifiable. Older multi-attempt records with missing identities and legacy
started records without an attempt UUID require operator investigation. The
command does not guess directories or offer a force-delete option.

Deploy with workers stopped and apply
`2026_10_26_000000_add_sftp_cancellation_support` before restarting. Preserve the
UUID replay guards and attempt history on code rollback. Keep submissions
disabled on versions that do not enforce cancellation guards. Dropping them can
revive old requests and loses the identities needed for safe cleanup; retire old
forms and queued jobs before any separately reviewed schema rollback.

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

## License

[BSD 3-Clause](LICENSE). Copyright 2026 GGP Systems Limited.
