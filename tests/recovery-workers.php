<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use ProjectSendSftp\TransferStatus;

// Two actual PHP processes share the fixture SQLite DB and SSH server.
// Pause the first after sending bytes; expire its lease, fence it from another
// process, then let it resume. Its open SFTP handle must not enable publication.
$processes = [];
$launch = function (string $id, string $label, array $flags = []) use ($hostPath, $fixturePath, &$processes) {
    $process = proc_open([PHP_BINARY, __DIR__.'/recovery-worker.php', $hostPath, $fixturePath, $id, $label, ...$flags], [
        0 => ['pipe', 'r'], 1 => ['file', $fixturePath.'/worker-'.$label.'.log', 'a'],
        2 => ['file', $fixturePath.'/worker-'.$label.'.log', 'a'],
    ], $pipes);
    if (! is_resource($process)) { throw new RuntimeException('Could not launch worker fixture.'); }
    fclose($pipes[0]);
    $processes[] = $process;
    return $process;
};
$join = function ($process): void {
    $deadline = microtime(true) + 30;
    do {
        $status = proc_get_status($process);
        if (! $status['running']) {
            sourceCheck($status['exitcode'] === 0, 'Independent fixture worker exits successfully');
            return;
        }
        usleep(20000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Fixture worker did not finish in time.');
};
try {
    $concurrentFile = $newFile(['original_name' => 'concurrent-workers.dump']);
    $concurrent = $submit->submit($staff, $concurrentFile->id, $sftpTarget->id);
    DB::table('jobs')->where('queue', 'sftp')->delete();
    $first = $launch($concurrent->id, 'original', ['--pause']);
    $deadline = microtime(true) + 20;
    while (! file_exists($fixturePath.'/paused-original')) {
        if (microtime(true) > $deadline || ! proc_get_status($first)['running']) { throw new RuntimeException('Original fixture worker did not pause: '.$concurrent->fresh()->error.' '.file_get_contents($fixturePath.'/worker-original.log'));  }
        usleep(20000);
    }
    $token = $concurrent->fresh()->execution_token;
    $second = $launch($concurrent->id, 'duplicate');
    $join($second);
    sourceCheck($concurrent->fresh()->status === TransferStatus::Running && $concurrent->fresh()->execution_token === $token
        && ! file_exists($sshPath.'/incoming/concurrent-workers.dump'), 'Concurrent duplicate worker cannot steal an active execution lease');
    DB::table('sftp_transfers')->where('id', $concurrent->id)->update(['lease_expires_at' => now()->subSecond()]);
    $recovery = $launch($concurrent->id, 'recovery');
    $join($recovery);
    sourceCheck($concurrent->fresh()->status === TransferStatus::Failed && $concurrentFile->downloads()->count() === 0,
        'A second process fences the stalled writer and reconciles its uncommitted partial');
    touch($fixturePath.'/resume-original');
    $join($first);
    sourceCheck($concurrent->fresh()->status === TransferStatus::Failed && ! file_exists($sshPath.'/incoming/concurrent-workers.dump')
        && $concurrentFile->downloads()->count() === 0, 'A stale worker resuming with an open remote handle cannot publish or overwrite recovered state');
    $retried = $launch($concurrent->id, 'retried', ['--retry']);
    $join($retried);
    sourceCheck($concurrent->fresh()->status === TransferStatus::Completed && $concurrent->fresh()->attempts === 2
        && $concurrentFile->downloads()->count() === 1 && file_get_contents($sshPath.'/incoming/concurrent-workers.dump') === $payload,
        'A new worker safely retries after fencing and records one delivered copy');

    $killedFile = $newFile(['original_name' => 'killed-worker.dump']);
    $killed = $submit->submit($staff, $killedFile->id, $sftpTarget->id);
    DB::table('jobs')->where('queue', 'sftp')->delete();
    $victim = $launch($killed->id, 'killed', ['--pause']);
    $deadline = microtime(true) + 20;
    while (! file_exists($fixturePath.'/paused-killed')) {
        if (microtime(true) > $deadline || ! proc_get_status($victim)['running']) { throw new RuntimeException('Kill fixture did not pause.'); }
        usleep(20000);
    }
    proc_terminate($victim, 9);
    $deadline = microtime(true) + 5;
    while (proc_get_status($victim)['running'] && microtime(true) < $deadline) { usleep(20000); }
    sourceCheck($killed->fresh()->status === TransferStatus::Running, 'A real hard-killed worker leaves running state without a failure callback');
    DB::table('sftp_transfers')->where('id', $killed->id)->update(['lease_expires_at' => now()->subSecond()]);
    $recover($killed, true);
    sourceCheck($killed->fresh()->status === TransferStatus::Completed && $killed->fresh()->attempts === 2
        && $killedFile->downloads()->count() === 1, 'Explicit recovery safely retries a hard-killed worker after lease expiry');
} finally {
    touch($fixturePath.'/resume-original');
    foreach ($processes as $process) {
        if (is_resource($process)) {
            if (proc_get_status($process)['running']) { proc_terminate($process); }
            proc_close($process);
        }
    }
}
