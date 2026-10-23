<?php

declare(strict_types=1);

namespace ProjectSendSftp;

use App\Modules\Files\Models\File;
use Illuminate\Database\Eloquent\Model;

final class Transfer extends Model
{
    protected $table = 'sftp_transfers';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $attributes = ['status' => 'queued', 'bytes_sent' => 0];

    protected $hidden = [
        'source_disk', 'source_path', 'sha256', 'destination_revision', 'remote_path',
        'attempt_id', 'execution_token', 'lease_expires_at', 'remote_identity', 'actor_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'status' => TransferStatus::class,
            'requested_by' => 'integer',
            'file_id' => 'integer',
            'destination_id' => 'integer',
            'total_bytes' => 'integer',
            'bytes_sent' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'attempts' => 'integer',
            'remote_identity' => 'array',
            'actor_snapshot' => 'array',
        ];
    }

    /** @return array{source_disk: string, source_path: string, filename: string, total_bytes: int, sha256: string} */
    public static function sourceIdentity(File $file): array
    {
        return [
            'source_disk' => (string) $file->disk,
            'source_path' => (string) $file->path,
            'filename' => (string) $file->original_name,
            'total_bytes' => (int) $file->size,
            'sha256' => strtolower((string) $file->checksum),
        ];
    }
}
