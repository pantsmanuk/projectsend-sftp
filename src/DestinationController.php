<?php

declare(strict_types=1);

namespace ProjectSendSftp;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DestinationController
{
    public function index(Request $request): Response
    {
        return $this->page($request, 'index', fn (): array => [
            'destinations' => Destination::query()->orderBy('name')->orderBy('id')->paginate(20),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->page($request, 'form', fn (): array => ['destination' => new Destination]);
    }

    public function edit(Request $request, Destination $destination): Response
    {
        return $this->page($request, 'form', fn (): array => ['destination' => $destination]);
    }

    public function store(Request $request, SaveDestination $save): RedirectResponse
    {
        $destination = $save->save($request->user(), $this->input($request));

        return $this->saved($destination);
    }

    public function update(Request $request, Destination $destination, SaveDestination $save): RedirectResponse
    {
        $destination = $save->save($request->user(), $this->input($request), $destination);

        return $this->saved($destination);
    }

    public function confirmDeletion(Request $request, Destination $destination): Response
    {
        return $this->page($request, 'delete', fn (): array => ['destination' => $destination]);
    }

    public function destroy(Request $request, Destination $destination): RedirectResponse
    {
        Gate::authorize('sftp.manage-destinations');
        $validated = $request->validate([
            'confirm_delete' => ['accepted'],
            '_revision' => ['required', 'string', 'size:64'],
        ]);

        $name = DB::transaction(function () use ($destination, $validated): string {
            $current = Destination::query()->lockForUpdate()->findOrFail($destination->getKey());
            if (! hash_equals($current->revision(), $validated['_revision'])) {
                throw ValidationException::withMessages([
                    '_revision' => __('This destination changed after you opened the confirmation page. Review its current details before deleting it.'),
                ]);
            }

            if (Transfer::query()->where('destination_id', $current->getKey())
                ->whereIn('status', [TransferStatus::Queued, TransferStatus::Running, TransferStatus::Uncertain])->exists()) {
                throw ValidationException::withMessages([
                    '_revision' => __('This destination has queued, running, or unresolved transfers. Resolve them before deleting it.'),
                ]);
            }

            $name = $current->name;
            $current->delete();

            return $name;
        });

        return redirect()->route('sftp.destinations.index', [], 303)
            ->with('success', __('Destination ":name" deleted.', ['name' => $name]))
            ->header('Cache-Control', 'private, no-store');
    }

    private function page(Request $request, string $view, callable $data): Response
    {
        Gate::authorize('sftp.manage-destinations');

        // The host's sidebar uses Inertia links. Ask it to perform a full
        // same-tab visit to these independently packaged Blade pages.
        $response = $request->header('X-Inertia')
            ? response('', 409)->header('X-Inertia-Location', $request->fullUrl())
            : response()->view('sftp::'.$view, $data());

        return $response->header('Cache-Control', 'private, no-store')->header('Vary', 'X-Inertia');
    }

    private function input(Request $request): array
    {
        Gate::authorize('sftp.manage-destinations');
        $request->validate(['private_key' => ['nullable', 'file', 'max:64']]);
        $input = $request->only(['name', 'host', 'port', 'username', 'directory', 'host_fingerprint', 'enabled', 'windows_acl_managed', 'private_key_passphrase', '_revision']);

        if ($file = $request->file('private_key')) {
            $input['private_key'] = $file->get();
        }

        return $input;
    }

    private function saved(Destination $destination): RedirectResponse
    {
        return redirect()->route('sftp.destinations.index', [], 303)
            ->with('success', __('Destination ":name" saved.', ['name' => $destination->name]))
            ->header('Cache-Control', 'private, no-store');
    }
}
