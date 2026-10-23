<?php

declare(strict_types=1);

use App\Modules\Audit\ActivityLog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use ProjectSendSftp\Transfer;
use ProjectSendSftp\TransferRunner;
use ProjectSendSftp\TransferStatus;

(function () use ($sshPath, $payload, $newFile, $submit, $staff, $runNext, $sftpTarget, $app): void {
    $file = $newFile(['original_name' => 'outer-commit.dump']);
    $row = $submit->submit($staff, $file->id, $sftpTarget->id);
    DB::table('jobs')->where('queue', 'sftp')->delete();
    DB::beginTransaction();
    try {
        $app->make(TransferRunner::class)->run($row->id);
        $done = $row->fresh();
        $root = $sshPath.'/incoming/.projectsend-'.$done->attempt_id.'.upload';
        sourceCheck($done->status === TransferStatus::Completed && is_file($root.'/writer/ready')
            && $file->downloads()->count() === 1, 'Completed-attempt receipts survive until the outermost accounting transaction commits');
        DB::commit();
    } catch (Throwable $exception) {
        if (DB::transactionLevel() > 0) { DB::rollBack(); }
        throw $exception;
    }
    clearstatcache();
    sourceCheck(! file_exists($root) && file_get_contents($done->remote_path) === $payload,
        'The outermost commit removes the successful attempt metadata while preserving the final backup');

    $file = $newFile(['original_name' => 'cleanup-obstructed.dump']);
    $row = $submit->submit($staff, $file->id, $sftpTarget->id);
    $obstruct = true;
    ActivityLog::created(function (ActivityLog $log) use ($row, $sshPath, &$obstruct): void {
        if ($obstruct && ($log->context['sftp_transfer_id'] ?? null) === $row->id) {
            $obstruct = false;
            $root = $sshPath.'/incoming/.projectsend-'.$row->fresh()->attempt_id.'.upload';
            file_put_contents($root.'/unexpected-note', 'leave this alone');
        }
    });
    $runNext();
    $done = $row->fresh();
    $root = $sshPath.'/incoming/.projectsend-'.$done->attempt_id.'.upload';
    sourceCheck($done->status === TransferStatus::Completed && $done->error === null
        && $file->downloads()->count() === 1 && is_file($root.'/writer/ready')
        && file_get_contents($root.'/unexpected-note') === 'leave this alone',
        'Cleanup refuses unexpected content without deleting receipts, failing delivery, or duplicating accounting');
    $saved = $done->getRawOriginal();
    $directory = $sftpTarget->directory;
    $sftpTarget->update(['directory' => $sshPath.'/different-endpoint']);
    $logins = substr_count(file_get_contents($sshPath.'/sshd.log'), 'Accepted publickey');
    Artisan::call('sftp:recover', ['transfer' => $row->id]);
    $runNext();
    sourceCheck(substr_count(file_get_contents($sshPath.'/sshd.log'), 'Accepted publickey') === $logins
        && $row->fresh()->getRawOriginal() === $saved && is_file($root.'/writer/ready'),
        'Completed cleanup refuses a changed endpoint before connecting and preserves the completed record');
    $sftpTarget->update(['directory' => $directory, 'enabled' => false]);
    unlink($root.'/unexpected-note');
    sourceCheck(Artisan::call('sftp:recover', ['transfer' => $row->id, '--retry' => true]) === 0
        && DB::table('jobs')->where('queue', 'sftp')->count() === 1,
        'An explicitly selected completed UUID queues cleanup only, even with --retry');
    $runNext();
    clearstatcache();
    sourceCheck(! file_exists($root) && file_get_contents($done->remote_path) === $payload
        && $row->fresh()->getRawOriginal() === $saved && $file->downloads()->count() === 1,
        'Completed cleanup succeeds with a disabled destination without re-upload, status changes, or new accounting');
    $sftpTarget->update(['enabled' => true]);
    Artisan::call('sftp:recover', ['transfer' => $row->id]);
    $runNext();
    sourceCheck($row->fresh()->getRawOriginal() === $saved && ! file_exists($root)
        && $file->downloads()->count() === 1, 'Repeated completed cleanup is idempotent and never recreates its remote directory');
})();
