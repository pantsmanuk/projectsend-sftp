<?php

declare(strict_types=1);

namespace ProjectSendSftp;

use Closure;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Exception\NoKeyLoadedException;

final class SaveDestination
{
    /**
     * @param array<string, mixed> $input
     */
    public function save(Authenticatable&Authorizable $actor, array $input, ?Destination $destination = null): Destination
    {
        Gate::forUser($actor)->authorize('sftp.manage-destinations');

        $presence = $destination === null ? 'required' : 'sometimes';
        $validated = Validator::make($input, [
            'name' => [$presence, 'required', 'string', 'max:255', 'not_regex:/[\x00-\x1f\x7f]/'],
            'host' => ['bail', $presence, 'required', 'string', 'max:253', function (string $attribute, mixed $value, Closure $fail): void {
                if (filter_var($value, FILTER_VALIDATE_IP) === false
                    && preg_match('/\A[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*\z/', $value) !== 1) {
                    $fail('Use a hostname or IP address, without a URL scheme or port.');
                }
            }],
            'port' => ['sometimes', 'required', 'integer', 'between:1,65535'],
            'username' => [$presence, 'required', 'string', 'max:64', 'regex:/\A[a-zA-Z0-9_][a-zA-Z0-9_.@-]*\z/'],
            'directory' => ['bail', $presence, 'required', 'string', 'max:1024', function (string $attribute, mixed $value, Closure $fail): void {
                if (! str_starts_with($value, '/') || preg_match('/[\\\\\x00-\x1f\x7f]/', $value)
                    || array_intersect(['.', '..'], explode('/', $value)) !== []) {
                    $fail('Use an absolute directory without dot segments, backslashes, or control characters.');
                }
            }],
            'host_fingerprint' => [$presence, 'required', 'string', 'regex:/\ASHA256:[A-Za-z0-9+\/]{42}[AEIMQUYcgkosw048]\z/'],
            'private_key' => [$destination === null ? 'required' : 'nullable', 'string', 'max:65536'],
            'private_key_passphrase' => ['nullable', 'string', 'max:1024'],
            'enabled' => ['sometimes', 'required', 'boolean'],
            'windows_acl_managed' => ['sometimes', 'required', 'boolean'],
            '_revision' => ['sometimes', 'required', 'string', 'size:64'],
        ])->validate();

        return DB::transaction(function () use ($validated, $destination): Destination {
            // Read the current credential pair under a lock so a stale edit
            // cannot undo another administrator's key rotation.
            $destination = $destination === null
                ? new Destination
                : Destination::query()->lockForUpdate()->findOrFail($destination->getKey());

            if (isset($validated['_revision']) && ! hash_equals($destination->revision(), $validated['_revision'])) {
                throw ValidationException::withMessages([
                    '_revision' => 'Someone changed this destination. Review your values before saving again.',
                ]);
            }
            unset($validated['_revision']);

            $newKey = $validated['private_key'] ?? null;
            $newPassphrase = $validated['private_key_passphrase'] ?? null;
            unset($validated['private_key'], $validated['private_key_passphrase']);

            if (is_string($newKey) && $newKey !== '') {
                $destination->private_key = $newKey;
                // A replacement key takes its own passphrase, never the old one.
                $destination->private_key_passphrase = $newPassphrase === '' ? null : $newPassphrase;
            } elseif (is_string($newPassphrase) && $newPassphrase !== '') {
                $destination->private_key_passphrase = $newPassphrase;
            }

            if (! $destination->exists || $destination->isDirty(['private_key', 'private_key_passphrase'])) {
                try {
                    PublicKeyLoader::loadPrivateKey($destination->private_key, $destination->private_key_passphrase ?? false);
                } catch (NoKeyLoadedException) {
                    throw ValidationException::withMessages([
                        'private_key' => 'The private key could not be opened with the supplied passphrase.',
                    ]);
                }
            }

            $destination->fill($validated)->save();

            return $destination;
        });
    }
}
