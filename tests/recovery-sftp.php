<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use ProjectSendSftp\Transfer;
use ProjectSendSftp\TransferStatus;

$recover = function (Transfer $row, bool $retry = false) use ($runNext): void {
    sourceCheck(Artisan::call('sftp:recover', ['transfer' => $row->id, '--retry' => $retry]) === 0,
        'Recovery command queues the selected transfer without exposing credentials');
    $runNext();
};
// The ready receipt plus missing staged payload proves rename happened. Full
// readback must still reject an altered final file, including same-size edits.
$publishedId = $accounting->fresh()->attempt_id;
file_put_contents($sshPath.'/incoming/accounting-failure.dump', str_repeat('X', strlen($payload)));
$recover($accounting);
sourceCheck($accounting->fresh()->status === TransferStatus::Uncertain && $accountingFile->downloads()->count() === 0,
    'Reconciliation rejects a same-size remote checksum mismatch without spending allowance');
file_put_contents($sshPath.'/incoming/accounting-failure.dump', $payload);
$staff->update(['active' => false]);
$sftpTarget->update(['enabled' => false]);
$sftpTarget->update(['private_key' => phpseclib3\Crypt\PublicKeyLoader::loadPrivateKey(
    file_get_contents($sshPath.'/login'), ' fixture passphrase ',
)->withPassword(' fixture passphrase ')->toString('PKCS8')]);
$accountingFile->delete();
$recover($accounting);
if ($accounting->fresh()->status !== TransferStatus::Completed) {
    throw new RuntimeException('Publication recovery failed: '.$accounting->fresh()->error);
}
sourceCheck($accounting->fresh()->status === TransferStatus::Completed && $accountingFile->downloads()->count() === 1
    && $accounting->fresh()->attempt_id === $publishedId,
    'Recovery accounts an already published backup once despite later requester/file/destination ineligibility');
$staff->update(['active' => true]);
$sftpTarget->update(['enabled' => true]);
$recover($accounting);
sourceCheck($accountingFile->downloads()->count() === 1 && DB::table('jobs')->where('queue', 'sftp')->count() === 0,
    'Repeated reconciliation of completed delivery neither uploads nor accounts again');

$oldAttempt = $mismatch->fresh()->attempt_id;
$recover($mismatch);
$oldRoot = $sshPath.'/incoming/.projectsend-'.$oldAttempt.'.upload';
sourceCheck($mismatch->fresh()->status === TransferStatus::Failed && is_file($oldRoot.'/writer')
    && ! file_exists($oldRoot.'/sealed/payload.part'), 'Recovery fences and removes an abandoned partial before allowing retry');
$mismatchFile->update(['scan_status' => 'infected']);
$recover($mismatch, true);
sourceCheck($mismatch->fresh()->status === TransferStatus::Failed && $mismatch->fresh()->attempts === 1,
    'A retry rechecks source availability after remote reconciliation');
$mismatchFile->update(['scan_status' => 'clean']);
Illuminate\Support\Facades\Storage::disk('sftp_test_local')->put('backups/changed-bytes.dump', $payload);
$recover($mismatch, true);
sourceCheck($mismatch->fresh()->status === TransferStatus::Completed && $mismatch->fresh()->attempts === 2
    && $mismatch->fresh()->attempt_id !== $oldAttempt && $mismatchFile->downloads()->count() === 1,
    'An explicit retry uses a new attempt identity only after the old writer is fenced');

$collisionFile = $newFile(['original_name' => 'foreign-identical.dump']);
file_put_contents($sshPath.'/incoming/foreign-identical.dump', $payload);
$collision = $submit->submit($staff, $collisionFile->id, $sftpTarget->id);
$runNext();
$recover($collision, true);
if ($collision->fresh()->status !== TransferStatus::Uncertain || $collision->fresh()->attempts !== 1) {
    throw new RuntimeException('Collision recovery state: '.$collision->fresh()->status->value.'; attempts='.$collision->fresh()->attempts.'; '.$collision->fresh()->error);
}
sourceCheck($collision->fresh()->status === TransferStatus::Uncertain && $collisionFile->downloads()->count() === 0
    && file_get_contents($sshPath.'/incoming/foreign-identical.dump') === $payload && $collision->fresh()->attempts === 1,
    'An unrelated identical destination file is neither claimed as our delivery nor overwritten on retry');

$orphanFile = $newFile(['original_name' => 'dispatch-gap.dump']);
$orphan = $submit->submit($staff, $orphanFile->id, $sftpTarget->id);
DB::table('jobs')->where('queue', 'sftp')->delete();
$recover($orphan);
sourceCheck($orphan->fresh()->status === TransferStatus::Completed && $orphanFile->downloads()->count() === 1,
    'Recovery repairs a committed transfer whose queue dispatch was lost');

require __DIR__.'/recovery-workers.php';
