<?php

declare(strict_types=1);

namespace ProjectSendSftp;

use App\Models\User;
use App\Modules\Audit\Action;
use App\Modules\Audit\ActivityLogger;
use App\Modules\Files\Access\StaffLibraryScope;
use App\Modules\Files\Models\File;
use App\Modules\Identity\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

final class TransferRunner
{
    public function __construct(private readonly SftpTransport $transport) {}

    public function run(string $id, bool $allowRetry = false): void
    {
        app()->forgetInstance(PermissionChecker::class);
        app()->forgetInstance(StaffLibraryScope::class);
        $transfer = DB::transaction(function () use ($id, $allowRetry): ?Transfer {
            $row = Transfer::query()->lockForUpdate()->find($id);
            if ($row === null) { return null; }
            if ($row->status === TransferStatus::Completed) { return $row; }
            if (($row->status === TransferStatus::Failed && ! $allowRetry)
                || ($row->lease_expires_at !== null && $row->lease_expires_at->isFuture())) {
                return null;
            }
            // This marker is needed to refuse old phase 6 uncertain attempts,
            // whose randomly named remote staging cannot be safely identified.
            $row->forceFill([
                'execution_token' => (string) Str::uuid(), 'lease_expires_at' => now()->addSeconds(120),
                'status' => TransferStatus::Running, 'finished_at' => null,
            ])->save();
            return $row;
        });
        if ($transfer === null) { return; }
        if ($transfer->status === TransferStatus::Completed) {
            DB::afterCommit(fn () => $this->cleanup($transfer->id));
            return;
        }
        $token = $transfer->execution_token;
        $enteredTransport = $transfer->attempt_id !== null;
        $guard = fn () => $this->heartbeat($id, $token);
        try {
            if ($transfer->remote_identity === null && $transfer->started_at !== null) {
                $enteredTransport = true;
                throw new SftpTransferException('This legacy attempt has no recoverable remote identity. Operator investigation is required.');
            }
            $destination = Destination::query()->findOrFail($transfer->destination_id);
            if ($transfer->remote_identity !== null && $destination->remoteIdentity() !== $transfer->remote_identity) {
                throw new SftpTransferException('The destination endpoint has changed. Restore the original endpoint before reconciliation or retry.');
            }
            if ($transfer->attempt_id !== null) {
                $path = $this->transport->reconcile($destination, $transfer->attempt_id, $transfer->filename,
                    $transfer->total_bytes, $transfer->sha256, $guard);
                if ($path !== null) {
                    $this->complete($transfer, $token, $path);
                    return;
                }
                $enteredTransport = false; // Remote barrier proves no publication.
                if (! $allowRetry) {
                    $this->finish($id, $token, TransferStatus::Failed, 'The previous attempt was fenced and its partial upload removed. An explicit retry is safe.');
                    return;
                }
            }
            $actor = User::query()->findOrFail($transfer->requested_by);
            if (! $destination->enabled || ($transfer->attempts === 0 && ! hash_equals($transfer->destination_revision, $destination->revision()))) {
                throw new SftpTransferException('The destination is disabled or has changed since this transfer was requested.');
            }
            $lastProgressAt = 0.0;
            app(TransferSource::class)->withStream($actor, $transfer->file_id, function ($stream, File $file) use (
                $transfer, $token, $destination, $actor, $guard, &$enteredTransport, &$lastProgressAt,
            ): void {
                foreach (Transfer::sourceIdentity($file) as $attribute => $value) {
                    if ($transfer->getAttribute($attribute) !== $value) {
                        throw new SftpTransferException('The selected source file has changed since this transfer was requested.');
                    }
                }
                $guard();
                $attemptId = (string) Str::uuid();
                $updated = $this->owned($transfer->id, $token)->update([
                    'attempt_id' => $attemptId, 'attempts' => $transfer->attempts + 1,
                    'previous_attempt_ids' => json_encode([
                        ...($transfer->previous_attempt_ids ?? []),
                        ...($transfer->attempt_id === null ? [] : [$transfer->attempt_id]),
                    ], JSON_THROW_ON_ERROR),
                    'remote_identity' => json_encode($destination->remoteIdentity(), JSON_THROW_ON_ERROR),
                    'actor_snapshot' => json_encode($transfer->actor_snapshot ?? ['name' => $actor->name, 'type' => $actor->type->value], JSON_THROW_ON_ERROR),
                    'started_at' => $transfer->started_at ?? now(), 'bytes_sent' => 0, 'error' => null,
                ]);
                if ($updated !== 1) { throw new SftpTransferException('The transfer execution lease was lost.'); }
                $enteredTransport = true;
                $result = $this->transport->upload($destination, $stream, $transfer->filename, $transfer->total_bytes, $transfer->sha256,
                    function (int $bytes) use ($transfer, $token, &$lastProgressAt): void {
                        $now = microtime(true);
                        if ($now - $lastProgressAt >= 1 || $bytes === $transfer->total_bytes) {
                            $this->heartbeat($transfer->id, $token, $bytes);
                            $lastProgressAt = $now;
                        }
                    }, $attemptId, $guard);
                $this->complete($transfer->fresh(), $token, $result['path']);
            });
        } catch (Throwable $exception) {
            $this->finish($id, $token, $enteredTransport ? TransferStatus::Uncertain : TransferStatus::Failed,
                $exception instanceof SftpTransferException ? $exception->getMessage()
                    : 'The transfer could not confirm completion. Check requester, source, destination, and storage availability.');
        }
    }

