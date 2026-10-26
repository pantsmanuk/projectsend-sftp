<?php

declare(strict_types=1);

use App\Modules\Audit\Action;
use App\Modules\Files\Access\StaffLibraryScope;
use App\Modules\Files\Models\File;
use App\Modules\Identity\Permissions\PermissionChecker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ProjectSendSftp\Destination;
use ProjectSendSftp\RequestTransfer;
use ProjectSendSftp\Transfer;
use ProjectSendSftp\TransferBackup;
use ProjectSendSftp\TransferStatus;

// Included after sources.php: actual ProjectSend schema/users, isolated DB and storage.
$app->make('config')->set('queue.default', 'database');
$app->make('config')->set('queue.connections.database.retry_after', 3660);
$submit = $app->make(RequestTransfer::class);
$target = $adminDestination->replicate();
$target->forceFill(['name' => 'Queue-only fixture', 'host' => '127.0.0.1', 'port' => 9])->save();
$runNext = function () use ($app): void {
    $app->make('queue.worker')->runNextJob('database', 'sftp', new WorkerOptions(sleep: 0, maxTries: 1));
};
$rejectRequest = function (callable $request, string $label): void {
    $before = Transfer::count();
    $queued = DB::table('jobs')->count();
    $rejected = false;
    try { $request(); }
    catch (Throwable) {
        $rejected = true;
    }
    sourceCheck($rejected && Transfer::count() === $before && DB::table('jobs')->count() === $queued, $label);
};

$transfer = $submit->submit($staff, $local->id, $target->id);
$queued = DB::table('jobs')->where('queue', 'sftp')->first();
$command = unserialize(json_decode($queued->payload, true)['data']['command']);
sourceCheck($transfer->fresh()->status === TransferStatus::Queued && $transfer->fresh()->bytes_sent === 0
    && $command instanceof TransferBackup && $command->transferId === $transfer->id
    && $command->tries === 1 && $command->failOnTimeout && $command->afterCommit,
    'Submission persists a queued attempt and dispatches a single-attempt job on the dedicated queue');
sourceCheck(! str_contains($queued->payload, 'fixture-only-secret') && ! str_contains($queued->payload, 'private_key')
    && ! str_contains($queued->payload, 'source_path') && ! str_contains($queued->payload, '127.0.0.1'),
    'Queue payload contains the transfer ID and queue policy without credentials or source/connection details');
sourceCheck($local->downloads()->count() === 0, 'Submission does not spend download allowance');

// Every non-network case changes eligibility before the worker runs. The
// loopback port is deliberately not a configured or production SSH server.
$target->update(['enabled' => false]);
$runNext();
sourceCheck($transfer->fresh()->status === TransferStatus::Failed && $local->downloads()->count() === 0
    && DB::table('jobs')->where('queue', 'sftp')->count() === 0, 'Worker rejects a destination disabled after submission without accounting or retry');
$target->update(['enabled' => true]);

$beforeRollback = Transfer::count();
DB::beginTransaction();
$rolledBack = $submit->submit($staff, $local->id, $target->id);
sourceCheck(DB::table('jobs')->where('queue', 'sftp')->count() === 0, 'Dispatch waits for the outer database transaction to commit');
DB::rollBack();
sourceCheck(Transfer::count() === $beforeRollback && ! Transfer::query()->whereKey($rolledBack->id)->exists()
    && DB::table('jobs')->where('queue', 'sftp')->count() === 0, 'Rolling back submission creates neither a transfer record nor a queued job');

foreach (['null', 'sync'] as $driver) {
    $app->make('config')->set('queue.default', $driver);
    $rejectRequest(fn () => $submit->submit($staff, $local->id, $target->id), 'Submission refuses the '.$driver.' queue driver');
}
$app->make('config')->set('queue.default', 'database');
$rejectRequest(fn () => $submit->submit($client, $local->id, $target->id), 'Client cannot submit a transfer');
$rejectRequest(fn () => $submit->submit($noFiles, $local->id, $target->id), 'Staff without file access cannot submit a transfer');
$rejectRequest(fn () => $submit->submit($scoped, $local->id, $target->id), 'Staff cannot submit an out-of-scope file ID');
$rejectRequest(fn () => $submit->submit($staff, PHP_INT_MAX, $target->id), 'Forged source ID creates no transfer or queued work');
$rejectRequest(fn () => $submit->submit($staff, $local->id, PHP_INT_MAX), 'Forged destination ID creates no transfer or queued work');
$staleActor = $newUser('queue-stale-submitter', $staffRole);
DB::table('users')->where('id', $staleActor->id)->update(['active' => false]);
$rejectRequest(fn () => $submit->submit($staleActor, $local->id, $target->id), 'Submission reloads account state rather than trusting a stale actor model');
foreach ([['checksum' => ''], ['original_name' => '../escape.dump'], ['size' => -1]] as $attributes) {
    $invalid = $newFile($attributes);
    $rejectRequest(fn () => $submit->submit($staff, $invalid->id, $target->id), 'Invalid source metadata cannot enter the transfer queue');
}

