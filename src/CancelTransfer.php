<?php

declare(strict_types=1);

namespace ProjectSendSftp;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class CancelTransfer extends Command
{
    protected $signature = 'sftp:cancel {transfer : A failed transfer UUID}
        {--workers-stopped : Confirm all SFTP workers and their remote SSH sessions have terminated}';

    protected $description = 'Remove a failed transfer and its verified unpublished staging artifacts';

    public function handle(SftpTransport $transport): int
    {
        $id = $this->argument('transfer');
        if (! Str::isUuid($id)) {
            $this->error('Supply a valid transfer UUID.');
            return self::FAILURE;
        }
        $id = strtolower($id);
        try {
            DB::transaction(function () use ($id, $transport): void {
                // Hold the same row lock used by execution/recovery until remote
                // cleanup and deletion finish. A failed cleanup retains the row.
                $row = Transfer::query()->lockForUpdate()->find($id);
                if ($row === null) {
                    if (DB::table('sftp_cancelled_transfers')->where('id', $id)->exists()) { return; }
                    throw new SftpTransferException('Transfer not found. Check the UUID.');
                }
                if ($row->status !== TransferStatus::Failed) {
                    throw new SftpTransferException('Only failed transfers can be removed. Use sftp:recover for uncertain or interrupted delivery first.');
                }
                if ($row->lease_expires_at !== null && $row->lease_expires_at->isFuture()) {
                    throw new SftpTransferException('An execution lease is still active. Wait for it to expire after stopping the worker.');
                }
                if ($row->attempt_id === null) {
                    if ($row->started_at !== null || $row->attempts !== 0 || ($row->previous_attempt_ids ?? []) !== []) {
                        throw new SftpTransferException('This legacy transfer has incomplete attempt history. Operator investigation is required; no paths will be guessed.');
                    }
                } else {
                    if (! $this->option('workers-stopped')) {
                        throw new SftpTransferException('Stop all SFTP workers and terminate their remote SSH sessions, then repeat with --workers-stopped. Removing fencing barriers while a writer can resume is unsafe.');
                    }
                    $attempts = [...($row->previous_attempt_ids ?? []), $row->attempt_id];
                    if (count($attempts) !== $row->attempts || count(array_unique($attempts)) !== count($attempts)) {
                        throw new SftpTransferException('This legacy transfer has incomplete attempt history. Operator investigation is required; no paths will be guessed.');
                    }
                    $destination = Destination::query()->find($row->destination_id);
                    if ($destination === null || $row->remote_identity === null || $destination->remoteIdentity() !== $row->remote_identity) {
                        throw new SftpTransferException('Restore the original destination endpoint and credentials before removing its staging artifacts.');
                    }
                    foreach ($attempts as $attempt) {
                        $transport->cleanupUnpublished($destination, $attempt, $row->filename, $row->total_bytes, $row->sha256);
                    }
                }
                // Retain only the UUID, not transfer metadata. Old send forms
                // must never recreate this ID and revive an old queued job.
                DB::table('sftp_cancelled_transfers')->insertOrIgnore(['id' => $id]);
                $row->delete();
            });
            $this->info('Failed transfer removed. Any remaining queued jobs for this UUID will do nothing.');
            return self::SUCCESS;
        } catch (SftpTransferException $exception) {
            $this->error($exception->getMessage());
        } catch (Throwable) {
            $this->error('Cancellation could not confirm completion. Check the database, destination and service, then retry.');
        }
        return self::FAILURE;
    }
}
