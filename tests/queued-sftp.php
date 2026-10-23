<?php

declare(strict_types=1);

use App\Modules\Audit\ActivityLog;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ProjectSendSftp\Destination;
use ProjectSendSftp\Transfer;
use ProjectSendSftp\TransferBackup;
use ProjectSendSftp\TransferStatus;

require __DIR__.'/sftp-fixture.php';
$sshPath = $fixturePath.'/sftp-server';
$sshServer = null;
$observedProgress = [];
$interrupt = false;
try {
    [$sshServer, $sshUser, $sshPort] = startSftpFixture($sshPath);
    $sftpTarget = Destination::create([
        'name' => 'Queued loopback destination', 'host' => '127.0.0.1', 'port' => $sshPort, 'username' => $sshUser,
        'directory' => $sshPath.'/incoming', 'enabled' => true,
        'host_fingerprint' => fixtureFingerprint($sshPath.'/host-ed25519.pub'),
        'private_key' => file_get_contents($sshPath.'/login'), 'private_key_passphrase' => ' fixture passphrase ',
    ]);
    DB::listen(function (QueryExecuted $query) use (&$observedProgress, &$interrupt, $sshPath): void {
        if (str_starts_with($query->sql, 'update "sftp_transfers" set "bytes_sent"')) {
            $row = Transfer::query()->where('status', TransferStatus::Running)->first();
            if ($row !== null) {
                $observedProgress[] = $row->bytes_sent;
                if ($interrupt) {
                    $interrupt = false;
                    touch($sshPath.'/drop-connection');
                    usleep(150000);
                }
            }
        }
    });
    if (in_array('--workers-only', $argv, true)) {
        $recover = function ($row, bool $retry = false) use ($runNext): void {
            Illuminate\Support\Facades\Artisan::call('sftp:recover', ['transfer' => $row->id, '--retry' => $retry]);
            $runNext();
        };
        require __DIR__.'/recovery-workers.php';
    } else {
    $webFile = $newFile(['original_name' => 'staff-web.dump']);
    $app->instance('env', 'local');
    $webReview = $workflowHttp($staff, 'GET', '/sftp/transfers/review/'.$webFile->id);
    $webInput = ['_token' => $formValue($webReview->getContent(), '_token'), '_request_id' => $formValue($webReview->getContent(), '_request_id'), 'file_id' => $webFile->id, 'destination_id' => $sftpTarget->id];
    $webSent = $workflowHttp($staff, 'POST', '/sftp/transfers', $webInput);
    sourceCheck($webSent->getStatusCode() === 303, 'Staff review and HTTP send submit to the real SFTP fixture');
    $runNext();
    $webStatus = $workflowHttp($staff, 'GET', '/sftp/transfers/'.$webInput['_request_id'].'/status', [], ['HTTP_ACCEPT' => 'application/json']);
    sourceCheck(json_decode($webStatus->getContent(), true)['data']['status'] === 'completed'
        && file_get_contents($sshPath.'/incoming/staff-web.dump') === $payload && $webFile->downloads()->count() === 1
        && (fileperms($sshPath.'/incoming/staff-web.dump') & 0777) === 0644
        && str_contains($workflowHttp($staff, 'GET', '/sftp/transfers')->getContent(), $webInput['_request_id']),
        'Staff HTTP-to-queue-to-SFTP delivery publishes 0644 with confirmed progress, own history, exact bytes and one audit entry');
    $app->instance('env', 'testing');
    $webTransfer = Transfer::findOrFail($webInput['_request_id']);
    sourceCheck(! is_dir($sshPath.'/incoming/.projectsend-'.$webTransfer->attempt_id.'.upload'),
        'A committed completed transfer removes its successful attempt directory');
    $realFile = $newFile(['original_name' => 'queued-local.dump', 'download_limit' => 1]);
    $attempt = $submit->submit($staff, $realFile->id, $sftpTarget->id);
    $rawJob = DB::table('jobs')->where('queue', 'sftp')->first()->payload;
    sourceCheck(! str_contains($rawJob, file_get_contents($sshPath.'/login')) && ! str_contains($rawJob, 'fixture passphrase'),
        'A real encrypted destination key/passphrase never enters the job payload');
    sourceCheck($app->make('queue')->connection('database')->pop('default') === null
        && ! file_exists($sshPath.'/incoming/queued-local.dump'), 'Default-queue workers cannot pick up SFTP jobs');
    Auth::guard('web')->setUser($client); // Must never become the audit actor.
    $runNext();
    $done = $attempt->fresh();
    $log = $realFile->downloads()->first();
    sourceCheck($done->status === TransferStatus::Completed && $done->started_at !== null && $done->finished_at !== null
        && $done->bytes_sent === strlen($payload) && file_get_contents($done->remote_path) === $payload
        && hash_file('sha256', $done->remote_path) === hash('sha256', $payload),
        'Real database-queued execution streams the authorised source over SFTP and records confirmed completion');
    sourceCheck($observedProgress !== [] && end($observedProgress) === strlen($payload),
        'Progress is persisted while the attempt is running');
    sourceCheck($realFile->downloads()->count() === 1 && $log->actor_id === $staff->id
        && $log->context['delivery'] === 'sftp' && $log->context['sftp_transfer_id'] === $attempt->id,
        'Confirmed delivery spends allowance exactly once through the host audit log with the explicit requester');
    sourceCheck(array_intersect(['source_path', 'source_disk', 'sha256', 'destination_revision', 'remote_path'], array_keys($done->toArray())) === [],
        'Transfer responses hide internal storage and destination path details');
    $rejectRequest(fn () => $submit->submit($staff, $realFile->id, $sftpTarget->id), 'Confirmed delivery exhausts the host download allowance');
    $app->make('queue')->connection('database')->push(new TransferBackup($attempt->id), [], 'sftp');
    $runNext();
    sourceCheck($attempt->fresh()->status === TransferStatus::Completed && $realFile->downloads()->count() === 1,
        'A completed attempt is not executed or accounted again on sequential redelivery');

    $mock->append(new Aws\Result(['Body' => GuzzleHttp\Psr7\Utils::streamFor($payload), 'ContentLength' => strlen($payload)]));
    $objectFile = $newFile(['disk' => 'sftp_test_remote', 'original_name' => 'queued-object.dump']);
    $objectAttempt = $submit->submit($staff, $objectFile->id, $sftpTarget->id);
    $runNext();
    sourceCheck($objectAttempt->fresh()->status === TransferStatus::Completed
        && file_get_contents($objectAttempt->fresh()->remote_path) === $payload && $objectFile->downloads()->count() === 1,
        'Queued execution composes the real S3/Flysystem source adapter with SFTP without a local backup copy');

    require __DIR__.'/cleanup-queued-sftp.php';
    require __DIR__.'/windows-queued-sftp.php';

    // The audit insert and completed state must roll back together if either
    // cannot be saved after remote publication. Only the result stays uncertain.
    $failAudit = true;
    ActivityLog::created(function () use (&$failAudit): void {
        if ($failAudit) { $failAudit = false; throw new RuntimeException('fixture-only secret failure detail'); }
    });
    Storage::disk('sftp_test_local')->put('backups/accounting.dump', $payload);
    $accountingFile = $newFile(['original_name' => 'accounting-failure.dump', 'path' => 'backups/accounting.dump']);
    $accounting = $submit->submit($staff, $accountingFile->id, $sftpTarget->id);
    $runNext();
    sourceCheck($accounting->fresh()->status === TransferStatus::Uncertain && $accountingFile->downloads()->count() === 0
        && file_get_contents($sshPath.'/incoming/accounting-failure.dump') === $payload
        && is_file($sshPath.'/incoming/.projectsend-'.$accounting->fresh()->attempt_id.'.upload/writer/ready')
        && ! str_contains($accounting->fresh()->error, 'secret'), 'Accounting failure rolls back the download log and retains an uncertain publication');

    $mismatchFile = $newFile(['original_name' => 'changed-bytes.dump', 'path' => 'backups/changed-bytes.dump']);
    Storage::disk('sftp_test_local')->put('backups/changed-bytes.dump', str_repeat('X', strlen($payload)));
    $mismatch = $submit->submit($staff, $mismatchFile->id, $sftpTarget->id);
    $runNext();
    sourceCheck($mismatch->fresh()->status === TransferStatus::Uncertain && $mismatchFile->downloads()->count() === 0
        && ! file_exists($sshPath.'/incoming/changed-bytes.dump'), 'Changed source bytes cannot publish or spend allowance');

    require __DIR__.'/recovery-sftp.php';

    $largePayload = str_repeat("queued interruption fixture\0", 100000);
    Storage::disk('sftp_test_local')->put('backups/interruption.dump', $largePayload);
    $interruptedFile = $newFile([
        'original_name' => 'queued-interruption.dump', 'path' => 'backups/interruption.dump',
        'size' => strlen($largePayload), 'checksum' => hash('sha256', $largePayload),
    ]);
    $interruptedAttempt = $submit->submit($staff, $interruptedFile->id, $sftpTarget->id);
    $interrupt = true;
    $runNext();
    sourceCheck($interruptedAttempt->fresh()->status === TransferStatus::Uncertain
        && $interruptedFile->downloads()->count() === 0 && ! file_exists($sshPath.'/incoming/queued-interruption.dump')
        && DB::table('jobs')->where('queue', 'sftp')->count() === 0,
        'A queued transfer interrupted by the real SSH server is uncertain, unaccounted, and never automatically retried');
    }
} finally {
    if (is_resource($sshServer)) { proc_terminate($sshServer); proc_close($sshServer); }
}
