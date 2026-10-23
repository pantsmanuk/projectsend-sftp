<?php

declare(strict_types=1);

namespace ProjectSendSftp;

use InvalidArgumentException;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use Throwable;

final class SftpTransport
{
    /**
     * The caller owns the source stream. No seeks, full-file buffering, shell
     * commands, database writes, automatic retries, or overwrites occur here.
     *
     * @param resource $stream
     * @param (callable(int): void)|null $progress bytes sent, not yet published
     * @param string|null $attemptId managed attempt UUID, persisted before calling
     * @param callable|null $guard lease ownership check before publication
     * @return array{path: string, bytes: int, sha256: string}
     */
    public function upload(Destination $destination, $stream, string $filename, int $expectedBytes, string $expectedSha256, ?callable $progress = null, ?string $attemptId = null, ?callable $guard = null): array
    {
        if (! is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new InvalidArgumentException('A readable source stream is required.');
        }
        self::validateFile($filename, $expectedBytes, $expectedSha256, $destination->windows_acl_managed);
        if (! $destination->enabled) {
            throw new SftpTransferException('The destination is disabled.');
        }

        $sftp = null;
        $staging = null;
        $managed = $attemptId !== null;
        $manifest = $managed ? $this->manifest($attemptId, $filename, $expectedBytes, $expectedSha256) : null;
        try {
            if ($guard !== null) { $guard(); }
            $sftp = $this->authenticate($destination);
            $directory = rtrim($destination->directory, '/');
            $finalPath = $directory.'/'.$filename;
            if (! $sftp->is_dir($directory === '' ? '/' : $directory)) {
                throw new SftpTransferException('The configured incoming directory is unavailable.');
            }
            if ($sftp->lstat($finalPath) !== false) {
                throw new SftpTransferException('A file with this destination name already exists.');
            }

            $candidate = $directory.'/.projectsend-'.($managed ? $attemptId : bin2hex(random_bytes(16))).'.upload';
            if ($guard !== null) { $guard(); }
            // mkdir reserves a unique staging location without truncating an
            // existing file or following an existing staging symlink.
            if (! $sftp->mkdir($candidate)) {
                throw new SftpTransferException('Could not create a private staging directory.');
            }
            $staging = $candidate;
            if (! $this->setPermissions($sftp, $destination, $staging, 0700)) {
                throw new SftpTransferException('Could not restrict permissions on the staging directory.');
            }
            if ($managed) {
                if (! $sftp->put($staging.'/manifest', $manifest) || ! $sftp->mkdir($staging.'/writer')
                    || ! $this->setPermissions($sftp, $destination, $staging.'/writer', 0700)) {
                    throw new SftpTransferException('Could not reserve this upload attempt.');
                }
                $partial = $staging.'/writer/payload.part';
            } else {
                $partial = $staging.'/payload.part';
            }
            $bytes = 0;
            $hash = hash_init('sha256');
            $failure = null;
            $reader = function (int $length) use ($stream, $expectedBytes, &$bytes, $hash, &$failure): ?string {
                if ($failure !== null) {
                    return null;
                }
                try {
                    $chunk = fread($stream, $length);
                } catch (Throwable) {
                    $chunk = false;
                }
                if ($chunk === false || ($chunk === '' && ! feof($stream))) {
                    $failure = 'The source stream could not be read completely.';
                    return null;
                }
                if ($chunk === '') {
                    return null;
                }
                $bytes += strlen($chunk);
                if ($bytes > $expectedBytes) {
                    $failure = 'The source size changed before the transfer completed.';
                    return null;
                }
                hash_update($hash, $chunk);

                return $chunk;
            };
            $reportProgress = function (int $bytes) use ($progress, &$failure): void {
                if ($progress !== null && $failure === null) {
                    try {
                        $progress($bytes);
                    } catch (Throwable) {
                        $failure = 'Transfer progress could not be recorded.';
                    }
                }
            };
            // Let put drain outstanding replies and close its handle even if
            // a local callback fails. Throwing inside it desynchronises cleanup.
            $uploaded = $sftp->put($partial, $reader, SFTP::SOURCE_CALLBACK, -1, -1, $reportProgress);
            if ($failure !== null) {
                throw new SftpTransferException($failure);
            }
            if (! $uploaded) {
                throw new SftpTransferException('The SFTP upload did not complete.');
            }
            $checksum = hash_final($hash);
            if ($bytes !== $expectedBytes || ! hash_equals(strtolower($expectedSha256), $checksum)) {
                throw new SftpTransferException('The source size or checksum does not match the selected backup.');
            }
            $stat = $sftp->stat($partial);
            if ($stat === false || (int) ($stat['size'] ?? -1) !== $expectedBytes) {
                throw new SftpTransferException('The uploaded file size could not be verified.');
            }
            // Unix destinations grant database-service reads before publication.
            // Windows destinations inherit administrator-provisioned ACLs.
            if (! $this->setPermissions($sftp, $destination, $partial, 0644)) {
                throw new SftpTransferException('Could not set permissions on the uploaded backup.');
            }
            if ($guard !== null) { $guard(); }
            if ($managed && ! $sftp->put($staging.'/writer/ready', $manifest)) {
                throw new SftpTransferException('Could not record publication intent.');
            }
            if ($guard !== null) { $guard(); }
            if (! $sftp->rename($partial, $finalPath)) {
                throw new SftpTransferException('Could not publish the backup without overwriting an existing file.');
            }

            return ['path' => $finalPath, 'bytes' => $bytes, 'sha256' => $checksum];
        } catch (SftpTransferException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new SftpTransferException('The SFTP connection or transfer failed.');
        } finally {
            if ($sftp !== null) {
                // Connection loss can prevent cleanup. Never remove the final
                // path: a lost rename acknowledgement has an uncertain outcome.
                try {
                    if (! $managed && $staging !== null && $sftp->isConnected()) {
                        $sftp->delete($staging.'/payload.part', false);
                        $sftp->rmdir($staging);
                    }
                } catch (Throwable) {
                    // Hidden staging artifacts are reconciled by later job work.
                }
                try {
                    $sftp->disconnect();
                } catch (Throwable) {
                    // Disconnect must not replace the transfer result or error.
                }
            }
        }
    }

