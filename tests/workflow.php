<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ProjectSendSftp\Destination;
use ProjectSendSftp\Transfer;
use ProjectSendSftp\TransferStatus;

// Actual host HTTP middleware, sessions, policies, source metadata and queue.
// Local environment deliberately enables CSRF checks otherwise skipped in tests.
$app->instance('env', 'local');
$app->make('config')->set('queue.default', 'database');
$workflowCookies = [];
$workflowHttp = function (?User $actor, string $method, string $path, array $input = [], array $headers = []) use ($app, &$workflowCookies) {
    Auth::forgetGuards();
    if ($actor !== null) { Auth::guard('web')->setUser($actor); }
    $request = Request::create('http://localhost'.$path, $method, $input, $workflowCookies, [], array_replace(['HTTP_ACCEPT' => 'text/html'], $headers));
    $kernel = $app->make(Kernel::class);
    $response = $kernel->handle($request);
    foreach ($response->headers->getCookies() as $cookie) { $workflowCookies[$cookie->getName()] = $cookie->getValue(); }
    $kernel->terminate($request, $response);
    return $response;
};
$formValue = function (string $html, string $name): string {
    preg_match('/name="'.preg_quote($name, '/').'" value="([^"]+)"/', $html, $match);
    return $match[1] ?? throw new RuntimeException('Missing form field '.$name);
};
$review = $workflowHttp($staff, 'GET', '/sftp/transfers/review/'.$local->id);
sourceCheck($review->getStatusCode() === 200 && str_contains($review->getContent(), 'Review transfer')
    && str_contains($review->getContent(), '<select') && ! str_contains($review->getContent(), 'secret.internal')
    && ! str_contains($review->getContent(), $disabledDestination->name), 'Staff review renders a native enabled-destination selector without connection details');
$input = ['_token' => $formValue($review->getContent(), '_token'), '_request_id' => $formValue($review->getContent(), '_request_id'), 'file_id' => $local->id, 'destination_id' => $adminDestination->id];
sourceCheck($workflowHttp($staff, 'POST', '/sftp/transfers', array_replace($input, ['_token' => 'bad']))->getStatusCode() === 419
    && Transfer::count() === 0, 'Staff submission requires a valid CSRF token');
$created = $workflowHttp($staff, 'POST', '/sftp/transfers', $input);
sourceCheck($created->getStatusCode() === 303 && Transfer::count() === 1 && DB::table('jobs')->count() === 1, 'Staff HTTP submission records and queues one transfer');
$id = $input['_request_id'];
$path = '/sftp/transfers/'.$id;
sourceCheck($workflowHttp($staff, 'POST', '/sftp/transfers', $input)->headers->get('Location') === $created->headers->get('Location')
    && Transfer::count() === 1 && DB::table('jobs')->count() === 1, 'Replayed send form returns the same transfer without another queued job');
