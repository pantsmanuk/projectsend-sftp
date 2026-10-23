<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Files\Models\File;
use App\Modules\Identity\Models\Role;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ProjectSendSftp\Destination;
use ProjectSendSftp\Transfer;
use ProjectSendSftp\TransferStatus;

// Generated data only. Independent, memory-limited worker; no host .env/services.
require __DIR__.'/host-bootstrap.php';

function trialCheck(bool $condition, string $message): void
{
    if (! $condition) { throw new RuntimeException($message); }
}

function trialQueue($app): void
{
    $app->make('config')->set([
        'queue.default' => 'database', 'queue.connections.database.retry_after' => 3660,
    ]);
}

if (($argv[1] ?? '') === '--worker') {
    try {
        [$script, $mode, $hostPath, $fixturePath, $source] = $argv;
        $app = bootSftpHost($hostPath, dirname(__DIR__), $fixturePath, $fixturePath.'/database.sqlite');
        trialQueue($app);
        if ($source === 's3-mock') {
            // Real Flysystem/AWS adapter, file-backed response body. No AWS network.
            $mock = new Aws\MockHandler;
            $mock->append(new Aws\Result(['Body' => GuzzleHttp\Psr7\Utils::streamFor(fopen($fixturePath.'/source-files/large.dump', 'rb'))]));
            $s3 = new Aws\S3\S3Client(['version' => 'latest', 'region' => 'us-east-1',
                'credentials' => ['key' => 'fixture', 'secret' => 'fixture'], 'handler' => $mock]);
            $adapter = new League\Flysystem\AwsS3V3\AwsS3V3Adapter($s3, 'fixture-bucket');
            Storage::set('sftp_trial_s3', new Illuminate\Filesystem\FilesystemAdapter(
                new League\Flysystem\Filesystem($adapter), $adapter, ['throw' => true],
            ));
        }
        $started = microtime(true);
        $exit = Illuminate\Support\Facades\Artisan::call('queue:work', [
            'connection' => 'database', '--queue' => 'sftp', '--once' => true,
            '--tries' => 1, '--timeout' => 3600, '--memory' => 128,
        ]);
        trialCheck($exit === 0, 'Worker command failed.');
        if ($source === 's3-mock') {
            trialCheck($mock->getLastCommand()->getName() === 'GetObject'
                && $mock->getLastCommand()['Key'] === 'large.dump'
                && $mock->getLastCommand()['@http']['stream'] === true,
                'S3 adapter did not request the expected streaming object.');
        }
        file_put_contents($fixturePath.'/metrics.json', json_encode([
            'worker_seconds' => round(microtime(true) - $started, 3),
            'php_peak_bytes' => memory_get_peak_usage(true),
            // Linux reports ru_maxrss in KiB. Other platforms are not claimed.
            'peak_rss_kib' => getrusage()['ru_maxrss'],
            'memory_limit' => ini_get('memory_limit'),
        ], JSON_THROW_ON_ERROR));
        exit(0);
    } catch (Throwable $error) {
        fwrite(STDERR, $error."\n");
        exit(1);
    }
}