    /**
     * Fence a previous writer before inspecting publication. The returned path
     * proves this attempt published; null proves it cannot publish and no final
     * file exists. Any ambiguity throws and forbids a retry.
     */
    public function reconcile(Destination $destination, string $attemptId, string $filename, int $bytes, string $sha256, callable $guard): ?string
    {
        self::validateFile($filename, $bytes, $sha256, $destination->windows_acl_managed);
        $manifest = $this->manifest($attemptId, $filename, $bytes, $sha256);
        $root = rtrim($destination->directory, '/').'/.projectsend-'.$attemptId.'.upload';
        $final = rtrim($destination->directory, '/').'/'.$filename;
        $sftp = null;
        try {
            $guard();
            $sftp = $this->authenticate($destination);
            $entries = $this->entries($sftp, rtrim($destination->directory, '/') ?: '/');
            $rootName = basename($root);
            if (! isset($entries[$rootName])) {
                // Occupy the original name permanently. A stale worker paused
                // before its mkdir can never reserve this attempt afterwards.
                if (! $sftp->mkdir($root) || ! $this->setPermissions($sftp, $destination, $root, 0700)) {
                    throw new SftpTransferException('Could not reserve the recovery barrier.');
                }
                if (! $sftp->put($root.'/manifest', $manifest)) {
                    throw new SftpTransferException('Could not record the recovery barrier.');
                }
            } elseif (($entries[$rootName]['type'] ?? null) !== 2) {
                throw new SftpTransferException('The attempt directory is not a regular directory.');
            }
            $guard();
            $rootEntries = $this->entries($sftp, $root);
            if (array_diff(array_keys($rootEntries), ['manifest', 'writer', 'sealed', 'fence', 'unpublished']) !== []) {
                throw new SftpTransferException('The attempt directory contains unrecognised objects.');
            }
            if ($rootEntries === [] && ! $sftp->put($root.'/manifest', $manifest)) {
                throw new SftpTransferException('Could not record the recovery barrier.');
            }
            $rootEntries = $this->entries($sftp, $root);
            if (isset($rootEntries['manifest']) && ! $this->matches($sftp, $root.'/manifest', $rootEntries['manifest'], $manifest)) {
                throw new SftpTransferException('The attempt identity could not be verified.');
            }
            if (isset($rootEntries['writer']) && ($rootEntries['writer']['type'] ?? null) === 2) {
                // Moving the directory invalidates the old writer's publication
                // path, even while it still has an open handle to its payload.
                if (! $sftp->rename($root.'/writer', $root.'/sealed')) {
                    throw new SftpTransferException('Could not isolate the previous writer.');
                }
            }
            $rootEntries = $this->entries($sftp, $root);
            if (! isset($rootEntries['writer'])) {
                $fence = 'ProjectSend fenced '.$attemptId;
                if (! $sftp->put($root.'/fence', $fence) || ! $sftp->rename($root.'/fence', $root.'/writer')) {
                    throw new SftpTransferException('Could not finish fencing the previous writer.');
                }
            }
            $rootEntries = $this->entries($sftp, $root);
            if (($rootEntries['writer']['type'] ?? null) !== 1
                || ! $this->matches($sftp, $root.'/writer', $rootEntries['writer'], 'ProjectSend fenced '.$attemptId)) {
                throw new SftpTransferException('The previous writer is not fenced.');
            }
            // A missing/partial manifest cannot be guessed at. Leave all data
            // intact so another inspection or an operator can resolve it.
            if (($rootEntries['manifest']['type'] ?? null) !== 1 || ! $this->matches($sftp, $root.'/manifest', $rootEntries['manifest'], $manifest)) {
                throw new SftpTransferException('The attempt identity could not be verified.');
            }
            $guard();
            if (isset($rootEntries['sealed']) && ($rootEntries['sealed']['type'] ?? null) !== 2) {
                throw new SftpTransferException('The isolated attempt is not a regular directory.');
            }
            $sealed = isset($rootEntries['sealed']) ? $this->entries($sftp, $root.'/sealed') : [];
            if (array_diff(array_keys($sealed), ['ready', 'payload.part']) !== []) {
                throw new SftpTransferException('The isolated attempt contains unrecognised objects.');
            }
            $ready = ($sealed['ready']['type'] ?? null) === 1 && $this->matches($sftp, $root.'/sealed/ready', $sealed['ready'], $manifest);
            if (isset($sealed['ready']) && ! $ready) {
                throw new SftpTransferException('The publication receipt could not be verified.');
            }
            $unpublished = isset($rootEntries['unpublished']);
            if ($unpublished && ! $this->matches($sftp, $root.'/unpublished', $rootEntries['unpublished'], $manifest)) {
                throw new SftpTransferException('The non-publication receipt could not be verified.');
            }
            $published = $ready && ! isset($sealed['payload.part']) && ! $unpublished;
            $finalEntries = $this->entries($sftp, rtrim($destination->directory, '/') ?: '/');
            if ($published) {
                if (($finalEntries[$filename]['type'] ?? null) !== 1 || (int) ($finalEntries[$filename]['size'] ?? -1) !== $bytes) {
                    throw new SftpTransferException('The published backup is missing or has changed.');
                }
                $hash = hash_init('sha256');
                $read = 0;
                $verified = $sftp->get($final, function (string $chunk) use ($hash, &$read, $bytes, $guard): void {
                    $guard();
                    $read += strlen($chunk);
                    if ($read > $bytes) {
                        throw new SftpTransferException('The published backup has changed.');
                    }
                    hash_update($hash, $chunk);
                });
                if ($verified === false || $read !== $bytes || ! hash_equals(strtolower($sha256), hash_final($hash))) {
                    throw new SftpTransferException('The published backup checksum could not be verified.');
                }
                $guard();
                return $final;
            }
            // Persist the negative outcome BEFORE removing its evidence. A
            // later recovery must not mistake our cleanup for publication.
            if (! $unpublished) {
                $guard();
                if (! $sftp->put($root.'/unpublished', $manifest)) {
                    throw new SftpTransferException('Could not record the non-publication receipt.');
                }
            }
            if (isset($sealed['payload.part'])) {
                $guard();
                if (($sealed['payload.part']['type'] ?? null) !== 1 || ! $sftp->delete($root.'/sealed/payload.part', false)) {
                    throw new SftpTransferException('Could not remove the isolated partial upload.');
                }
            }
            if (isset($finalEntries[$filename])) {
                throw new SftpTransferException('An existing destination file cannot be attributed to this attempt.');
            }
            $guard();
            return null;
        } catch (SftpTransferException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new SftpTransferException('SFTP reconciliation could not confirm a safe outcome.');
        } finally {
            try { $sftp?->disconnect(); } catch (Throwable) {}
        }
    }