foreach (['inactive', 'expired', 'deleted', 'client'] as $change) {
    $actor = $newUser('queue-'.$change, $staffRole);
    $attempt = $submit->submit($actor, $local->id, $target->id);
    match ($change) {
        'inactive' => $actor->update(['active' => false]),
        'expired' => $actor->forceFill(['expires_at' => now()->subDay()])->save(),
        'deleted' => $actor->delete(),
        'client' => $actor->update(['type' => 'client']),
    };
    $runNext();
    sourceCheck($attempt->fresh()->status === TransferStatus::Failed, 'Worker reloads and refuses a '.$change.' requester');
}

$permissionActor = $newUser('queue-permissions', $staffRole);
$attempt = $submit->submit($permissionActor, $local->id, $target->id);
$permissionActor->can('upload'); // Warm the host singleton, as a preceding job would.
DB::table('role_permission')->where('role_id', $staffRole->id)->delete();
$runNext();
sourceCheck($attempt->fresh()->status === TransferStatus::Failed, 'Worker clears cached permissions before rechecking a revoked role');
DB::table('role_permission')->insert(['role_id' => $staffRole->id, 'permission' => 'upload']);
$app->forgetInstance(PermissionChecker::class);

$scopeActor = $newUser('queue-scoping', $staffRole);
$attempt = $submit->submit($scopeActor, $local->id, $target->id);
$app->make(StaffLibraryScope::class)->files($scopeActor);
$scopeActor->update(['role_id' => $scopedRole->id]);
$runNext();
sourceCheck($attempt->fresh()->status === TransferStatus::Failed, 'Worker drops cached library scope and reloads the current requester role');

foreach ([['path' => 'backups/replaced.dump'], ['checksum' => str_repeat('a', 64)], ['size' => strlen($payload) + 1],
    ['original_name' => 'renamed.dump'], ['disk' => 'another-disk'], ['scan_status' => 'infected'], ['deleted_at' => now()]] as $attributes) {
    $file = $newFile();
    $attempt = $submit->submit($staff, $file->id, $target->id);
    DB::table('files')->where('id', $file->id)->update($attributes);
    $runNext();
    sourceCheck($attempt->fresh()->status === TransferStatus::Failed && $file->downloads()->count() === 0,
        'Worker refuses a changed or unavailable source: '.array_key_first($attributes));
}
$limited = $newFile(['download_limit' => 1]);
$attempt = $submit->submit($staff, $limited->id, $target->id);
DB::table('activity_log')->insert([
    'actor_id' => $staff->id, 'action' => Action::FileDownloaded->value, 'subject_type' => $limited->getMorphClass(),
    'subject_id' => $limited->id, 'created_at' => now(),
]);
$runNext();
sourceCheck($attempt->fresh()->status === TransferStatus::Failed && $limited->downloads()->count() === 1,
    'Worker rechecks an allowance spent while the job was waiting');

$missingSource = $newFile(['path' => 'backups/absent-queue-source.dump']);
$missingAttempt = $submit->submit($staff, $missingSource->id, $target->id);
$runNext();
sourceCheck($missingAttempt->fresh()->status === TransferStatus::Failed && $missingSource->downloads()->count() === 0,
    'Storage-read failure is recorded before any SFTP upload or download accounting');

$attempt = $submit->submit($staff, $local->id, $target->id);
$target->update(['directory' => '/changed']);
$runNext();
sourceCheck($attempt->fresh()->status === TransferStatus::Failed, 'Worker refuses changed destination configuration');

$running = $submit->submit($staff, $local->id, $target->id);
$leaseToken = (string) Illuminate\Support\Str::uuid();
$running->update(['status' => TransferStatus::Running, 'execution_token' => $leaseToken, 'lease_expires_at' => now()->addMinute()]);
DB::table('jobs')->where('queue', 'sftp')->update(['attempts' => 1]);
$runNext();
sourceCheck($running->fresh()->status === TransferStatus::Running && $running->fresh()->execution_token === $leaseToken,
    'A duplicate delivery exceeding queue attempts cannot mutate the active worker lease');
sourceCheck(Illuminate\Support\Facades\Artisan::call('sftp:recover', ['transfer' => $running->id, '--retry' => true]) === 0
    && DB::table('jobs')->where('queue', 'sftp')->count() === 0, 'Recovery command skips an active lease even when retry is requested');
sourceCheck(Illuminate\Support\Facades\Artisan::call('sftp:recover', ['transfer' => 'invalid']) === 1,
    'Recovery command rejects malformed transfer IDs');