$hostPath = realpath($argv[1] ?? dirname(__DIR__, 3));
$sizeMiB = $argv[2] ?? '512';
$source = $argv[3] ?? 'local';
if ($hostPath === false || ! is_file($hostPath.'/vendor/autoload.php')
    || ! ctype_digit($sizeMiB) || (int) $sizeMiB < 256 || (int) $sizeMiB > 65536
    || ! in_array($source, ['local', 's3-mock'], true)) {
    fwrite(STDERR, "Usage: php tests/large-backup.php HOST_PATH [256..65536 MiB, default 512] [local|s3-mock]\n");
    exit(1);
}
$fixturePath = sys_get_temp_dir().'/projectsend-sftp-large-'.bin2hex(random_bytes(8));
$server = $worker = null;
$files = null;
try {
    $app = bootSftpHost($hostPath, dirname(__DIR__), $fixturePath, $fixturePath.'/database.sqlite');
    $files = new Illuminate\Filesystem\Filesystem;
    $migrator = $app->make('migrator');
    $migrator->getRepository()->createRepository();
    $migrator->run([$hostPath.'/database/migrations', dirname(__DIR__).'/database/migrations']);
    trialQueue($app);
    trialCheck(extension_loaded('pcntl'), 'The trial worker requires pcntl for its timeout.');
    $bytes = (int) $sizeMiB * 1024 * 1024;
    trialCheck(disk_free_space($fixturePath) > $bytes * 2 + 256 * 1024 * 1024, 'Insufficient temporary disk space.');
    mkdir($fixturePath.'/source-files', 0700, true);
    $stream = fopen($fixturePath.'/source-files/large.dump', 'xb');
    $hash = hash_init('sha256');
    // Fresh random chunks avoid sparse-file and highly compressible trial data.
    try {
        for ($written = 0; $written < $bytes; $written += strlen($chunk)) {
            $chunk = random_bytes(min(1024 * 1024, $bytes - $written));
            trialCheck(fwrite($stream, $chunk) === strlen($chunk), 'Fixture source write failed.');
            hash_update($hash, $chunk);
        }
    } finally { fclose($stream); }
    unset($chunk);
    $sha256 = hash_final($hash);
    $role = Role::create(['name' => 'Large trial staff', 'client_scoped' => false, 'is_administrator' => false]);
    DB::table('role_permission')->insert(['role_id' => $role->id, 'permission' => 'upload']);
    $userId = DB::table('users')->insertGetId([
        'name' => 'Generated trial staff', 'email' => 'large-trial@example.invalid',
        'password' => 'unused-fixture-password', 'type' => 'staff', 'active' => true, 'role_id' => $role->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $staff = User::findOrFail($userId);
    $fileId = DB::table('files')->insertGetId([
        'uploaded_by' => $userId, 'name' => 'Generated large backup', 'original_name' => 'large.dump',
        'path' => 'large.dump', 'slug' => 'large-trial', 'disk' => $source === 'local' ? 'sftp_test_local' : 'sftp_trial_s3',
        'mime_type' => 'application/octet-stream', 'size' => $bytes, 'checksum' => $sha256, 'scan_status' => 'clean',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    require __DIR__.'/sftp-fixture.php';
    [$server, $username, $port] = startSftpFixture($fixturePath.'/ssh');
    $destination = Destination::create([
        'name' => 'Generated loopback trial', 'enabled' => true, 'host' => '127.0.0.1', 'port' => $port,
        'username' => $username, 'directory' => $fixturePath.'/ssh/incoming',
        'host_fingerprint' => fixtureFingerprint($fixturePath.'/ssh/host-ed25519.pub'),
        'private_key' => file_get_contents($fixturePath.'/ssh/login'), 'private_key_passphrase' => ' fixture passphrase ',
    ]);
    // Exercise staff HTTP review/send/status, with real middleware and CSRF.
    // Synthetic authentication is deliberate; actual login remains a rollout gate.
    $app->instance('env', 'local');
    $cookies = [];
    $httpBytes = 0;
    $http = function (string $method, string $path, array $input = [], bool $json = false) use ($app, $staff, &$cookies, &$httpBytes) {
        Auth::forgetGuards();
        Auth::guard('web')->setUser($staff);
        $request = Request::create('http://localhost'.$path, $method, $input, $cookies, [], ['HTTP_ACCEPT' => $json ? 'application/json' : 'text/html']);
        $kernel = $app->make(Kernel::class);
        $response = $kernel->handle($request);
        foreach ($response->headers->getCookies() as $cookie) { $cookies[$cookie->getName()] = $cookie->getValue(); }
        $kernel->terminate($request, $response);
        $httpBytes += strlen($response->getContent());
        return $response;
    };
    $review = $http('GET', '/sftp/transfers/review/'.$fileId);
    trialCheck($review->getStatusCode() === 200, 'Trial review failed.');
    $field = function (string $name) use ($review): string {
        preg_match('/name="'.preg_quote($name, '/').'" value="([^"]+)"/', $review->getContent(), $match);
        return $match[1] ?? throw new RuntimeException('Missing trial form field.');
    };
    $id = $field('_request_id');
    $sent = $http('POST', '/sftp/transfers', ['_token' => $field('_token'), '_request_id' => $id,
        'file_id' => $fileId, 'destination_id' => $destination->id]);
    trialCheck($sent->getStatusCode() === 303 && DB::table('jobs')->where('queue', 'sftp')->count() === 1, 'HTTP send did not queue exactly one job.');
    trialCheck(! file_exists($fixturePath.'/ssh/incoming/large.dump'), 'HTTP request performed the upload.');
    $worker = proc_open([PHP_BINARY, '-d', 'memory_limit=128M', __FILE__, '--worker', $hostPath, $fixturePath, $source], [
        0 => ['pipe', 'r'], 1 => ['file', $fixturePath.'/worker.log', 'a'], 2 => ['file', $fixturePath.'/worker.log', 'a'],
    ], $pipes);
    trialCheck(is_resource($worker), 'Could not launch trial worker.');
    fclose($pipes[0]);
    $deadline = microtime(true) + 3650;
    do {
        $status = proc_get_status($worker);
        if (! $status['running']) { break; }
        trialCheck(microtime(true) < $deadline, 'Trial worker exceeded its deadline.');
        usleep(100000);
    } while (true);
    trialCheck($status['exitcode'] === 0, 'Trial worker failed: '.file_get_contents($fixturePath.'/worker.log'));
    $row = Transfer::findOrFail($id);
    $remote = $fixturePath.'/ssh/incoming/large.dump';
    trialCheck($row->status === TransferStatus::Completed && $row->bytes_sent === $bytes, 'Trial did not confirm all bytes: '.$row->status->value);
    trialCheck(filesize($remote) === $bytes && hash_file('sha256', $remote) === $sha256, 'Delivered bytes differ.');
    $log = File::findOrFail($fileId)->downloads()->sole();
    trialCheck($log->actor_id === $userId && $log->context['sftp_transfer_id'] === $id, 'Delivery accounting differs.');
    trialCheck(DB::table('jobs')->where('queue', 'sftp')->count() === 0 && DB::table('failed_jobs')->count() === 0, 'Trial left queued/failed jobs.');
    $response = $http('GET', '/sftp/transfers/'.$id.'/status', [], true);
    trialCheck($response->getStatusCode() === 200 && json_decode($response->getContent(), true)['data']['status'] === 'completed', 'Staff status did not confirm delivery.');
    trialCheck($httpBytes < 256 * 1024, 'Staff HTTP responses exceeded the metadata budget.');
    $metrics = json_decode(file_get_contents($fixturePath.'/metrics.json'), true, flags: JSON_THROW_ON_ERROR);
    trialCheck($metrics['php_peak_bytes'] < 128 * 1024 * 1024, 'Worker exceeded the PHP memory budget.');
    trialCheck($metrics['peak_rss_kib'] < 256 * 1024, 'Worker exceeded the 256 MiB process RAM budget.');
    echo json_encode([
        'result' => 'passed', 'source' => $source, 'bytes' => $bytes, 'sha256_match' => true,
        'accounting_entries' => 1, 'staff_http_body_bytes' => $httpBytes,
        'mib_per_second' => round((int) $sizeMiB / $metrics['worker_seconds'], 2),
        'php' => PHP_VERSION, 'laravel' => $app->version(),
    ] + $metrics, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error."\n");
    $testExitCode = 1;
} finally {
    foreach ([$worker, $server] as $process) {
        if (is_resource($process)) {
            if (proc_get_status($process)['running']) { proc_terminate($process); }
            proc_close($process);
        }
    }
    if ($files !== null) { $files->deleteDirectory($fixturePath); }
}
exit($testExitCode ?? 0);