    /**
     * Remove only the successful attempt's known metadata. The caller must have
     * committed Completed and its accounting before calling. Never sweep older
     * fenced attempts: their writers may still resume after this delivery.
     */
    public function cleanupCompleted(Destination $destination, string $attemptId, string $filename, int $bytes, string $sha256): void
    {
        $manifest = $this->manifest($attemptId, $filename, $bytes, $sha256);
        $directory = rtrim($destination->directory, '/') ?: '/';
        $name = '.projectsend-'.$attemptId.'.upload';
        $root = rtrim($directory, '/').'/'.$name;
        $sftp = null;
        try {
            $sftp = $this->authenticate($destination);
            $parent = $this->entries($sftp, $directory);
            if (! isset($parent[$name])) { return; } // Already removed.
            if (($parent[$name]['type'] ?? null) !== 2) {
                throw new SftpTransferException('The completed attempt is not a regular directory.');
            }
            $entries = $this->entries($sftp, $root);
            if (array_diff(array_keys($entries), ['manifest', 'writer', 'sealed']) !== []) {
                throw new SftpTransferException('The completed attempt contains unrecognised objects.');
            }
            // Delete the manifest last. Its absence is safe only in an empty
            // directory left after a previously interrupted cleanup.
            if ($entries !== [] && (! isset($entries['manifest'])
                || ! $this->matches($sftp, $root.'/manifest', $entries['manifest'], $manifest))) {
                throw new SftpTransferException('The completed attempt identity could not be verified.');
            }
            $files = [];
            $directories = [];
            foreach (['writer', 'sealed'] as $child) {
                if (! isset($entries[$child])) { continue; }
                $path = $root.'/'.$child;
                if ($child === 'writer' && ($entries[$child]['type'] ?? null) === 1
                    && $this->matches($sftp, $path, $entries[$child], 'ProjectSend fenced '.$attemptId)) {
                    $files[] = $path;
                    continue;
                }
                if (($entries[$child]['type'] ?? null) !== 2) {
                    throw new SftpTransferException('The completed attempt contains an unexpected object.');
                }
                $contents = $this->entries($sftp, $path);
                if (array_diff(array_keys($contents), ['ready']) !== []
                    || (isset($contents['ready']) && ! $this->matches($sftp, $path.'/ready', $contents['ready'], $manifest))) {
                    throw new SftpTransferException('The completed attempt still contains a payload or unrecognised objects.');
                }
                if (isset($contents['ready'])) { $files[] = $path.'/ready'; }
                $directories[] = $path;
            }
            // Validate the entire shape before deleting anything. Non-recursive
            // operations leave unexpected files and symlinks alone.
            foreach ($files as $path) {
                if (! $sftp->delete($path, false)) {
                    throw new SftpTransferException('Could not remove a completed attempt receipt.');
                }
            }
            foreach ($directories as $path) {
                if (! $sftp->rmdir($path)) {
                    throw new SftpTransferException('Could not remove a completed attempt directory.');
                }
            }
            if ((isset($entries['manifest']) && ! $sftp->delete($root.'/manifest', false)) || ! $sftp->rmdir($root)) {
                throw new SftpTransferException('Could not finish completed attempt cleanup.');
            }
        } catch (SftpTransferException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new SftpTransferException('Completed attempt cleanup could not finish.');
        } finally {
            try { $sftp?->disconnect(); } catch (Throwable) {}
        }
    }