$jsonHeaders = ['HTTP_ACCEPT' => 'application/json'];
foreach ([array_replace($input, ['file_id' => $ownScoped->id]), array_replace($input, ['destination_id' => $disabledDestination->id])] as $collision) {
    sourceCheck($workflowHttp($staff, 'POST', '/sftp/transfers', $collision, $jsonHeaders)->getStatusCode() === 422
        && Transfer::findOrFail($id)->status === TransferStatus::Queued && DB::table('jobs')->count() === 1,
        'A request UUID cannot be reused for another file or destination or revoke existing work');
}
sourceCheck($workflowHttp($owner, 'POST', '/sftp/transfers', $input, $jsonHeaders)->getStatusCode() === 422, 'Another requester cannot reuse a send UUID');
$adminRole = App\Modules\Identity\Models\Role::create(['name' => 'Workflow admin', 'is_administrator' => true]);
$workflowAdmin = $newUser('workflow-admin', $adminRole);
foreach ([$owner, $workflowAdmin] as $other) {
    sourceCheck($workflowHttp($other, 'GET', $path, [], $jsonHeaders)->getStatusCode() === 404
        && $workflowHttp($other, 'GET', $path.'/status', [], $jsonHeaders)->getStatusCode() === 404
        && ! str_contains($workflowHttp($other, 'GET', '/sftp/transfers')->getContent(), $id),
        'Own history and direct transfer URLs stay private even from configuration administrators');
}
foreach ([null, $client, (clone $staff)->forceFill(['active' => false]), (clone $staff)->forceFill(['expires_at' => now()->subDay()])] as $denied) {
    sourceCheck(in_array($workflowHttp($denied, 'GET', '/sftp/transfers', [], $jsonHeaders)->getStatusCode(), [302, 401, 403, 419], true)
        && in_array($workflowHttp($denied, 'POST', '/sftp/transfers', $input, $jsonHeaders)->getStatusCode(), [302, 401, 403, 419], true), 'Ineligible accounts cannot read history or submit transfers');
}
$renewed = $workflowHttp($staff, 'GET', '/sftp/transfers/review/'.$local->id);
$input['_token'] = $formValue($renewed->getContent(), '_token');
foreach ([[$staff, $trashed->id, 404], [$staff, PHP_INT_MAX, 404], [$scoped, $local->id, 404], [$noFiles, $local->id, 404], [$staff, $blocked[0]->id, 423], [$staff, $capped->id, 403]] as [$actor, $fileId, $status]) {
    sourceCheck($workflowHttp($actor, 'GET', '/sftp/transfers/review/'.$fileId, [], $jsonHeaders)->getStatusCode() === $status
        && $workflowHttp($actor, 'POST', '/sftp/transfers', array_replace($input, ['_request_id' => (string) Str::uuid(), 'file_id' => $fileId]), $jsonHeaders)->getStatusCode() === $status,
        'Direct review and forged submission preserve current file scope, availability and allowance');
}
foreach ([$disabledDestination->id, PHP_INT_MAX] as $destinationId) {
    sourceCheck($workflowHttp($staff, 'POST', '/sftp/transfers', array_replace($input, ['_request_id' => (string) Str::uuid(), 'destination_id' => $destinationId]), $jsonHeaders)->getStatusCode() === 422
        && Transfer::count() === 1, 'Disabled or forged destination cannot queue work');
}
sourceCheck($workflowHttp($staff, 'GET', '/system/settings/sftp')->getStatusCode() === 403
    && ! str_contains($review->getContent(), '>Destinations</a>'), 'Staff workflow and administrator configuration remain separate');
$show = $workflowHttp($staff, 'GET', $path);
sourceCheck($show->getStatusCode() === 200 && str_contains($show->getContent(), 'Waiting to start')
    && str_contains($show->headers->get('Cache-Control'), 'no-store'), 'Own detail renders queued state and prevents caching');