    private function owned(string $id, string $token): Builder
    {
        return Transfer::query()->whereKey($id)->where('execution_token', $token)->where('status', TransferStatus::Running);
    }

    private function heartbeat(string $id, string $token, ?int $bytes = null): void
    {
        $query = $this->owned($id, $token)->where('lease_expires_at', '>', now());
        $changes = ['lease_expires_at' => now()->addSeconds(120)];
        if ($bytes !== null) { $changes = ['bytes_sent' => $bytes] + $changes; }
        // MySQL may report zero for an unchanged timestamp within one second.
        if ($query->update($changes) === 0 && ! $query->exists()) {
            throw new SftpTransferException('The transfer execution lease was lost.');
        }
    }

    private function finish(string $id, string $token, TransferStatus $status, string $error): void
    {
        $this->owned($id, $token)->update([
            'status' => $status, 'error' => $error, 'finished_at' => now(),
            'execution_token' => null, 'lease_expires_at' => null,
        ]);
    }

    private function complete(Transfer $transfer, string $token, string $path): void
    {
        DB::transaction(function () use ($transfer, $token, $path): void {
            $current = Transfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if ($current->status !== TransferStatus::Running || $current->execution_token !== $token
                || $current->lease_expires_at === null || ! $current->lease_expires_at->isFuture()) {
                throw new SftpTransferException('The transfer execution lease was lost.');
            }
            // Reconciliation records an already delivered copy even if its
            // requester/file was subsequently disabled, changed, or deleted.
            $actor = new User;
            $actor->forceFill([
                'id' => User::withTrashed()->whereKey($current->requested_by)->exists() ? $current->requested_by : null,
                'name' => $current->actor_snapshot['name'], 'type' => $current->actor_snapshot['type'],
            ]);
            $file = new File;
            $file->forceFill(['id' => $current->file_id, 'name' => $current->file_name]);
            app(ActivityLogger::class)->log(Action::FileDownloaded, actor: $actor, subject: $file, context: [
                'delivery' => 'sftp', 'sftp_transfer_id' => $current->id, 'sftp_destination_id' => $current->destination_id,
            ]);
            $current->forceFill([
                'status' => TransferStatus::Completed, 'bytes_sent' => $current->total_bytes, 'remote_path' => $path,
                'finished_at' => now(), 'error' => null, 'execution_token' => null, 'lease_expires_at' => null,
            ])->save();
            // Retain receipts until the outermost completion/accounting commit.
            // Cleanup never changes the already committed delivery outcome.
            DB::afterCommit(fn () => $this->cleanup($current->id));
        });
    }

    private function cleanup(string $id): void
    {
        try {
            $transfer = Transfer::query()->findOrFail($id);
            if ($transfer->status !== TransferStatus::Completed || $transfer->attempt_id === null) { return; }
            $destination = Destination::query()->findOrFail($transfer->destination_id);
            if ($transfer->remote_identity !== $destination->remoteIdentity()) {
                throw new SftpTransferException('The destination endpoint has changed.');
            }
            $this->transport->cleanupCompleted($destination, $transfer->attempt_id, $transfer->filename,
                $transfer->total_bytes, $transfer->sha256);
        } catch (Throwable) {
            // Only the reference is safe to log; never include backend errors,
            // credentials, paths, or an exception with a sensitive chain.
            try {
                Log::warning('Completed SFTP transfer staging cleanup could not finish.', ['transfer_id' => $id]);
            } catch (Throwable) {
                // Logging failure must not escape an after-commit callback.
            }
        }
    }
}
