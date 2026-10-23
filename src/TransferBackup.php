<?php

declare(strict_types=1);

namespace ProjectSendSftp;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

final class TransferBackup implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 3600;
    public bool $failOnTimeout = true;

    public function __construct(public readonly string $transferId, public readonly bool $allowRetry = false)
    {
        $this->onQueue('sftp');
    }

    public function handle(TransferRunner $runner): void
    {
        $runner->run($this->transferId, $this->allowRetry);
    }

    public static function requireAsyncQueue(): void
    {
        $connection = config('queue.default');
        if (! in_array(config("queue.connections.{$connection}.driver"), ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
            throw new SftpTransferException('An asynchronous queue connection is required for SFTP transfers.');
        }
    }

    // No failed() callback mutates shared state: Laravel can call it for a
    // duplicate delivery while the original worker is still uploading.
    // A killed worker's lease expires and explicit recovery takes ownership.
}
