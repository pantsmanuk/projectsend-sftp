<?php

declare(strict_types=1);

// Test-only independent process, sharing only the isolated fixture database.
[$script, $hostPath, $fixturePath, $id, $label] = $argv;
require __DIR__.'/host-bootstrap.php';
$app = bootSftpHost($hostPath, dirname(__DIR__), $fixturePath, $fixturePath.'/database.sqlite');
if (in_array('--pause', $argv, true)) {
    $paused = false;
    Illuminate\Support\Facades\DB::listen(function ($event) use ($fixturePath, $label, &$paused): void {
        if (! $paused && str_starts_with($event->sql, 'update "sftp_transfers" set "bytes_sent"')) {
            $paused = true;
            touch($fixturePath.'/paused-'.$label);
            $deadline = microtime(true) + 40;
            while (! file_exists($fixturePath.'/resume-'.$label)) {
                if (microtime(true) > $deadline) { throw new RuntimeException('Fixture pause timed out.'); }
                usleep(20000);
            }
        }
    });
}
$app->make(ProjectSendSftp\TransferRunner::class)->run($id, in_array('--retry', $argv, true));
