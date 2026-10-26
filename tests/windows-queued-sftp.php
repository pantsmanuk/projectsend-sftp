<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use ProjectSendSftp\Destination;
use ProjectSendSftp\Transfer;
use ProjectSendSftp\TransferStatus;

(function () use ($fixturePath, $payload, $newFile, $submit, $staff, $runNext, $app, $workflowHttp, $formValue): void {
    $path = $fixturePath.'/windows-permission-server';
    $server = null;
    try {
        [$server, $username, $port] = startSftpFixture($path, true);
        $target = Destination::create([
            'name' => 'Windows ACL queue fixture', 'host' => '127.0.0.1', 'port' => $port, 'username' => $username,
            'directory' => $path.'/incoming', 'enabled' => true,
            'host_fingerprint' => fixtureFingerprint($path.'/host-ed25519.pub'),
            'private_key' => file_get_contents($path.'/login'), 'private_key_passphrase' => ' fixture passphrase ',
        ]);
        $file = $newFile(['original_name' => 'windows-retry.dump']);
        $transfer = $submit->submit($staff, $file->id, $target->id);
        $runNext();
        sourceCheck($transfer->fresh()->status === TransferStatus::Uncertain
            && $transfer->fresh()->error === 'Could not set permissions on the uploaded backup.'
            && $file->downloads()->count() === 0 && ! file_exists($path.'/incoming/windows-retry.dump')
            && DB::table('jobs')->where('queue', 'sftp')->count() === 0,
            'Windows no-op chmod leaves the queued strict-policy attempt uncertain, unpublished, unaccounted, and without automatic retry');
        $oldAttempt = $transfer->fresh()->attempt_id;
        $identity = $transfer->fresh()->remote_identity;
        $target->update(['windows_acl_managed' => true]);
        $unsafe = $newFile(['original_name' => 'windows.dump:stream']);
        try {
            $submit->submit($staff, $unsafe->id, $target->id);
            throw new RuntimeException('Windows alternate stream was queued.');
        } catch (ProjectSendSftp\SftpTransferException) {
            sourceCheck(! Transfer::query()->where('file_id', $unsafe->id)->exists()
                && DB::table('jobs')->where('queue', 'sftp')->count() === 0,
                'Windows alternate-stream filenames are refused before creating a transfer or dispatching work');
        }
        file_put_contents($path.'/chmod-requests.log', '');
        touch($path.'/deny-rename');
        Artisan::call('sftp:recover', ['transfer' => $transfer->id, '--retry' => true]);
        $runNext();
        sourceCheck($transfer->fresh()->status === TransferStatus::Uncertain && $transfer->fresh()->attempts === 1
            && $transfer->fresh()->error === 'Could not isolate the previous writer.' && $file->downloads()->count() === 0,
            'Windows retry refuses to upload or account when the previous writer cannot be fenced');
        unlink($path.'/deny-rename');
        sourceCheck(Artisan::call('sftp:recover', ['transfer' => $transfer->id, '--retry' => true]) === 0,
            'Existing failed Windows transfer is eligible for checked CLI retry after administrator opt-in');
        $runNext();
        $done = $transfer->fresh();
        sourceCheck($done->status === TransferStatus::Completed && $done->attempts === 2 && $done->attempt_id !== $oldAttempt
            && $done->remote_identity === $identity && $file->downloads()->count() === 1
            && $done->bytes_sent === strlen($payload) && file_get_contents($done->remote_path) === $payload
            && is_file($path.'/incoming/.projectsend-'.$oldAttempt.'.upload/writer')
            && ! file_exists($path.'/incoming/.projectsend-'.$oldAttempt.'.upload/sealed/payload.part')
            && ! file_exists($path.'/incoming/.projectsend-'.$done->attempt_id.'.upload')
            && file_get_contents($path.'/chmod-requests.log') === '',
            'Windows policy change safely recovers the old attempt, restarts upload, publishes exact bytes, and accounts once without chmod');
        Artisan::call('sftp:recover', ['transfer' => $transfer->id, '--retry' => true]);
        $runNext();
        sourceCheck(DB::table('jobs')->where('queue', 'sftp')->count() === 0 && $file->downloads()->count() === 1,
            'Completed Windows delivery cannot be retried or accounted twice');

        $webFile = $newFile(['original_name' => 'windows-web.dump']);
        $app->instance('env', 'local');
        try {
            $review = $workflowHttp($staff, 'GET', '/sftp/transfers/review/'.$webFile->id);
            $input = ['_token' => $formValue($review->getContent(), '_token'),
                '_request_id' => $formValue($review->getContent(), '_request_id'),
                'file_id' => $webFile->id, 'destination_id' => $target->id];
            sourceCheck($workflowHttp($staff, 'POST', '/sftp/transfers', $input)->getStatusCode() === 303,
                'Staff HTTP review submits a Windows ACL destination to the dedicated queue');
            touch($path.'/deny-cleanup-rmdir');
            $runNext();
            $status = $workflowHttp($staff, 'GET', '/sftp/transfers/'.$input['_request_id'].'/status', [], ['HTTP_ACCEPT' => 'application/json']);
            sourceCheck(json_decode($status->getContent(), true)['data']['status'] === 'completed'
                && Transfer::findOrFail($input['_request_id'])->bytes_sent === strlen($payload)
                && file_get_contents($path.'/incoming/windows-web.dump') === $payload
                && $webFile->downloads()->count() === 1 && file_get_contents($path.'/chmod-requests.log') === '',
                'Staff HTTP-to-queue-to-SFTP Windows ACL delivery completes with exact bytes, status, and one accounting entry');
            $done = Transfer::findOrFail($input['_request_id']);
            $root = $path.'/incoming/.projectsend-'.$done->attempt_id.'.upload';
            $saved = $done->getRawOriginal();
            sourceCheck($done->error === null && is_dir($root.'/writer') && ! file_exists($root.'/writer/ready')
                && is_file($root.'/manifest'), 'Windows rmdir failure leaves a completed delivery and resumable partial cleanup');
            unlink($path.'/deny-cleanup-rmdir');
            Artisan::call('sftp:recover', ['transfer' => $done->id]);
            $runNext();
            clearstatcache();
            sourceCheck(! file_exists($root) && $done->fresh()->getRawOriginal() === $saved
                && $webFile->downloads()->count() === 1 && file_get_contents($done->remote_path) === $payload,
                'Explicit completed recovery resumes Windows cleanup without uploading or accounting again');
        } finally {
            $app->instance('env', 'testing');
        }
        $cancelFile = $newFile(['original_name' => 'windows-cancel.dump', 'path' => 'backups/windows-cancel.dump']);
        Illuminate\Support\Facades\Storage::disk('sftp_test_local')->put($cancelFile->path, str_repeat('X', strlen($payload)));
        $cancelRow = $submit->submit($staff, $cancelFile->id, $target->id);
        $runNext();
        Artisan::call('sftp:recover', ['transfer' => $cancelRow->id]);
        $runNext();
        sourceCheck($cancelRow->fresh()->status === TransferStatus::Failed, 'Windows cancellation fixture is reconciled and unpublished');
        $cancelRoot = $path.'/incoming/.projectsend-'.$cancelRow->fresh()->attempt_id.'.upload';
        touch($path.'/deny-cleanup-rmdir');
        sourceCheck(Artisan::call('sftp:cancel', ['transfer' => $cancelRow->id, '--workers-stopped' => true]) === 1
            && $cancelRow->fresh() !== null && is_file($cancelRoot.'/manifest') && is_dir($cancelRoot.'/sealed'),
            'Windows rmdir refusal retains the failed transfer and identity after partial cancellation cleanup');
        unlink($path.'/deny-cleanup-rmdir');
        sourceCheck(Artisan::call('sftp:cancel', ['transfer' => $cancelRow->id, '--workers-stopped' => true]) === 0,
            'Windows cancellation resumes safely after a partial directory-removal failure');
        clearstatcache();
        sourceCheck($cancelRow->fresh() === null && ! file_exists($cancelRoot) && $cancelFile->downloads()->count() === 0
            && file_get_contents($path.'/incoming/windows-web.dump') === $payload,
            'Windows cancellation removes only the failed attempt while preserving delivered backups and accounting');
    } finally {
        if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    }
})();
