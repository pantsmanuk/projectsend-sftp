<?php

declare(strict_types=1);

namespace ProjectSendSftp;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class TransferSelectionController
{
    public function files(Request $request, TransferSource $source): JsonResponse
    {
        Gate::authorize('sftp.transfer');
        $input = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $files = $source->files($request->user(), $input['q'] ?? '', (int) ($input['page'] ?? 1));

        return response()->json([
            'data' => $files->items(),
            'meta' => [
                'current_page' => $files->currentPage(),
                'last_page' => $files->lastPage(),
                'per_page' => $files->perPage(),
                'total' => $files->total(),
            ],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function destinations(): JsonResponse
    {
        Gate::authorize('sftp.transfer');

        return response()->json([
            'data' => Destination::query()->where('enabled', true)->orderBy('name')->orderBy('id')
                ->get(['id', 'name'])->map(fn (Destination $destination): array => [
                    'id' => $destination->id, 'name' => $destination->name,
                ]),
        ])->header('Cache-Control', 'private, no-store');
    }
}