    private function setPermissions(SFTP $sftp, Destination $destination, string $path, int $mode): bool
    {
        // SFTP v3 cannot inspect NTFS ACLs. This explicit administrator setting
        // delegates staging privacy and backup readability to server ACLs.
        if ($destination->windows_acl_managed) {
            return true;
        }

        $permissions = $sftp->chmod($mode, $path);

        return $permissions !== false && ($permissions & 0777) === $mode;
    }

    private function matches(SFTP $sftp, string $path, array $attributes, string $expected): bool
    {
        return ($attributes['type'] ?? null) === 1 && (int) ($attributes['size'] ?? -1) === strlen($expected)
            && $sftp->get($path, false, 0, strlen($expected) + 1) === $expected;
    }

    private function entries(SFTP $sftp, string $directory): array
    {
        $entries = $sftp->rawlist($directory);
        if (! is_array($entries)) {
            throw new SftpTransferException('The remote directory could not be inspected.');
        }
        unset($entries['.'], $entries['..']);
        return $entries;
    }

    private function manifest(string $attemptId, string $filename, int $bytes, string $sha256): string
    {
        self::validateFile($filename, $bytes, $sha256);
        if (preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/', $attemptId) !== 1) {
            throw new InvalidArgumentException('A valid attempt UUID is required.');
        }
        return json_encode(['attempt' => $attemptId, 'filename' => $filename, 'bytes' => $bytes, 'sha256' => strtolower($sha256)], JSON_THROW_ON_ERROR);
    }