foreach (TransferStatus::cases() as $state) {
    Transfer::whereKey($id)->update(['status' => $state, 'bytes_sent' => strlen($payload), 'error' => 'private.internal /secret/path key-secret']);
    $statusResponse = $workflowHttp($staff, 'GET', $path.'/status', [], $jsonHeaders);
    $summary = json_decode($statusResponse->getContent(), true)['data'];
    sourceCheck($statusResponse->getStatusCode() === 200 && $summary['status'] === $state->value && $summary['percent'] === 100
        && array_intersect(['error', 'source_path', 'source_disk', 'remote_path', 'remote_identity', 'actor_snapshot', 'sha256', 'destination_revision', 'execution_token'], array_keys($summary)) === []
        && ! str_contains($statusResponse->getContent(), 'key-secret'), 'Status '.$state->value.' exposes whitelisted progress and safe messages only');
}
// Duration excludes queue waiting, survives refresh, and stops at persisted finish.
$clock = Illuminate\Support\Carbon::parse('2026-10-21 12:00:00 UTC');
Illuminate\Support\Carbon::setTestNow($clock);
try {
    foreach ([
        [TransferStatus::Queued, null, null, null, ''],
        [TransferStatus::Running, $clock->copy()->subSeconds(3661), null, 3661, '01:01:01'],
        [TransferStatus::Completed, $clock->copy()->subDays(2), $clock->copy()->subHours(23), 90000, '25:00:00'],
        [TransferStatus::Failed, $clock->copy()->subSeconds(120), $clock->copy()->subSeconds(60), 60, '00:01:00'],
        [TransferStatus::Uncertain, $clock->copy()->subSeconds(120), $clock->copy()->subSeconds(60), 60, '00:01:00'],
        [TransferStatus::Failed, null, $clock, null, ''],
    ] as [$state, $started, $finished, $seconds, $text]) {
        Transfer::whereKey($id)->update(['status' => $state, 'created_at' => $clock->copy()->subDays(3),
            'started_at' => $started, 'finished_at' => $finished]);
        $timing = json_decode($workflowHttp($staff, 'GET', $path.'/status', [], $jsonHeaders)->getContent(), true)['data'];
        sourceCheck($timing['elapsed_seconds'] === $seconds && $timing['elapsed_text'] === $text,
            'Duration for '.$state->value.' uses actual execution timestamps, including durations over 24 hours');
        if ($state === TransferStatus::Completed) {
            sourceCheck(str_contains($workflowHttp($staff, 'GET', $path)->getContent(), '25:00:00')
                && str_contains($workflowHttp($staff, 'GET', '/sftp/transfers')->getContent(), '25:00:00'),
                'Finished duration persists in delivery status and My transfers');
        }
    }
} finally {
    Illuminate\Support\Carbon::setTestNow();
    Transfer::whereKey($id)->update(['status' => TransferStatus::Queued, 'created_at' => now(), 'started_at' => null, 'finished_at' => null]);
}
$search = $workflowHttp($staff, 'GET', '/sftp/transfers/create?q=Pagination%20fixture&page=2');
sourceCheck($search->getStatusCode() === 200 && str_contains($search->getContent(), 'Page 2 of 2'), 'Staff file search preserves URL query and pagination');
sourceCheck($workflowHttp($staff, 'GET', '/sftp/transfers/create?q=Pagination%20fixture&page=999')->isRedirection()
    && str_contains($workflowHttp($staff, 'GET', '/sftp/transfers/create?q=no-matching-backup')->getContent(), 'No matching backups')
    && str_contains($workflowHttp($noFiles, 'GET', '/sftp/transfers/create')->getContent(), 'No backups available'), 'Out-of-range, no-results, and empty library states are usable');
$inertia = $workflowHttp($staff, 'GET', '/sftp/transfers', [], ['HTTP_X_INERTIA' => 'true']);
sourceCheck($inertia->getStatusCode() === 409 && $inertia->headers->get('X-Inertia-Location') === 'http://localhost/sftp/transfers', 'Host sidebar hands off staff workflow to a same-tab full page');
$adminDestination->update(['enabled' => false]);
sourceCheck(str_contains($workflowHttp($staff, 'GET', '/sftp/transfers/review/'.$local->id)->getContent(), 'No destinations available'), 'Review handles an empty approved destination list');
$adminDestination->update(['enabled' => true]);
$unsafeName = '<img src=x onerror=alert(1)>';
Transfer::whereKey($id)->update(['file_name' => $unsafeName]);
$escapedHistory = $workflowHttp($staff, 'GET', '/sftp/transfers')->getContent();
sourceCheck(str_contains($escapedHistory, '&lt;img') && ! str_contains($escapedHistory, $unsafeName), 'History escapes untrusted backup names');
for ($i = 0; $i < 20; $i++) {
    $copy = Transfer::findOrFail($id)->replicate();
    $copy->id = (string) Str::uuid();
    $copy->save();
}
sourceCheck(str_contains($workflowHttp($staff, 'GET', '/sftp/transfers?page=2')->getContent(), 'Page 2 of 2')
    && $workflowHttp($staff, 'GET', '/sftp/transfers?page=99')->isRedirection(), 'Own history is bounded and clamps stale page numbers');
sourceCheck($workflowHttp($staff, 'POST', '/sftp/transfers', array_replace($input, ['_request_id' => 'invalid']), $jsonHeaders)->getStatusCode() === 422
    && $workflowHttp($staff, 'GET', '/sftp/transfers/create?page=0', [], $jsonHeaders)->getStatusCode() === 422
    && $workflowHttp($staff, 'GET', '/sftp/transfers/create?q[]=invalid', [], $jsonHeaders)->getStatusCode() === 422, 'Staff routes validate request IDs and search/page boundaries');
