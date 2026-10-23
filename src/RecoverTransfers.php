<?php

declare(strict_types=1);

namespace ProjectSendSftp;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Throwable;

final class RecoverTransfers extends Command
{
    protected $signature = 'sftp:recover {transfer? : A transfer UUID; omit to scan eligible records}
        {--retry : Permit a new upload after the previous attempt is proved unpublished}';

    protected $description = 'Queue SFTP recovery or completed-transfer staging cleanup, optionally allowing safe retries';

    public function handle(): int
    {
        $id = $this->argument('transfer');
        if ($id !== null && ! Str::isUuid($id)) {
            $this->error('Supply a valid transfer UUID.');
            return self::FAILURE;
        }
        try {
            TransferBackup::requireAsyncQueue();
            $query = Transfer::query()->where(fn ($q) => $q->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', now()));
            // An explicit completed UUID retries cleanup only. Routine scans
            // must not repeatedly reconnect for every historical delivery.
            if ($id === null) { $query->where('status', '!=', TransferStatus::Completed); }
            if (! $this->option('retry')) { $query->where('status', '!=', TransferStatus::Failed); }
            if ($id !== null) { $query->whereKey($id); }
            $count = 0;
            $query->select('id')->chunkById(100, function ($rows) use (&$count): void {
                foreach ($rows as $row) {
                    Bus::dispatch(new TransferBackup($row->id, (bool) $this->option('retry')));
                    $count++;
                }
            });
            $this->info("Queued {$count} recovery/cleanup job(s). Active leases were skipped; completed transfers only receive cleanup when explicitly selected.");
            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Recovery could not be queued. Check the database and asynchronous queue connection.');
            return self::FAILURE;
        }
    }
}
