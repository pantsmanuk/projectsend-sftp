<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ProjectSendSftp\SftpTransport;
use ProjectSendSftp\Transfer;
use ProjectSendSftp\TransferBackup;
use ProjectSendSftp\TransferStatus;

(function () use ($sshPath, $payload, $newFile, $submit, $staff, $runNext, $sftpTarget, $app): void {
    $file = $newFile(['original_name' => 'cancel-multiple.dump', 'path' => 'backups/cancel-multiple.dump']);
    Storage::disk('sftp_test_local')->put($file->path, str_repeat('X', strlen($payload)));
    $row = $submit->submit($staff, $file->id, $sftpTarget->id);
    $runNext();
    $firstAttempt = $row->fresh()->attempt_id;
    Artisan::call('sftp:recover', ['transfer' => $row->id]);
    $runNext();
    sourceCheck($row->fresh()->status === TransferStatus::Failed, 'Cancellation fixture reconciles an unsuccessful upload before removal');
    Artisan::call('sftp:recover', ['transfer' => $row->id, '--retry' => true]);
    $runNext();
    Artisan::call('sftp:recover', ['transfer' => $row->id]);
    $runNext();
    $failed = $row->fresh();
    sourceCheck($failed->status === TransferStatus::Failed && $failed->attempts === 2
        && $failed->previous_attempt_ids === [$firstAttempt]
        && ! array_key_exists('previous_attempt_ids', $failed->toArray()),
        'Retries persist all earlier attempt identities without exposing them in model responses');
    $roots = array_map(fn ($attempt) => $sshPath.'/incoming/.projectsend-'.$attempt.'.upload', [$firstAttempt, $failed->attempt_id]);
    $cancel = fn (array $options = []) => Artisan::call('sftp:cancel', ['transfer' => $row->id] + $options);
    $logins = substr_count(file_get_contents($sshPath.'/sshd.log'), 'Accepted publickey');
    sourceCheck($cancel() === 1 && substr_count(file_get_contents($sshPath.'/sshd.log'), 'Accepted publickey') === $logins
        && is_file($roots[0].'/writer'), 'Cancellation without stopped-worker confirmation preserves barriers without connecting');
    $originalDirectory = $sftpTarget->directory;
    $sftpTarget->update(['directory' => $sshPath.'/another-endpoint']);
    sourceCheck($cancel(['--workers-stopped' => true]) === 1
        && substr_count(file_get_contents($sshPath.'/sshd.log'), 'Accepted publickey') === $logins,
        'Cancellation refuses a changed endpoint before connecting');
    $sftpTarget->update(['directory' => $originalDirectory]);
    file_put_contents($roots[0].'/keep-note', 'unrelated');
    sourceCheck($cancel(['--workers-stopped' => true]) === 1 && $row->fresh() !== null
        && file_get_contents($roots[0].'/keep-note') === 'unrelated' && is_file($roots[0].'/manifest'),
        'Unknown remote content refuses cancellation and preserves the record and unrelated object');
    unlink($roots[0].'/keep-note');
    rename($roots[0], $roots[0].'-saved');
    symlink($roots[0].'-saved', $roots[0]);
    sourceCheck($cancel(['--workers-stopped' => true]) === 1 && is_link($roots[0]) && is_file($roots[0].'-saved/manifest'),
        'Cancellation neither follows nor deletes a symlink replacing the attempt root');
    unlink($roots[0]);
    rename($roots[0].'-saved', $roots[0]);
    $failDelete = true;
    Transfer::deleting(function (Transfer $deleting) use ($row, &$failDelete): void {
        if ($deleting->id === $row->id && $failDelete) { $failDelete = false; throw new RuntimeException('synthetic database failure'); }
    });
    sourceCheck($cancel(['--workers-stopped' => true]) === 1 && $row->fresh() !== null
        && ! DB::table('sftp_cancelled_transfers')->where('id', $row->id)->exists(),
        'A database failure after remote cleanup retains attempt history for a safe repeated cancellation');
    clearstatcache();
    sourceCheck(! file_exists($roots[0]) && ! file_exists($roots[1]), 'Cancellation cleans both current and earlier fenced attempt directories');
    $sftpTarget->update(['enabled' => false]);
    sourceCheck($cancel(['--workers-stopped' => true]) === 0 && $row->fresh() === null,
        'Cancellation converges after remote cleanup and database failure, even for a disabled destination');
    clearstatcache();
    sourceCheck(! file_exists($roots[0]) && ! file_exists($roots[1]) && $file->downloads()->count() === 0
        && Storage::disk('sftp_test_local')->get($file->path) === str_repeat('X', strlen($payload))
        && file_get_contents($sshPath.'/incoming/staff-web.dump') === $payload,
        'Removal preserves source bytes, other delivered backups and accounting');
    $sftpTarget->update(['enabled' => true]);
    $app->make('queue')->connection('database')->push(new TransferBackup($row->id, true), [], 'sftp');
    $runNext();
    sourceCheck($row->fresh() === null && ! file_exists($sshPath.'/incoming/cancel-multiple.dump'),
        'A real delayed retry job cannot resurrect or publish a cancelled attempt');

    // Simulate a lost completion write: the ready receipt still proves publication.
    $deliveredFile = $newFile(['original_name' => 'cancel-published.dump']);
    $delivered = $submit->submit($staff, $deliveredFile->id, $sftpTarget->id);
    DB::table('jobs')->where('queue', 'sftp')->delete();
    $attempt = (string) Str::uuid();
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, $payload);
    rewind($stream);
    try {
        $app->make(SftpTransport::class)->upload($sftpTarget, $stream, $delivered->filename,
            $delivered->total_bytes, $delivered->sha256, attemptId: $attempt);
    } finally { fclose($stream); }
    $delivered->update(['status' => TransferStatus::Failed, 'attempt_id' => $attempt, 'attempts' => 1, 'started_at' => now()]);
    sourceCheck(Artisan::call('sftp:cancel', ['transfer' => $delivered->id, '--workers-stopped' => true]) === 1
        && str_contains(Artisan::output(), 'published a backup') && $delivered->fresh()->status === TransferStatus::Failed
        && file_get_contents($sshPath.'/incoming/cancel-published.dump') === $payload && $deliveredFile->downloads()->count() === 0,
        'Cancellation refuses a published attempt and preserves its record, delivered bytes and accounting evidence');
    Artisan::call('sftp:recover', ['transfer' => $delivered->id, '--retry' => true]);
    $runNext();
    sourceCheck($delivered->fresh()->status === TransferStatus::Completed && $deliveredFile->downloads()->count() === 1,
        'Refused cancellation leaves publication receipts usable for checked recovery and exactly-once accounting');
})();
