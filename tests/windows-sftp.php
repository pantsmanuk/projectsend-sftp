<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use ProjectSendSftp\Destination;
use ProjectSendSftp\SftpTransferException;

// Real SSH/SFTP with a protocol relay that accepts chmod without changing modes.
// NTFS ACL enforcement and Windows handle semantics still need a Windows trial.
(function () use ($transport, $stream, $payload): void {
    $path = sys_get_temp_dir().'/projectsend-windows-sftp-'.bin2hex(random_bytes(8));
    $server = null;
    try {
        [$server, $username, $port] = startSftpFixture($path, true);
        $destination = new Destination([
            'name' => 'Windows permission fixture', 'host' => '127.0.0.1', 'port' => $port, 'username' => $username,
            'directory' => $path.'/incoming', 'enabled' => true,
            'host_fingerprint' => fixtureFingerprint($path.'/host-ed25519.pub'),
            'private_key' => file_get_contents($path.'/login'), 'private_key_passphrase' => ' fixture passphrase ',
        ]);
        $hash = hash('sha256', $payload);
        $guard = fn () => null;
        $send = fn (string $name, string $attempt, ?callable $progress = null) => $transport->upload(
            $destination, $stream($payload), $name, strlen($payload), $hash, $progress, $attempt, $guard,
        );
        $failure = function (callable $operation, string $message): void {
            try { $operation(); throw new RuntimeException('Expected permission fixture failure.'); }
            catch (SftpTransferException $exception) {
                sftpCheck($exception->getMessage() === $message, $message);
            }
        };

        $old = (string) Str::uuid();
        $root = $path.'/incoming/.projectsend-'.$old.'.upload';
        $failure(fn () => $send('retry.dump', $old), 'Could not set permissions on the uploaded backup.');
        sftpCheck(is_file($root.'/writer/payload.part') && ! file_exists($root.'/writer/ready')
            && ! file_exists($path.'/incoming/retry.dump'), 'Successful no-op chmod reproduces the Windows failure before publication intent or rename');

        $destination->windows_acl_managed = true;
        foreach (['backup:stream', 'NUL.bak', 'COM1.bak', 'lpt².bak', 'backup.bak.', 'backup.bak ', 'bad?.bak', "bad\xff.bak"] as $invalidName) {
            try {
                $send($invalidName, (string) Str::uuid());
                throw new RuntimeException('Unsafe Windows filename accepted.');
            } catch (InvalidArgumentException) {
                sftpCheck(true, 'Windows ACL destinations reject alternate streams, reserved names, and ambiguous or invalid filenames');
            }
        }
        file_put_contents($path.'/chmod-requests.log', '');
        sftpCheck($transport->reconcile($destination, $old, 'retry.dump', strlen($payload), $hash, $guard) === null
            && is_file($root.'/writer') && ! file_exists($root.'/sealed/payload.part'),
            'Windows ACL policy fences and cleans an old strict-permission failure before retry');
        $attempt = (string) Str::uuid();
        $result = $send('retry.dump', $attempt);
        clearstatcache();
        sftpCheck(file_get_contents($result['path']) === $payload && (fileperms($result['path']) & 0777) === 0600
            && $transport->reconcile($destination, $attempt, 'retry.dump', strlen($payload), $hash, $guard) === $result['path'],
            'Explicit Windows ACL policy publishes exact bytes despite 0600 mode and reconciles publication');
        $neverStarted = (string) Str::uuid();
        sftpCheck($transport->reconcile($destination, $neverStarted, 'never-started.dump', strlen($payload), $hash, $guard) === null,
            'Windows ACL policy reserves a recovery barrier for an attempt that never started');
        sftpCheck(file_get_contents($path.'/chmod-requests.log') === '',
            'Windows uploads and recovery send no explicit or implicit chmod requests');

        file_put_contents($path.'/permission-policy', 'deny');
        $destination->windows_acl_managed = false;
        $denied = (string) Str::uuid();
        $failure(fn () => $send('denied.dump', $denied), 'Could not restrict permissions on the staging directory.');
        sftpCheck(! file_exists($path.'/incoming/.projectsend-'.$denied.'.upload/manifest')
            && ! file_exists($path.'/incoming/denied.dump'), 'Strict Unix policy rejects denied staging chmod before sending backup bytes');
        $failure(fn () => $transport->reconcile($destination, (string) Str::uuid(), 'barrier.dump', strlen($payload), $hash, $guard),
            'Could not reserve the recovery barrier.');
        file_put_contents($path.'/permission-policy', 'deny-writer');
        $writer = (string) Str::uuid();
        $failure(fn () => $send('writer.dump', $writer), 'Could not reserve this upload attempt.');
        sftpCheck(! file_exists($path.'/incoming/.projectsend-'.$writer.'.upload/writer/payload.part'),
            'Strict Unix policy rejects denied writer chmod before opening the payload');

        $destination->windows_acl_managed = true;
        file_put_contents($path.'/permission-policy', 'deny');
        file_put_contents($path.'/chmod-requests.log', '');
        $blocked = (string) Str::uuid();
        $failure(fn () => $send('blocked.dump', $blocked, function () use ($path): void { touch($path.'/deny-rename'); }),
            'Could not publish the backup without overwriting an existing file.');
        $failure(fn () => $transport->reconcile($destination, $blocked, 'blocked.dump', strlen($payload), $hash, $guard),
            'Could not isolate the previous writer.');
        sftpCheck(! file_exists($path.'/incoming/blocked.dump')
            && file_exists($path.'/incoming/.projectsend-'.$blocked.'.upload/writer/payload.part'),
            'A refused Windows directory rename leaves the attempt unresolved and its evidence intact');
        unlink($path.'/deny-rename');
        sftpCheck($transport->reconcile($destination, $blocked, 'blocked.dump', strlen($payload), $hash, $guard) === null,
            'Recovery proceeds once the server permits fencing the old writer');
        $send('blocked.dump', (string) Str::uuid());
        sftpCheck(file_get_contents($path.'/incoming/blocked.dump') === $payload
            && file_get_contents($path.'/chmod-requests.log') === '', 'Windows ACL policy also works when all chmod requests would be denied');

        $collision = $path.'/incoming/collision.dump';
        try {
            $send('collision.dump', (string) Str::uuid(), function () use ($collision): void { file_put_contents($collision, 'existing winner'); });
            throw new RuntimeException('Windows collision was overwritten.');
        } catch (SftpTransferException) {
            sftpCheck(file_get_contents($collision) === 'existing winner', 'Windows ACL policy retains no-overwrite protection during publication');
        }
        $destination->host_fingerprint = fixtureFingerprint($path.'/unauthorised.pub');
        $failure(fn () => $send('untrusted.dump', (string) Str::uuid()), 'The server SSH host key does not match the trusted fingerprint.');
    } finally {
        if (is_resource($server)) { proc_terminate($server); proc_close($server); }
        (new Filesystem)->deleteDirectory($path);
    }
})();
