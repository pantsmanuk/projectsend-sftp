<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Filesystem\Filesystem;
use ProjectSendSftp\Destination;
use ProjectSendSftp\SftpTransport;
use ProjectSendSftp\SftpTransferException;

$packagePath = dirname(__DIR__);
$autoload = $argv[1] ?? $packagePath.'/vendor/autoload.php';
if (! is_file($autoload)) {
    fwrite(STDERR, "Supply a Laravel application's vendor/autoload.php.\n");
    exit(1);
}
$loader = require $autoload;
$loader->addPsr4('ProjectSendSftp\\', $packagePath.'/src');
Model::encryptUsing(new Encrypter(random_bytes(32), 'AES-256-CBC'));

function sftpCheck(bool $condition, string $message): void
{
    if (! $condition) { throw new RuntimeException($message); }
    echo "PASS: {$message}\n";
}

require __DIR__.'/sftp-fixture.php';

$fixturePath = sys_get_temp_dir().'/projectsend-sftp-transport-'.bin2hex(random_bytes(8));
$server = null;
$streams = [];
try {
    [$server, $username, $port] = startSftpFixture($fixturePath);
    $destination = new Destination([
        'name' => 'Local fixture', 'host' => '127.0.0.1', 'port' => $port, 'username' => $username,
        'directory' => $fixturePath.'/incoming', 'enabled' => true,
        'host_fingerprint' => fixtureFingerprint($fixturePath.'/host-ed25519.pub'),
        'private_key' => file_get_contents($fixturePath.'/login'), 'private_key_passphrase' => ' fixture passphrase ',
    ]);
    $transport = new SftpTransport;
    $payload = str_repeat("fixture backup\0\n", 8192);
    $stream = function (string $data) use (&$streams) {
        $handle = fopen('php://temp', 'w+b');
        fwrite($handle, $data);
        rewind($handle);
        $streams[] = $handle;
        return $handle;
    };
    $send = fn (string $name, ?Destination $target = null, ?callable $progress = null) => $transport->upload(
        $target ?? $destination, $stream($payload), $name, strlen($payload), hash('sha256', $payload), $progress,
    );
    $expectFailure = function (callable $operation, string $name): void {
        try { $operation(); throw new LogicException('Expected transfer failure.'); }
        catch (SftpTransferException $exception) {
            sftpCheck($exception->getPrevious() === null && ! str_contains($exception->getMessage(), 'fixture passphrase'), $name);
        }
    };

    $source = $stream($payload);
    $progress = [];
    $result = $transport->upload($destination, $source, 'backup.dump', strlen($payload), hash('sha256', $payload), function (int $bytes) use (&$progress): void { $progress[] = $bytes; });
    sftpCheck(file_get_contents($result['path']) === $payload && hash_file('sha256', $result['path']) === $result['sha256']
        && $result['bytes'] === strlen($payload) && is_resource($source), 'Real SFTP transfers exact bytes using an encrypted SSH key and leaves the source open for its owner');
    sftpCheck(end($progress) === strlen($payload) && (fileperms($result['path']) & 0777) === 0644
        && glob($fixturePath.'/incoming/.projectsend-*.upload') === [], 'Successful uploads report progress, publish with 0644 permissions for database-service reads, and remove staging directories');
    $rsa = clone $destination;
    $rsa->host_fingerprint = fixtureFingerprint($fixturePath.'/host-rsa.pub');
    sftpCheck(file_get_contents($send('rsa-pin.dump', $rsa)['path']) === $payload, 'RSA host fingerprints work when the server also offers preferred Ed25519 keys');

    $beforeLogins = substr_count(file_get_contents($fixturePath.'/sshd.log'), 'Accepted publickey');
    $wrongPin = clone $destination;
    $wrongPin->host_fingerprint = 'SHA256:'.rtrim(base64_encode(hash('sha256', 'unknown fixture host', true)), '=');
    $expectFailure(fn () => $send('wrong-pin.dump', $wrongPin), 'Wrong host fingerprints are refused');
    sftpCheck(substr_count(file_get_contents($fixturePath.'/sshd.log'), 'Accepted publickey') === $beforeLogins
        && ! file_exists($fixturePath.'/incoming/wrong-pin.dump'), 'Host verification fails before authentication or remote file creation');
    $wrongLogin = clone $destination;
    $wrongLogin->private_key = file_get_contents($fixturePath.'/unauthorised');
    $wrongLogin->private_key_passphrase = null;
    $expectFailure(fn () => $send('wrong-login.dump', $wrongLogin), 'Unauthorised login keys are refused without exposing credentials');

    $disabled = clone $destination;
    $disabled->enabled = false;
    $expectFailure(fn () => $send('disabled.dump', $disabled), 'Disabled destinations are refused');
    $expectFailure(fn () => $send('backup.dump'), 'Existing destination files are never overwritten');
    sftpCheck(file_get_contents($fixturePath.'/incoming/backup.dump') === $payload, 'Existing backup bytes survive a filename collision');
    $racePath = $fixturePath.'/incoming/race.dump';
    $expectFailure(fn () => $send('race.dump', progress: function () use ($racePath): void {
        if (! file_exists($racePath)) { file_put_contents($racePath, 'concurrent winner'); }
    }), 'Publication refuses a destination filename created during upload');
    sftpCheck(file_get_contents($racePath) === 'concurrent winner', 'Concurrent destination content remains unchanged');

    $expectFailure(fn () => $transport->upload($destination, $stream($payload), 'bad-checksum.dump', strlen($payload), str_repeat('0', 64)), 'Checksum mismatches never publish a backup');
    $expectFailure(fn () => $transport->upload($destination, $stream($payload), 'truncated.dump', strlen($payload) + 1, hash('sha256', $payload)), 'Truncated source streams never publish a backup');
    $expectFailure(fn () => $transport->upload($destination, $stream($payload), 'oversized.dump', strlen($payload) - 1, hash('sha256', $payload)), 'Oversized source streams never publish a backup');
    foreach (['bad-checksum.dump', 'truncated.dump', 'oversized.dump'] as $name) {
        sftpCheck(! file_exists($fixturePath.'/incoming/'.$name), 'Failed '.$name.' has no published file');
    }
    try { $send('../escape.dump'); throw new LogicException('Path traversal accepted.'); }
    catch (InvalidArgumentException) { sftpCheck(! file_exists($fixturePath.'/escape.dump'), 'Destination filenames cannot traverse directories'); }
    $empty = $transport->upload($destination, $stream(''), 'empty.dump', 0, hash('sha256', ''));
    sftpCheck(filesize($empty['path']) === 0, 'Empty readable streams are handled correctly');

    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($pair === false) { throw new RuntimeException('Could not create the non-seekable source fixture.'); }
    $streams[] = $pair[0];
    $small = "non-seekable backup\0\n";
    fwrite($pair[1], $small);
    fclose($pair[1]);
    $nonSeekable = $transport->upload($destination, $pair[0], 'non-seekable.dump', strlen($small), hash('sha256', $small));
    sftpCheck(! stream_get_meta_data($pair[0])['seekable'] && file_get_contents($nonSeekable['path']) === $small,
        'Non-seekable source streams transfer without buffering or rewinding');
    $missingDirectory = clone $destination;
    $missingDirectory->directory = $fixturePath.'/missing';
    $expectFailure(fn () => $send('missing-directory.dump', $missingDirectory), 'Missing incoming directories are refused');
    mkdir($fixturePath.'/read-only', 0500);
    $readOnly = clone $destination;
    $readOnly->directory = $fixturePath.'/read-only';
    $expectFailure(fn () => $send('read-only.dump', $readOnly), 'Unwritable incoming directories fail without publishing');
    chmod($fixturePath.'/read-only', 0700);
    $expectFailure(fn () => $send('progress-error.dump', progress: fn () => throw new RuntimeException('fixture secret progress detail')),
        'Progress callback failures abort before publication');
    $unreadable = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($unreadable === false) { throw new RuntimeException('Could not create unreadable source fixture.'); }
    $streams[] = $unreadable[0];
    $streams[] = $unreadable[1];
    stream_set_blocking($unreadable[0], false);
    $expectFailure(fn () => $transport->upload($destination, $unreadable[0], 'read-error.dump', 0, hash('sha256', '')),
        'An incomplete source read fails before publication');
    sftpCheck(! file_exists($fixturePath.'/incoming/progress-error.dump') && ! file_exists($fixturePath.'/incoming/read-error.dump'),
        'Callback failures never publish temporary backup content');
    sftpCheck(glob($fixturePath.'/incoming/.projectsend-*.upload') === [], 'Connected failure paths remove their staging directories');

    $reserved = (string) Illuminate\Support\Str::uuid();
    $guard = fn () => null;
    sftpCheck($transport->reconcile($destination, $reserved, 'never-started.dump', strlen($payload), hash('sha256', $payload), $guard) === null,
        'Recovery fences an attempt that never created its staging directory');
    $expectFailure(fn () => $transport->upload($destination, $stream($payload), 'never-started.dump', strlen($payload), hash('sha256', $payload), null, $reserved, $guard),
        'A stale attempt cannot reserve its original remote path after recovery');
    $zeroAttempt = (string) Illuminate\Support\Str::uuid();
    $privateStaging = false;
    $managed = $transport->upload($destination, $stream(''), 'managed-empty.dump', 0, hash('sha256', ''), null, $zeroAttempt,
        function () use ($fixturePath, $zeroAttempt, &$privateStaging): void {
            $root = $fixturePath.'/incoming/.projectsend-'.$zeroAttempt.'.upload';
            if (file_exists($root.'/writer/ready')) {
                clearstatcache();
                $privateStaging = (fileperms($root) & 0777) === 0700
                    && (fileperms($root.'/writer') & 0777) === 0700
                    && (fileperms($root.'/writer/payload.part') & 0777) === 0644;
            }
        });
    sftpCheck($privateStaging && (fileperms($managed['path']) & 0777) === 0644,
        'Managed uploads set 0644 before publication while staging directories remain 0700');
    sftpCheck($transport->reconcile($destination, $zeroAttempt, 'managed-empty.dump', 0, hash('sha256', ''), $guard)
        === $fixturePath.'/incoming/managed-empty.dump', 'Publication receipts and remote checksums also reconcile an empty backup');
    file_put_contents($fixturePath.'/incoming/.projectsend-'.$zeroAttempt.'.upload/sealed/ready', 'corrupted receipt');
    $expectFailure(fn () => $transport->reconcile($destination, $zeroAttempt, 'managed-empty.dump', 0, hash('sha256', ''), $guard),
        'Corrupt publication receipts remain unresolved rather than permitting a retry');
    $completedRoot = $fixturePath.'/incoming/.projectsend-'.$zeroAttempt.'.upload';
    file_put_contents($completedRoot.'/sealed/ready', file_get_contents($completedRoot.'/manifest'));
    $transport->cleanupCompleted($destination, $zeroAttempt, 'managed-empty.dump', 0, hash('sha256', ''));
    clearstatcache();
    sftpCheck(! file_exists($completedRoot) && is_file($managed['path']),
        'Completed cleanup removes a recovered publication receipt and fence without touching the final empty backup');

    $raceAttempt = (string) Illuminate\Support\Str::uuid();
    $managedRacePath = $fixturePath.'/incoming/managed-race.dump';
    $expectFailure(fn () => $transport->upload($destination, $stream($payload), 'managed-race.dump', strlen($payload), hash('sha256', $payload),
        function () use ($managedRacePath, $payload): void { file_put_contents($managedRacePath, $payload); }, $raceAttempt, $guard),
        'Managed publication preserves a concurrent identical destination file');
    for ($recovery = 0; $recovery < 2; $recovery++) {
        $expectFailure(fn () => $transport->reconcile($destination, $raceAttempt, 'managed-race.dump', strlen($payload), hash('sha256', $payload), $guard),
            'Repeated recovery never mistakes partial cleanup for publication');
    }
    sftpCheck(is_file($fixturePath.'/incoming/.projectsend-'.$raceAttempt.'.upload/unpublished')
        && file_get_contents($managedRacePath) === $payload, 'A permanent non-publication receipt preserves the collision outcome');

    require __DIR__.'/cleanup-sftp.php';
    require __DIR__.'/windows-sftp.php';

    $large = str_repeat('interruption fixture ', 200000);
    $expectFailure(fn () => $transport->upload($destination, $stream($large), 'interrupted.dump', strlen($large), hash('sha256', $large), function () use ($fixturePath): void {
        if (! file_exists($fixturePath.'/drop-connection')) {
            touch($fixturePath.'/drop-connection');
            usleep(150000);
        }
    }), 'A real server-side SFTP disconnect fails safely');
    sftpCheck(! file_exists($fixturePath.'/incoming/interrupted.dump'), 'Interrupted uploads remain unpublished');
} catch (Throwable $exception) {
    fwrite(STDERR, $exception."\n");
    $exitCode = 1;
} finally {
    foreach ($streams as $handle) { if (is_resource($handle)) { fclose($handle); } }
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    (new Filesystem)->deleteDirectory($fixturePath);
}
exit($exitCode ?? 0);
