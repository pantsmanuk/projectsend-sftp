<?php

declare(strict_types=1);

// Shared generated-key, loopback-only OpenSSH fixture. No account/system keys change.
function fixtureCommand(array $command): string
{
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($process)) { throw new RuntimeException('Could not start fixture command.'); }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) { throw new RuntimeException('Fixture command failed: '.$error); }
    return $output;
}

/** @return array{resource, string, int} */
function startSftpFixture(string $fixturePath, bool $windowsPermissions = false): array
{
    $server = null;
    try {
        mkdir($fixturePath.'/incoming', 0700, true);
        $username = trim(fixtureCommand(['/usr/bin/id', '-un']));
        if ($username === 'root') { throw new RuntimeException('Run the local SSH fixture as an ordinary user, not root.'); }
        foreach (['host-ed25519', 'host-rsa', 'login', 'unauthorised'] as $name) {
            fixtureCommand(['/usr/bin/ssh-keygen', '-q', '-t', $name === 'host-rsa' ? 'rsa' : 'ed25519', '-b', '2048',
                '-N', $name === 'login' ? ' fixture passphrase ' : '', '-f', $fixturePath.'/'.$name]);
        }
        file_put_contents($fixturePath.'/authorized_keys', file_get_contents($fixturePath.'/login.pub'));
        chmod($fixturePath.'/authorized_keys', 0600);
        $subsystem = is_file('/usr/lib/ssh/sftp-server') ? '/usr/lib/ssh/sftp-server' : '/usr/lib/openssh/sftp-server';
        if (! is_executable($subsystem)) { throw new RuntimeException('OpenSSH sftp-server is required.'); }
        $command = escapeshellarg($subsystem);
        if ($windowsPermissions) {
            file_put_contents($fixturePath.'/permission-policy', 'noop');
            $command = 'python3 '.escapeshellarg(__DIR__.'/sftp-permissions-server.py').' '.$command.' '.escapeshellarg($fixturePath);
        }
        $wrapper = "#!/bin/sh\n".$command." <&0 &\nchild=\$!\n"
            ."trap 'kill \"\$child\" 2>/dev/null' EXIT TERM INT\n"
            ."while kill -0 \"\$child\" 2>/dev/null; do\n"
            .'  if [ -f '.escapeshellarg($fixturePath.'/drop-connection')." ]; then kill \"\$child\"; break; fi\n"
            ."  sleep 0.01\ndone\nwait \"\$child\"\n";
        file_put_contents($fixturePath.'/subsystem', $wrapper);
        chmod($fixturePath.'/subsystem', 0700);
        $listener = @stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorText);
        if ($listener === false) { throw new RuntimeException('Could not reserve a loopback port: '.$errorText); }
        $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
        fclose($listener);
        $config = "ListenAddress 127.0.0.1\nPort {$port}\n"
            ."HostKey {$fixturePath}/host-ed25519\nHostKey {$fixturePath}/host-rsa\n"
            ."PidFile {$fixturePath}/sshd.pid\nAuthorizedKeysFile {$fixturePath}/authorized_keys\n"
            ."AllowUsers {$username}\nPubkeyAuthentication yes\nPasswordAuthentication no\n"
            ."KbdInteractiveAuthentication no\nUsePAM no\nStrictModes no\nPermitRootLogin no\n"
            ."AllowTcpForwarding no\nX11Forwarding no\nPermitTunnel no\nLogLevel VERBOSE\n"
            ."ForceCommand {$fixturePath}/subsystem\nSubsystem sftp {$subsystem}\n";
        file_put_contents($fixturePath.'/sshd_config', $config);
        $server = proc_open(['/usr/bin/sshd', '-D', '-e', '-f', $fixturePath.'/sshd_config'], [
            0 => ['pipe', 'r'], 1 => ['file', $fixturePath.'/sshd.log', 'a'], 2 => ['file', $fixturePath.'/sshd.log', 'a'],
        ], $pipes, $fixturePath);
        if (! is_resource($server)) { throw new RuntimeException('Could not start the local SSH fixture.'); }
        fclose($pipes[0]);
        $ready = false;
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $probe = @stream_socket_client('tcp://127.0.0.1:'.$port, $code, $text, 0.1);
            if (is_resource($probe)) { fclose($probe); $ready = true; break; }
            if (! proc_get_status($server)['running']) { break; }
            usleep(20000);
        }
        if (! $ready) { throw new RuntimeException('Local SSH fixture failed: '.file_get_contents($fixturePath.'/sshd.log')); }


        return [$server, $username, $port];
    } catch (Throwable $exception) {
        if (is_resource($server)) { proc_terminate($server); proc_close($server); }
        throw $exception;
    }
}

function fixtureFingerprint(string $keyFile): string
{
    $parts = preg_split('/\s+/', trim(fixtureCommand(['/usr/bin/ssh-keygen', '-lf', $keyFile, '-E', 'sha256'])));
    return $parts[1];
}