$app->make('config')->set('queue.default', 'sync');
sourceCheck(Illuminate\Support\Facades\Artisan::call('sftp:recover', ['transfer' => $running->id]) === 1,
    'Recovery refuses synchronous queue execution');
$app->make('config')->set('queue.default', 'database');
$running->update(['remote_identity' => null, 'started_at' => now(), 'lease_expires_at' => now()->subSecond()]);
$app->make('queue')->connection('database')->push(new TransferBackup($running->id), [], 'sftp');
$runNext();
sourceCheck($running->fresh()->status === TransferStatus::Uncertain, 'Legacy in-flight attempts without remote identity are never guessed at or retried');

$legacyCompleted = $running->replicate();
$legacyCompleted->forceFill([
    'id' => (string) Illuminate\Support\Str::uuid(), 'status' => TransferStatus::Completed,
    'attempt_id' => null, 'execution_token' => null, 'lease_expires_at' => null,
])->save();
DB::beginTransaction();
try {
    Illuminate\Support\Facades\Artisan::call('sftp:recover');
    $ids = DB::table('jobs')->where('queue', 'sftp')->get()->map(
        fn ($job) => unserialize(json_decode($job->payload, true)['data']['command'])->transferId,
    )->all();
    sourceCheck(! in_array($legacyCompleted->id, $ids, true), 'Routine recovery scans exclude completed history');
} finally {
    DB::rollBack();
}
$savedCompleted = $legacyCompleted->fresh()->getRawOriginal();
sourceCheck(Illuminate\Support\Facades\Artisan::call('sftp:recover', ['transfer' => $legacyCompleted->id]) === 0
    && DB::table('jobs')->where('queue', 'sftp')->count() === 1, 'An explicit completed UUID can request staging cleanup');
$runNext();
sourceCheck($legacyCompleted->fresh()->getRawOriginal() === $savedCompleted,
    'Legacy completed records without an attempt identity are left unchanged without guessing remote paths');

$rejectEnqueue = true;
Illuminate\Support\Facades\Event::listen(Illuminate\Queue\Events\JobQueueing::class, function () use (&$rejectEnqueue): void {
    if ($rejectEnqueue) { $rejectEnqueue = false; throw new RuntimeException('fixture-only secret broker detail'); }
});
try {
    $submit->submit($staff, $local->id, $target->id);
    throw new LogicException('Queue failure accepted.');
} catch (ProjectSendSftp\SftpTransferException $exception) {
    sourceCheck($exception->getPrevious() === null && ! str_contains($exception->getMessage(), 'secret')
        && Transfer::query()->where('error', 'The transfer could not be queued.')->count() === 1
        && DB::table('jobs')->where('queue', 'sftp')->count() === 0,
        'Broker dispatch failures leave a safe failed record without leaking details or stranded queued work');
}
$attempt = $submit->submit($staff, $local->id, $target->id);
$targetName = $target->name;
$target->delete(); // Exercise FK history preservation even outside the controller.
$runNext();
sourceCheck($attempt->fresh()->status === TransferStatus::Failed && $attempt->fresh()->destination_id === null
    && $attempt->fresh()->destination_name === $targetName, 'Deleted destination refuses queued work and retains its history snapshot');

require __DIR__.'/cancel.php';

if (in_array('--sftp', $argv, true)) {
    require __DIR__.'/queued-sftp.php';
}

// The migration can roll back independently without dropping host records.
$transfersMigration = require $packagePath.'/database/migrations/2026_10_21_000001_create_sftp_transfers_table.php';
$recoveryMigration = require $packagePath.'/database/migrations/2026_10_21_000002_add_sftp_recovery_state.php';
$cancellationMigration = require $packagePath.'/database/migrations/2026_10_26_000000_add_sftp_cancellation_support.php';
$beforeCancellationRollback = Transfer::count();
$cancellationMigration->down();
sourceCheck(Transfer::count() === $beforeCancellationRollback
    && ! Illuminate\Support\Facades\Schema::hasTable('sftp_cancelled_transfers'),
    'Cancellation migration rolls back without deleting transfer history');
$cancellationMigration->up();
sourceCheck(Illuminate\Support\Facades\Schema::hasColumn('sftp_transfers', 'previous_attempt_ids')
    && Illuminate\Support\Facades\Schema::hasTable('sftp_cancelled_transfers'), 'Cancellation migration reapplies');
$beforeRecoveryRollback = Transfer::count();
$recoveryMigration->down();
sourceCheck(Transfer::count() === $beforeRecoveryRollback, 'Recovery migration rollback preserves transfer records');
$transfersMigration->down();
$transfersMigration->up();
$recoveryMigration->up();
sourceCheck(Transfer::count() === 0 && File::query()->whereKey($local->id)->exists() && Destination::count() > 0,
    'Transfer migration rolls back and reapplies without removing host files or destination configuration');
