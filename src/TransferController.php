<?php

declare(strict_types=1);

namespace ProjectSendSftp;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class TransferController
{
    public function index(Request $request): Response|RedirectResponse
    {
        Gate::authorize('sftp.transfer');
        $page = $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $transfers = Transfer::query()->where('requested_by', $request->user()->getKey())
            ->where(fn ($query) => $query->where('created_at', '>=', now()->subMonthsNoOverflow(6))
                ->orWhereIn('status', [TransferStatus::Queued, TransferStatus::Running, TransferStatus::Uncertain]))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(20, ['*'], 'page', (int) ($page['page'] ?? 1));
        if ($transfers->currentPage() > $transfers->lastPage()) {
            return redirect()->route('sftp.transfers.index', ['page' => $transfers->lastPage()]);
        }
        return $this->page($request, 'transfers.index', [
            'transfers' => $transfers,
            'rows' => $transfers->map(fn (Transfer $row) => $this->summary($request, $row)),
        ]);
    }

    public function create(Request $request, TransferSource $source): Response|RedirectResponse
    {
        Gate::authorize('sftp.transfer');
        $input = $request->validate(['q' => ['nullable', 'string', 'max:255'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $q = $input['q'] ?? '';
        $files = $source->files($request->user(), $q, (int) ($input['page'] ?? 1));
        $files->appends(['q' => $q]);
        if ($files->currentPage() > $files->lastPage()) {
            return redirect()->route('sftp.transfers.create', ['q' => $q, 'page' => $files->lastPage()]);
        }
        return $this->page($request, 'transfers.files', ['files' => $files, 'q' => $q]);
    }

    public function review(Request $request, int $file, TransferSource $source): Response
    {
        Gate::authorize('sftp.transfer');
        $selected = $source->select($request->user(), $file);
        $available = true;
        try {
            SftpTransport::validateFile($selected->original_name, (int) $selected->size, (string) $selected->checksum);
            TransferBackup::requireAsyncQueue();
        } catch (InvalidArgumentException|SftpTransferException) {
            $available = false;
        }
        return $this->page($request, 'transfers.review', [
            'file' => $selected,
            'destinations' => Destination::query()->where('enabled', true)->orderBy('name')->orderBy('id')->get(['id', 'name']),
            'requestId' => old('_request_id', (string) Str::uuid()), 'available' => $available,
        ]);
    }

    public function store(Request $request, RequestTransfer $submit): RedirectResponse
    {
        Gate::authorize('sftp.transfer');
        $input = $request->validate([
            '_request_id' => ['required', 'uuid'], 'file_id' => ['required', 'integer', 'min:1'],
            'destination_id' => ['required', 'integer', 'min:1'],
        ], [], ['_request_id' => __('send request'), 'file_id' => __('backup'), 'destination_id' => __('destination')]);
        try {
            $transfer = $submit->submit($request->user(), (int) $input['file_id'], (int) $input['destination_id'], $input['_request_id']);
        } catch (SftpTransferException|InvalidArgumentException) {
            throw ValidationException::withMessages(['destination_id' => __('The transfer could not be queued. Review the destination or ask an administrator to check the service.')]);
        }
        return redirect()->route('sftp.transfers.show', $transfer, 303)
            ->with('success', __('Transfer request recorded. You can leave this page and check its progress later.'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, Transfer $transfer): Response
    {
        $this->authorize($request, $transfer);
        return $this->page($request, 'transfers.show', ['row' => $this->summary($request, $transfer)]);
    }

    public function status(Request $request, Transfer $transfer): JsonResponse
    {
        $this->authorize($request, $transfer);
        return response()->json(['data' => $this->summary($request, $transfer)])->header('Cache-Control', 'private, no-store');
    }

    private function authorize(Request $request, Transfer $transfer): void
    {
        Gate::authorize('sftp.transfer');
        // Configuration authority never grants access to another user's history.
        abort_unless($transfer->requested_by === $request->user()->getKey(), 404);
    }

    private function summary(Request $request, Transfer $transfer): array
    {
        [$label, $message] = match ($transfer->status) {
            TransferStatus::Queued => [__('Waiting to start'), __('Your transfer is waiting to start. You can leave this page and return later.')],
            TransferStatus::Running => [__('Sending backup'), __('Bytes sent are shown below. Delivery is complete only after the final checks pass.')],
            TransferStatus::Completed => [__('Completed'), __('The backup was delivered. Database restoration remains a separate step.')],
            TransferStatus::Failed => [__('Not completed'), __('This transfer did not complete. Ask an administrator to check it before sending this backup again.')],
            TransferStatus::Uncertain => [__('Needs checking'), __('Delivery has not been confirmed. Ask an administrator to check this transfer before sending the backup again.')],
        };
        $sent = min($transfer->total_bytes, max(0, $transfer->bytes_sent));
        $percent = $transfer->status === TransferStatus::Completed ? 100
            : ($transfer->total_bytes > 0 ? (int) floor($sent / $transfer->total_bytes * 100) : 0);
        $timezone = $request->user()->timezone ?: config('app.timezone', 'UTC');
        $elapsed = $transfer->started_at !== null && ($transfer->status === TransferStatus::Running || $transfer->finished_at !== null)
            ? max(0, ($transfer->finished_at ?? now())->getTimestamp() - $transfer->started_at->getTimestamp()) : null;
        return [
            'id' => $transfer->id, 'file_name' => $transfer->file_name, 'filename' => $transfer->filename,
            'destination_name' => $transfer->destination_name, 'status' => $transfer->status->value,
            'label' => $label, 'message' => $message, 'percent' => $percent,
            'bytes_sent' => $sent, 'total_bytes' => $transfer->total_bytes,
            'size' => Number::fileSize($transfer->total_bytes, 2),
            'progress_text' => __(':sent of :total sent', ['sent' => Number::fileSize($sent, 2), 'total' => Number::fileSize($transfer->total_bytes, 2)]),
            'created_at' => $transfer->created_at->toIso8601String(),
            'created_label' => $transfer->created_at->timezone($timezone)->locale(app()->getLocale())->isoFormat('lll').' '.$timezone,
            'elapsed_seconds' => $elapsed,
            'elapsed_label' => $elapsed === null ? '' : ($transfer->status === TransferStatus::Completed ? __('Duration:') : __('Elapsed time:')),
            'elapsed_text' => $elapsed === null ? '' : sprintf('%02d:%02d:%02d', intdiv($elapsed, 3600), intdiv($elapsed % 3600, 60), $elapsed % 60),
        ];
    }

    private function page(Request $request, string $view, array $data): Response
    {
        $response = $request->header('X-Inertia')
            ? response('', 409)->header('X-Inertia-Location', $request->fullUrl())
            : response()->view('sftp::'.$view, $data);
        return $response->header('Cache-Control', 'private, no-store')->header('Vary', 'X-Inertia');
    }
}