    private function authenticate(Destination $destination): SFTP
    {
        $sftp = $this->connectPinned($destination);
        try {
            $key = PublicKeyLoader::loadPrivateKey($destination->private_key, $destination->private_key_passphrase ?? false);
            if (! $sftp->login($destination->username, $key)) {
                throw new SftpTransferException('SSH authentication failed.');
            }
            $sftp->disableStatCache();
            $sftp->setPreferredVersion(3);
            return $sftp;
        } catch (Throwable $exception) {
            try { $sftp->disconnect(); } catch (Throwable) {}
            throw $exception;
        }
    }

    public static function validateFile(string $filename, int $expectedBytes, string $expectedSha256, bool $windows = false): void
    {
        if ($expectedBytes < 0 || preg_match('/\A[a-fA-F0-9]{64}\z/', $expectedSha256) !== 1) {
            throw new InvalidArgumentException('A byte count and SHA-256 checksum are required.');
        }
        if ($filename === '' || strlen($filename) > 255 || in_array($filename, ['.', '..'], true)
            || preg_match('/[\/\\\\\x00-\x1f\x7f]/', $filename)) {
            throw new InvalidArgumentException('The destination filename must be one path segment of at most 255 bytes.');
        }
        // NTFS stream syntax and normalised/reserved names are not standalone
        // backup files, even though some are valid Unix filename segments.
        if ($windows && (preg_match('/[<>:"|?*]|[. ]\z/u', $filename) !== 0
            || preg_match('/\A(?:CON|PRN|AUX|NUL|COM[1-9¹²³]|LPT[1-9¹²³])(?:[. ]|\z)/iu', $filename) !== 0)) {
            throw new InvalidArgumentException('The destination filename is not a safe Windows filename.');
        }
    }

    private function connectPinned(Destination $destination): SFTP
    {
        $remaining = ['ssh-ed25519', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521', 'rsa-sha2-512', 'rsa-sha2-256'];
        while ($remaining !== []) {
            $sftp = new SFTP($destination->host, $destination->port, 30);
            $sftp->setTimeout(30);
            $sftp->setPreferredAlgorithms(['hostkey' => $remaining]);
            try {
                $key = $sftp->getServerPublicHostKey();
                $wireKey = is_string($key) ? base64_decode(explode(' ', $key, 2)[1] ?? '', true) : false;
                if (is_string($wireKey) && $wireKey !== '' && hash_equals(
                    $destination->host_fingerprint,
                    'SHA256:'.rtrim(base64_encode(hash('sha256', $wireKey, true)), '='),
                )) {
                    return $sftp;
                }
                // The fingerprint may identify another key offered by this
                // server. Retry only offered modern algorithms, before login.
                $remaining = array_values(array_intersect(
                    array_diff($remaining, [$sftp->getAlgorithmsNegotiated()['hostkey']]),
                    $sftp->getServerAlgorithms()['hostkey'],
                ));
            } catch (Throwable) {
                $sftp->disconnect();
                throw new SftpTransferException('Could not establish a verified SSH connection.');
            }
            $sftp->disconnect();
        }

        throw new SftpTransferException('The server SSH host key does not match the trusted fingerprint.');
    }
}
