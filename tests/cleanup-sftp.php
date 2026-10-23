<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use ProjectSendSftp\SftpTransferException;

(function () use ($transport, $destination, $fixturePath, $payload): void {
    $id = (string) Str::uuid();
    $filename = 'cleanup-protected.dump';
    $hash = hash('sha256', $payload);
    $root = $fixturePath.'/incoming/.projectsend-'.$id.'.upload';
    $manifest = json_encode(['attempt' => $id, 'filename' => $filename, 'bytes' => strlen($payload), 'sha256' => $hash], JSON_THROW_ON_ERROR);
    $clean = fn () => $transport->cleanupCompleted($destination, $id, $filename, strlen($payload), $hash);
    $refuse = function (string $label) use ($clean): void {
        try { $clean(); throw new RuntimeException('Unsafe cleanup accepted.'); }
        catch (SftpTransferException) { sftpCheck(true, $label); }
    };
    mkdir($fixturePath.'/protected', 0700);
    file_put_contents($fixturePath.'/protected/keep', $payload);
    symlink($fixturePath.'/protected', $root);
    $refuse('Completed cleanup refuses a symlink in place of the attempt directory');
    sftpCheck(is_link($root) && file_get_contents($fixturePath.'/protected/keep') === $payload,
        'Cleanup neither follows nor deletes an unexpected attempt symlink');
    unlink($root);
    mkdir($root, 0700);
    mkdir($root.'/writer', 0700);
    file_put_contents($root.'/manifest', $manifest);
    file_put_contents($root.'/writer/payload.part', $payload);
    $refuse('Completed cleanup refuses any remaining staged payload before deleting metadata');
    sftpCheck(file_get_contents($root.'/writer/payload.part') === $payload && file_get_contents($root.'/manifest') === $manifest,
        'A retained payload and its identity survive a refused cleanup');
    unlink($root.'/writer/payload.part');
    file_put_contents($root.'/writer/ready', $manifest);
    file_put_contents($root.'/manifest', 'another attempt');
    $refuse('Completed cleanup refuses a mismatched manifest without deleting its receipt');
    sftpCheck(file_get_contents($root.'/writer/ready') === $manifest, 'Receipt survives a mismatched cleanup identity');
    file_put_contents($root.'/manifest', $manifest);
    symlink($fixturePath.'/protected', $root.'/sealed');
    $refuse('Completed cleanup refuses symlinked metadata directories before deleting any receipts');
    sftpCheck(is_link($root.'/sealed') && file_get_contents($root.'/writer/ready') === $manifest,
        'Unexpected child symlinks and known receipts remain untouched');
    unlink($root.'/sealed');
    $clean();
    clearstatcache();
    sftpCheck(! file_exists($root) && file_get_contents($fixturePath.'/protected/keep') === $payload,
        'Cleanup removes only verified attempt metadata after an obstruction is resolved');
    // Simulate a process dying after deleting the manifest but before rmdir.
    mkdir($root, 0700);
    $clean();
    $clean();
    clearstatcache();
    sftpCheck(! file_exists($root), 'An empty final-cleanup remnant converges to absence and repeated cleanup does not recreate it');
})();
