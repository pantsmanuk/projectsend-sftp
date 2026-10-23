<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Audit\Action;
use App\Modules\Files\Models\File;
use App\Modules\Files\Scanning\ScanStatus;
use App\Modules\Identity\Models\Role;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem as Flysystem;
use ProjectSendSftp\Destination;
use ProjectSendSftp\TransferSource;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Included by host.php after an isolated real-host boot. Real host schemas,
// policies, and storage adapters are exercised without real users or services.
function sourceCheck(bool $condition, string $message): void
{
    if (! $condition) { throw new RuntimeException($message); }
    echo "PASS: {$message}\n";
}

$migrator = $app->make('migrator');
$migrator->getRepository()->createRepository();
$migrator->run([$hostPath.'/database/migrations', $packagePath.'/database/migrations']);

$staffRole = Role::create(['name' => 'Source test staff', 'client_scoped' => false, 'is_administrator' => false]);
$scopedRole = Role::create(['name' => 'Source test scoped staff', 'client_scoped' => true, 'is_administrator' => false]);
$emptyRole = Role::create(['name' => 'Source test no file access', 'client_scoped' => false, 'is_administrator' => false]);
foreach ([$staffRole, $scopedRole] as $role) {
    DB::table('role_permission')->insert(['role_id' => $role->id, 'permission' => 'upload']);
}
$newUser = function (string $name, Role $role, string $type = 'staff'): User {
    $id = DB::table('users')->insertGetId([
        'name' => $name, 'email' => $name.'@example.invalid', 'password' => 'unused-test-password',
        'type' => $type, 'active' => true, 'role_id' => $role->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    return User::findOrFail($id);
};
$staff = $newUser('source-staff', $staffRole);
$owner = $newUser('source-owner', $staffRole);
$scoped = $newUser('source-scoped', $scopedRole);
$client = $newUser('source-client', $staffRole, 'client');
$noFiles = $newUser('source-no-files', $emptyRole);
$payload = "test-only database backup\0\n";
Storage::disk('sftp_test_local')->put('backups/example.dump', $payload);
$newFile = function (array $attributes = []) use ($owner, $payload): File {
    $id = DB::table('files')->insertGetId(array_replace([
        'uploaded_by' => $owner->id, 'name' => 'Customer backup',
        'original_name' => 'customer.dump', 'path' => 'backups/example.dump',
        'slug' => 'source-test-'.bin2hex(random_bytes(8)),
        'disk' => 'sftp_test_local', 'mime_type' => 'application/octet-stream',
        'size' => strlen($payload), 'checksum' => hash('sha256', $payload),
        'scan_status' => ScanStatus::Clean->value, 'created_at' => now(), 'updated_at' => now(),
    ], $attributes));
    return File::withTrashed()->findOrFail($id);
};
$local = $newFile();
$ownScoped = $newFile(['uploaded_by' => $scoped->id, 'name' => 'Scoped backup', 'original_name' => 'scoped.dump']);
$blocked = [];
foreach ([ScanStatus::Pending, ScanStatus::Infected, ScanStatus::UnscannableBlocked, ScanStatus::Missing] as $status) {
    $blocked[] = $newFile(['name' => $status->value, 'scan_status' => $status->value]);
}
$trashed = $newFile(['deleted_at' => now()]);
$capped = $newFile(['download_limit' => 1]);
DB::table('activity_log')->insert([
    'actor_id' => $staff->id, 'action' => Action::FileDownloaded->value,
    'subject_type' => $capped->getMorphClass(), 'subject_id' => $capped->id, 'created_at' => now(),
]);

$source = $app->make(TransferSource::class);
sourceCheck(Gate::forUser($staff)->allows('sftp.transfer') && Gate::forUser($staff)->denies('sftp.manage-destinations'), 'Ordinary staff have transfer access without configuration access');
$listed = collect($source->files($staff)->items())->keyBy('id');
sourceCheck($listed->has($local->id) && $listed[$local->id]['selectable'], 'Picker includes readable files for non-administrator staff');
sourceCheck(collect($blocked)->every(fn (File $file) => ! $listed->has($file->id)) && ! $listed->has($trashed->id), 'Picker excludes pending, quarantined, missing, and deleted files');
sourceCheck(! $listed[$capped->id]['selectable'], 'Spent download allowance is visible as an unavailable choice');
sourceCheck(array_keys($listed[$local->id]) === ['id', 'name', 'original_name', 'size', 'selectable', 'download_allowance'], 'Picker metadata contains no storage paths, disks, credentials, or signed URLs');
sourceCheck($source->files($scoped)->total() === 1 && $source->files($scoped)->items()[0]['id'] === $ownScoped->id, 'Picker preserves real client-scoped staff library restrictions');
sourceCheck($source->files($noFiles)->total() === 0, 'Staff eligibility does not grant new ProjectSend file permissions');
sourceCheck($source->files($staff, 'Scoped')->total() === 1
    && $source->files($scoped, 'Customer')->total() === 0, 'Search remains inside the requesting staff library');

$closed = null;
$read = $source->withStream($staff, $local->id, function ($stream, File $file) use (&$closed, $local): string {
    $closed = $stream;
    sourceCheck($file->id === $local->id, 'Stream keeps the exact selected file ID');
    return stream_get_contents($stream);
});
sourceCheck($read === $payload && ! is_resource($closed), 'Local storage streams exact bytes and closes after success');
sourceCheck($source->withStream($owner, $capped->id, fn ($stream) => stream_get_contents($stream)) === $payload, 'Source reads preserve the existing uploader exemption from download caps');
try {
    $source->withStream($staff, $local->id, function ($stream) use (&$closed): void {
        $closed = $stream;
        throw new LogicException('test consumer failure');
    });
    throw new RuntimeException('Consumer failure was swallowed.');
} catch (LogicException) {
    sourceCheck(! is_resource($closed), 'Consumer failure propagates and still closes the source');
}

$mock = new MockHandler;
$mock->append(new Result(['Body' => Utils::streamFor($payload), 'ContentLength' => strlen($payload)]));
$s3 = new S3Client(['version' => 'latest', 'region' => 'us-east-1', 'credentials' => ['key' => 'fixture', 'secret' => 'fixture'], 'handler' => $mock]);
$adapter = new AwsS3V3Adapter($s3, 'fixture-bucket', 'fixture-prefix');
Storage::set('sftp_test_remote', new FilesystemAdapter(new Flysystem($adapter), $adapter, ['throw' => true]));
$remote = $newFile(['disk' => 'sftp_test_remote']);
sourceCheck($source->withStream($staff, $remote->id, fn ($stream) => stream_get_contents($stream)) === $payload
    && $mock->getLastCommand()->getName() === 'GetObject'
    && $mock->getLastCommand()['Key'] === 'fixture-prefix/backups/example.dump', 'Real S3/Flysystem adapter reads the selected object through a mocked AWS command');
$mock->append(new AwsException('fixture-only secret storage detail', $s3->getCommand('GetObject')));
try {
    $source->withStream($staff, $remote->id, fn () => throw new LogicException('Unopened source was consumed.'));
    throw new LogicException('Storage failure was ignored.');
} catch (RuntimeException $exception) {
    sourceCheck($exception->getMessage() === 'The selected file could not be read from storage.' && $exception->getPrevious() === null, 'Remote read failures expose no backend credentials or storage details');
}
$missing = $newFile(['path' => 'not-present.dump']);
try {
    $source->withStream($staff, $missing->id, fn () => throw new LogicException('Missing file was consumed.'));
    throw new LogicException('Missing storage file was ignored.');
} catch (RuntimeException $exception) {
    sourceCheck($exception->getMessage() === 'The selected file could not be read from storage.', 'Untracked missing storage files fail cleanly');
}

foreach ($blocked as $file) {
    try {
        $source->withStream($staff, $file->id, fn () => throw new LogicException('Unavailable file was opened.'));
        throw new LogicException('Unavailable source was allowed.');
    } catch (HttpException $exception) {
        sourceCheck($exception->getStatusCode() === 423, 'Stream rejects '.$file->scan_status->value.' before opening storage');
    }
}
foreach ([[$staff, $trashed->id], [$staff, PHP_INT_MAX], [$scoped, $local->id], [$noFiles, $local->id]] as [$actor, $id]) {
    try {
        $source->withStream($actor, $id, fn () => throw new LogicException('Forbidden file was opened.'));
        throw new LogicException('Forged or inaccessible file ID was accepted.');
    } catch (ModelNotFoundException) {
        // The existing library scope refuses direct IDs as well as picker rows.
    }
}
sourceCheck(true, 'Deleted, forged, out-of-scope, and unpermitted file IDs are refused');
try {
    $source->withStream($staff, $capped->id, fn () => throw new LogicException('Spent file was consumed.'));
    throw new LogicException('Download allowance was bypassed.');
} catch (HttpException $exception) {
    sourceCheck($exception->getStatusCode() === 403, 'Source reads enforce current download allowance');
}

$changed = $newFile();
$source->files($staff);
DB::table('files')->where('id', $changed->id)->update(['scan_status' => 'infected']);
try {
    $source->withStream($staff, $changed->id, fn () => throw new LogicException('Newly quarantined file was consumed.'));
    throw new LogicException('Picker-time availability was trusted.');
} catch (HttpException $exception) {
    sourceCheck($exception->getStatusCode() === 423, 'Stream rechecks availability after selection');
}

$adminDestination = Destination::create([
    'name' => 'Enabled fixture destination', 'host' => 'secret.internal', 'username' => 'secret-user',
    'directory' => '/secret/path', 'host_fingerprint' => 'SHA256:fixture', 'private_key' => 'fixture-only-secret', 'enabled' => true,
]);
$disabledDestination = $adminDestination->replicate();
$disabledDestination->name = 'Disabled fixture destination';
$disabledDestination->enabled = false;
$disabledDestination->save();
$http = function (?User $actor, string $path) use ($app) {
    Auth::forgetGuards();
    if ($actor !== null) { Auth::guard('web')->setUser($actor); }
    $request = Request::create('http://localhost'.$path, 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
    $kernel = $app->make(Kernel::class);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    return $response;
};
$destinations = $http($staff, '/sftp/destinations');
sourceCheck($destinations->getStatusCode() === 200 && json_decode($destinations->getContent(), true) === [
    'data' => [['id' => $adminDestination->id, 'name' => 'Enabled fixture destination']],
], 'Staff destination picker returns enabled destination IDs and names only');
$response = $http($staff, '/sftp/files?q=Scoped&page=1');
$json = json_decode($response->getContent(), true);
sourceCheck($response->getStatusCode() === 200 && $json['meta']['total'] === 1
    && $json['data'][0]['id'] === $ownScoped->id, 'Real-host HTTP file picker supports validated search and paging');
sourceCheck(str_contains($response->headers->get('Cache-Control'), 'no-store'), 'Staff picker responses cannot be cached');
sourceCheck($http($staff, '/sftp/files?page=0')->getStatusCode() === 422 && $http($staff, '/sftp/files?q[]=invalid')->getStatusCode() === 422, 'File picker rejects invalid query input');
sourceCheck($http($client, '/sftp/files')->getStatusCode() === 403 && $http($client, '/sftp/destinations')->getStatusCode() === 403, 'Clients cannot use either transfer picker');
sourceCheck($http(null, '/sftp/files')->getStatusCode() === 401 && $http(null, '/sftp/destinations')->getStatusCode() === 401, 'Guests cannot use either transfer picker');

foreach ([$client, (clone $staff)->forceFill(['active' => false]), (clone $staff)->forceFill(['expires_at' => now()->subDay()])] as $denied) {
    try {
        $source->withStream($denied, $local->id, fn () => throw new LogicException('Ineligible actor consumed source.'));
        throw new LogicException('Ineligible transfer actor was accepted.');
    } catch (AuthorizationException) {
        // Service access remains protected outside an HTTP request.
    }
}
sourceCheck(true, 'Client, inactive, and expired actors cannot open sources directly');

$perUser = $newFile(['download_limit' => 1, 'download_limit_scope' => 'per_user']);
DB::table('activity_log')->insert([
    'actor_id' => $scoped->id, 'action' => Action::FileDownloaded->value,
    'subject_type' => $perUser->getMorphClass(), 'subject_id' => $perUser->id, 'created_at' => now(),
]);
sourceCheck($source->withStream($staff, $perUser->id, fn ($stream) => stream_get_contents($stream)) === $payload, 'Another user spending a per-user allowance does not block this staff member');
DB::table('activity_log')->insert([
    'actor_id' => $staff->id, 'action' => Action::FileDownloaded->value,
    'subject_type' => $perUser->getMorphClass(), 'subject_id' => $perUser->id, 'created_at' => now(),
]);
try {
    $source->withStream($staff, $perUser->id, fn () => throw new LogicException('Spent per-user source was consumed.'));
    throw new LogicException('Per-user cap was bypassed.');
} catch (HttpException $exception) {
    sourceCheck($exception->getStatusCode() === 403, 'Source reads recheck per-user allowance after it is spent');
}
for ($index = 0; $index < 26; $index++) {
    $newFile(['name' => 'Pagination fixture '.$index]);
}
$firstPage = $source->files($staff, 'Pagination fixture', 1);
$secondPage = $source->files($staff, 'Pagination fixture', 2);
sourceCheck($firstPage->total() === 26 && count($firstPage->items()) === 25 && count($secondPage->items()) === 1
    && array_intersect(array_column($firstPage->items(), 'id'), array_column($secondPage->items(), 'id')) === [], 'Picker pagination is bounded, deterministic, and non-overlapping');