// Six calendar months, including month-end boundaries; unresolved work stays visible.
$historyClock = Illuminate\Support\Carbon::parse('2026-08-31 12:00:00 UTC');
Illuminate\Support\Carbon::setTestNow($historyClock);
DB::beginTransaction();
try {
    $historyTemplate = Transfer::findOrFail($id)->getAttributes();
    Transfer::query()->delete();
    $cutoff = $historyClock->copy()->subMonthsNoOverflow(6);
    $historyIds = [];
    foreach ([
        ['boundary', TransferStatus::Completed, $cutoff, $staff],
        ['recent-failed', TransferStatus::Failed, $cutoff->copy()->addSecond(), $staff],
        ['old-completed', TransferStatus::Completed, $cutoff->copy()->subSecond(), $staff],
        ['old-failed', TransferStatus::Failed, $cutoff->copy()->subSecond(), $staff],
        ['old-queued', TransferStatus::Queued, $cutoff->copy()->subYear(), $staff],
        ['old-running', TransferStatus::Running, $cutoff->copy()->subYear(), $staff],
        ['old-uncertain', TransferStatus::Uncertain, $cutoff->copy()->subYear(), $staff],
        ['other-recent', TransferStatus::Completed, $historyClock, $owner],
        ['other-unresolved', TransferStatus::Uncertain, $cutoff->copy()->subYear(), $owner],
    ] as [$name, $state, $createdAt, $actor]) {
        $copy = new Transfer($historyTemplate);
        $copy->id = (string) Str::uuid();
        $copy->forceFill(['file_name' => $name, 'status' => $state, 'created_at' => $createdAt,
            'requested_by' => $actor->id])->save();
        $historyIds[$name] = $copy->id;
    }
    $history = $workflowHttp($staff, 'GET', '/sftp/transfers')->getContent();
    foreach ($historyIds as $name => $historyId) {
        $visible = in_array($name, ['boundary', 'recent-failed', 'old-queued', 'old-running', 'old-uncertain'], true);
        sourceCheck(str_contains($history, $historyId) === $visible, 'History cutoff and requester scope: '.$name);
    }
    sourceCheck(Transfer::count() === 9
        && $workflowHttp($staff, 'GET', '/sftp/transfers/'.$historyIds['old-completed'])->getStatusCode() === 200,
        'Hidden history remains stored and accessible through its own detail URL');
    for ($i = 0; $i < 16; $i++) {
        $copy = Transfer::findOrFail($historyIds['boundary'])->replicate();
        $copy->id = (string) Str::uuid();
        $copy->save();
    }
    $secondPage = $workflowHttp($staff, 'GET', '/sftp/transfers?page=2');
    sourceCheck($secondPage->getStatusCode() === 200 && str_contains($secondPage->getContent(), 'Page 2 of 2')
        && ! str_contains($secondPage->getContent(), $historyIds['old-completed'])
        && ! str_contains($secondPage->getContent(), $historyIds['other-unresolved']),
        'History cutoff applies before pagination without leaking other requesters');
    Transfer::query()->whereNotIn('id', [$historyIds['old-completed'], $historyIds['old-failed']])->delete();
    sourceCheck(str_contains($workflowHttp($staff, 'GET', '/sftp/transfers')->getContent(), 'No transfers yet')
        && $workflowHttp($staff, 'GET', '/sftp/transfers?page=2')->isRedirection()
        && Transfer::count() === 2, 'An entirely hidden history renders empty and clamps stale pages without deleting records');
} finally {
    DB::rollBack();
    Illuminate\Support\Carbon::setTestNow();
}
$workflowHttp($staff, 'GET', '/sftp/transfers');
$navigation = new App\Modules\Platform\Navigation\Events\ResolvingNavigationLinks(isStaff: true);
Illuminate\Support\Facades\Event::dispatch($navigation);
sourceCheck(in_array('/sftp/transfers', array_column($navigation->links, 'url'), true)
    && ! in_array('/system/settings/sftp', array_column($navigation->links, 'url'), true), 'Ordinary staff receive the transfer sidebar link without the configuration link');
DB::table('jobs')->delete();
Transfer::query()->delete();
$workflowAdmin->delete();
$app->instance('env', 'testing');
