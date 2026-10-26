<?php

declare(strict_types=1);

namespace ProjectSendSftp;

use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;
use InvalidArgumentException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class RequestTransfer
{
    public function submit(User $actor, int $fileId, int $destinationId, ?string $requestId = null): Transfer
    {
        $actor = User::query()->findOrFail($actor->getKey());
        Gate::forUser($actor)->authorize('sftp.transfer');
        if ($requestId !== null && ! Str::isUuid($requestId)) {
            throw new InvalidArgumentException('A valid send request UUID is required.');
        }
        $id = strtolower($requestId ?? (string) Str::uuid());
        $existing = Transfer::query()->find($id);
        if ($existing !== null) {
            return $this->sameRequest($existing, $actor, $fileId, $destinationId);
        }
        TransferBackup::requireAsyncQueue();
        $file = app(TransferSource::class)->select($actor, $fileId);
        $identity = Transfer::sourceIdentity($file);
        SftpTransport::validateFile($identity['filename'], $identity['total_bytes'], $identity['sha256']);
        $created = false;

        try {
            return DB::transaction(function () use ($id, $actor, $file, $identity, $destinationId, &$created): Transfer {
                // Same lock as destination edits/deletion. A transfer can never
                // be inserted after deletion's active-transfer check passes.
                $destination = Destination::query()->lockForUpdate()->findOrFail($destinationId);
                $existing = Transfer::query()->lockForUpdate()->find($id);
                if ($existing !== null) {
                    return $this->sameRequest($existing, $actor, $file->getKey(), $destinationId);
                }
                if (DB::table('sftp_cancelled_transfers')->where('id', $id)->lockForUpdate()->first() !== null) {
                    throw ValidationException::withMessages(['_request_id' => __('This transfer was cancelled. Choose the backup again to start a new request.')]);
                }
                if (! $destination->enabled) {
                    throw new SftpTransferException('The destination is disabled.');
                }
                SftpTransport::validateFile($identity['filename'], $identity['total_bytes'], $identity['sha256'], $destination->windows_acl_managed);
                $transfer = Transfer::query()->create($identity + [
                    'id' => $id, 'requested_by' => $actor->getKey(), 'file_id' => $file->getKey(),
                    'file_name' => $file->name, 'destination_id' => $destination->getKey(),
                    'destination_name' => $destination->name, 'destination_revision' => $destination->revision(),
                    'remote_identity' => $destination->remoteIdentity(),
                    'actor_snapshot' => ['name' => $actor->name, 'type' => $actor->type->value],
                    'status' => TransferStatus::Queued,
                ]);
                $created = true;
                Bus::dispatch((new TransferBackup($id))->afterCommit());

                return $transfer;
            });
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            // An after-commit push may fail after the transfer row was saved.
            // Revoke only a still-queued record; a worker may already own it.
            if ($created) { Transfer::query()->whereKey($id)->where('status', TransferStatus::Queued)->update([
                'status' => TransferStatus::Failed, 'error' => 'The transfer could not be queued.', 'finished_at' => now(),
            ]); }
            throw new SftpTransferException('The transfer could not be queued.');
        }
    }

    private function sameRequest(Transfer $existing, User $actor, int $fileId, int $destinationId): Transfer
    {
        if ($existing->requested_by !== $actor->getKey() || $existing->file_id !== $fileId || $existing->destination_id !== $destinationId) {
            throw ValidationException::withMessages(['_request_id' => __('This send request has already been used. Open My transfers or choose the backup again.')]);
        }
        return $existing;
    }
}
