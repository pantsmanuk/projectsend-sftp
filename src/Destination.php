<?php

declare(strict_types=1);

namespace ProjectSendSftp;

use Illuminate\Database\Eloquent\Model;

final class Destination extends Model
{
    protected $table = 'sftp_destinations';

    protected $fillable = [
        'name', 'host', 'port', 'username', 'directory', 'host_fingerprint',
        'private_key', 'private_key_passphrase', 'enabled', 'windows_acl_managed',
    ];

    protected $hidden = ['private_key', 'private_key_passphrase'];

    protected $attributes = ['port' => 22, 'enabled' => false, 'windows_acl_managed' => false];

    protected $appends = ['has_private_key', 'has_private_key_passphrase'];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'enabled' => 'boolean',
            'windows_acl_managed' => 'boolean',
            'private_key' => 'encrypted',
            'private_key_passphrase' => 'encrypted',
        ];
    }

    public function getHasPrivateKeyAttribute(): bool
    {
        return ! empty($this->attributes['private_key']);
    }

    public function getHasPrivateKeyPassphraseAttribute(): bool
    {
        return ! empty($this->attributes['private_key_passphrase']);
    }

    public function revision(): string
    {
        $attributes = $this->getRawOriginal();
        ksort($attributes);

        return hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR));
    }

    /**
     * The approved endpoint. Credentials and permission policy may change to
     * recover an existing attempt, but must never redirect it to another path.
     */
    public function remoteIdentity(): array
    {
        return $this->only(['host', 'port', 'username', 'directory', 'host_fingerprint']);
    }
}
