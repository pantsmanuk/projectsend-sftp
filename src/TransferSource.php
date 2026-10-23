<?php

declare(strict_types=1);

namespace ProjectSendSftp;

use App\Models\User;
use App\Modules\Files\Access\DownloadAllowance;
use App\Modules\Files\Access\ViewableFileScope;
use App\Modules\Files\Models\File;
use App\Modules\Files\Scanning\FileAvailability;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use League\Flysystem\FilesystemException;
use RuntimeException;

final class TransferSource
{
    public function __construct(
        private readonly ViewableFileScope $scope,
        private readonly FileAvailability $availability,
        private readonly DownloadAllowance $allowance,
    ) {}

    public function files(User $actor, string $search = '', int $page = 1): LengthAwarePaginator
    {
        Gate::forUser($actor)->authorize('sftp.transfer');
        $query = $this->scope->for($actor)->available();

        if ($search !== '') {
            $query->where(fn ($query) => $query->where('name', 'like', '%'.$search.'%')
                ->orWhere('original_name', 'like', '%'.$search.'%'));
        }

        return $this->allowance->withCounts($query, $actor)->orderByDesc('id')
            ->paginate(25, ['*'], 'page', $page)
            ->through(function (File $file) use ($actor): array {
                $allowance = $this->allowance->summaryFor($file, $actor);

                return [
                    'id' => $file->id,
                    'name' => $file->name,
                    'original_name' => $file->original_name,
                    'size' => (int) $file->size,
                    'selectable' => ! $allowance['blocked'],
                    'download_allowance' => $allowance,
                ];
            });
    }

    public function select(User $actor, int $fileId): File
    {
        Gate::forUser($actor)->authorize('sftp.transfer');
        $file = $this->scope->for($actor)->findOrFail($fileId);
        $this->availability->guardDelivery($file);
        abort_unless($this->allowance->allows($file, $actor), 403);

        return $file;
    }

    /**
     * The callback receives a readable stream and the exact selected file.
     * The stream belongs to this method and closes even if consumption fails.
     * No source bytes or signed storage URLs are exposed by the HTTP picker.
     *
     * @template T
     * @param callable(resource, File): T $consume
     * @return T
     */
    public function withStream(User $actor, int $fileId, callable $consume): mixed
    {
        $file = $this->select($actor, $fileId);

        try {
            $stream = Storage::disk($file->disk)->readStream($file->path);
        } catch (FilesystemException|InvalidArgumentException) {
            // Storage errors can contain paths, endpoints, or credentials.
            // Do not carry those details into a transfer error or job payload.
            throw new RuntimeException('The selected file could not be read from storage.');
        }

        if (! is_resource($stream)) {
            throw new RuntimeException('The selected file could not be read from storage.');
        }

        try {
            return $consume($stream, $file);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
