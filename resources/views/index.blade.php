@extends('sftp::layout')
@section('title', __('SFTP destinations'))
@section('breadcrumbs')<span aria-current="page">{{ __('SFTP destinations') }}</span>@endsection
@section('content')
    <div class="page-heading"><div><h1>{{ __('SFTP destinations') }}</h1><p class="muted">{{ __('Manage the servers that receive customer backups.') }}</p></div><a class="button primary" href="{{ route('sftp.destinations.create') }}">{{ __('Add destination') }}</a></div>
    <p class="notice">{{ __('Staff send backups from the separate Send backup page. Saving a destination does not connect to the server.') }}</p>
    @if($destinations->isEmpty())
        <section class="empty panel"><span class="empty-symbol" aria-hidden="true">⇢</span><h2>{{ __('No destinations yet') }}</h2><p class="muted">{{ __('Add an approved server, its incoming directory, and an SSH key. New destinations start disabled.') }}</p><a class="button primary" href="{{ route('sftp.destinations.create') }}">{{ __('Add your first destination') }}</a></section>
    @else
        <div class="panel table-wrap" role="region" aria-label="{{ __('Configured SFTP destinations') }}" tabindex="0"><table><caption class="sr-only">{{ __('Configured SFTP destinations') }}</caption><thead><tr><th scope="col">{{ __('Destination') }}</th><th scope="col">{{ __('Connection') }}</th><th scope="col">{{ __('Incoming directory') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col"><span class="sr-only">{{ __('Actions') }}</span></th></tr></thead><tbody>
        @foreach($destinations as $destination)
            <tr><th scope="row">{{ $destination->name }}</th><td><code>{{ $destination->username.'@'.$destination->host.':'.$destination->port }}</code></td><td><code>{{ $destination->directory }}</code></td><td><span class="badge {{ $destination->enabled ? 'enabled' : '' }}">{{ $destination->enabled ? __('Enabled') : __('Disabled') }}</span></td><td><a class="edit-link" href="{{ route('sftp.destinations.edit', $destination) }}" aria-label="{{ __('Edit :name', ['name' => $destination->name]) }}">{{ __('Edit') }}</a></td></tr>
        @endforeach
        </tbody></table></div>
        @if($destinations->hasPages())<nav class="pagination" aria-label="{{ __('Destination pages') }}">@if($destinations->previousPageUrl())<a class="button secondary" href="{{ $destinations->previousPageUrl() }}">{{ __('Previous') }}</a>@endif<span>{{ __('Page :current of :last', ['current' => $destinations->currentPage(), 'last' => $destinations->lastPage()]) }}</span>@if($destinations->nextPageUrl())<a class="button secondary" href="{{ $destinations->nextPageUrl() }}">{{ __('Next') }}</a>@endif</nav>@endif
    @endif
@endsection
