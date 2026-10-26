<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ProjectSendSftp\RequestTransfer;
use ProjectSendSftp\Transfer;
use ProjectSendSftp\TransferBackup;
use ProjectSendSftp\TransferStatus;

(function () use ($app, $staff, $local, $adminDestination, $workflowHttp, $formValue): void {
    DB::beginTransaction();
    try {
        $submit = $app->make(RequestTransfer::class);
        $row = $submit->submit($staff, $local->id, $adminDestination->id);
        $cancel = fn (array $extra = []) => Artisan::call('sftp:cancel', ['transfer' => $row->id] + $extra);
        sourceCheck(Artisan::call('sftp:cancel', ['transfer' => 'invalid']) === 1
            && Artisan::call('sftp:cancel', ['transfer' => (string) Str::uuid()]) === 1,
            'Cancellation rejects malformed and unknown UUIDs');
        foreach ([TransferStatus::Queued, TransferStatus::Running, TransferStatus::Uncertain, TransferStatus::Completed] as $state) {
            $row->update(['status' => $state]);
            $saved = $row->fresh()->getRawOriginal();
            sourceCheck($cancel(['--workers-stopped' => true]) === 1 && $row->fresh()->getRawOriginal() === $saved,
                'Cancellation leaves '.$state->value.' records unchanged');
        }
        $row->update(['status' => TransferStatus::Failed, 'lease_expires_at' => now()->addMinute()]);
        sourceCheck($cancel(['--workers-stopped' => true]) === 1 && $row->fresh() !== null,
            'Cancellation refuses a failed record with an active execution lease');
        $row->update(['lease_expires_at' => null, 'started_at' => now()]);
        sourceCheck($cancel(['--workers-stopped' => true]) === 1, 'Cancellation refuses legacy started work without an attempt identity');
        $row->update(['attempt_id' => (string) Str::uuid(), 'attempts' => 1]);
        sourceCheck($cancel() === 1 && str_contains(Artisan::output(), '--workers-stopped'),
            'Remote artifact removal requires explicit confirmation of terminated workers and SSH sessions');
        $row->update(['attempts' => 2, 'previous_attempt_ids' => null]);
        sourceCheck($cancel(['--workers-stopped' => true]) === 1 && str_contains(Artisan::output(), 'incomplete attempt history'),
            'Legacy multi-attempt records cannot be deleted by guessing their abandoned paths');
        $row->update(['attempts' => 1, 'remote_identity' => null]);
        sourceCheck($cancel(['--workers-stopped' => true]) === 1 && str_contains(Artisan::output(), 'original destination'),
            'Cancellation refuses an unknown endpoint before connecting');
        $row->update(['attempt_id' => null, 'attempts' => 0, 'started_at' => null, 'destination_id' => null]);
        $downloads = $local->downloads()->count();
        $failDelete = true;
        Transfer::deleting(function (Transfer $deleting) use ($row, &$failDelete): void {
            if ($deleting->id === $row->id && $failDelete) {
                $failDelete = false;
                throw new RuntimeException('fixture-only secret database detail');
            }
        });
        sourceCheck($cancel() === 1 && $row->fresh() !== null
            && ! DB::table('sftp_cancelled_transfers')->where('id', $row->id)->exists()
            && ! str_contains(Artisan::output(), 'secret'),
            'Database failure rolls back deletion and the replay guard without leaking internal details');
        sourceCheck(Artisan::call('sftp:cancel', ['transfer' => strtoupper($row->id)]) === 0
            && $row->fresh() === null && DB::table('sftp_cancelled_transfers')->where('id', $row->id)->exists()
            && $local->downloads()->count() === $downloads,
            'A never-started failed transfer can be removed without a destination or changing accounting');
        sourceCheck($cancel() === 0, 'Repeated cancellation succeeds without recreating transfer data');
        (new TransferBackup($row->id, true))->handle($app->make(ProjectSendSftp\TransferRunner::class));
        sourceCheck($row->fresh() === null && $local->downloads()->count() === $downloads,
            'Delayed retry jobs for a deleted transfer are harmless');
        try {
            $submit->submit($staff, $local->id, $adminDestination->id, $row->id);
            throw new RuntimeException('Cancelled form replay was accepted.');
        } catch (ValidationException) {
            sourceCheck($row->fresh() === null, 'A replayed send form cannot revive a cancelled UUID');
        }
        $app->instance('env', 'local');
        try {
            $review = $workflowHttp($staff, 'GET', '/sftp/transfers/review/'.$local->id);
            $input = ['_token' => $formValue($review->getContent(), '_token'), '_request_id' => $row->id,
                'file_id' => $local->id, 'destination_id' => $adminDestination->id];
            sourceCheck($workflowHttp($staff, 'POST', '/sftp/transfers', $input, ['HTTP_ACCEPT' => 'application/json'])->getStatusCode() === 422
                && $workflowHttp($staff, 'GET', '/sftp/transfers/'.$row->id)->getStatusCode() === 404
                && $workflowHttp($staff, 'GET', '/sftp/transfers/'.$row->id.'/status')->getStatusCode() === 404
                && ! str_contains($workflowHttp($staff, 'GET', '/sftp/transfers')->getContent(), $row->id),
                'Cancelled transfers disappear from staff HTTP history/detail/status and old send forms are refused');
        } finally { $app->instance('env', 'testing'); }
        sourceCheck($submit->submit($staff, $local->id, $adminDestination->id)->status === TransferStatus::Queued,
            'Cancellation does not block a fresh authorised request for the same backup');
    } finally {
        DB::rollBack();
    }
})();
