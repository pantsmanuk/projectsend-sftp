@extends('sftp::layout')
@section('title', __('Send backup'))
@section('module-name', __('SFTP transfers'))
@section('breadcrumbs')<a href="{{ route('sftp.transfers.index') }}">{{ __('My transfers') }}</a><span aria-hidden="true">/</span><span aria-current="page">{{ __('Choose backup') }}</span>@endsection
@section('content')
    <div class="page-heading"><div><h1>{{ __('Send backup') }}</h1><p class="muted">{{ __('Choose a backup from your file library, then review its destination.') }}</p></div></div>
    <form method="get" action="{{ route('sftp.transfers.create') }}" class="search-form" data-search-form novalidate>
        <div class="field"><label for="q">{{ __('Search backups') }}</label><input type="search" id="q" name="q" value="{{ $q }}" maxlength="255" aria-describedby="q-error" aria-invalid="{{ $errors->has('q') ? 'true' : 'false' }}" @if($errors->has('q')) autofocus @endif><p class="field-error" id="q-error">{{ $errors->first('q') }}</p></div>
        <button class="button secondary" type="submit">{{ __('Search') }}</button>
        @if($q !== '')<a class="button secondary" href="{{ route('sftp.transfers.create') }}#q" aria-label="{{ __('Clear search') }}">{{ __('Clear search') }}</a>@endif
    </form>
    @if($files->isEmpty())
        <section class="panel empty"><h2>{{ $q !== '' ? __('No matching backups') : __('No backups available') }}</h2><p class="muted">{{ $q !== '' ? __('Try a different filename or clear your search.') : __('Only available files in your library appear here. Ask a colleague to upload or share the backup you need.') }}</p></section>
    @else
        <div class="panel table-wrap" role="region" aria-label="{{ __('Available backups') }}" tabindex="0"><table><caption class="sr-only">{{ __('Choose one backup to send') }}</caption><thead><tr><th scope="col">{{ __('Backup') }}</th><th scope="col">{{ __('Size') }}</th><th scope="col">{{ __('Action') }}</th></tr></thead><tbody>
        @foreach($files as $file)
            <tr><th scope="row"><span>{{ $file['name'] }}</span><span class="file-caption"><code>{{ $file['original_name'] }}</code></span></th><td class="size-cell">{{ Illuminate\Support\Number::fileSize($file['size'], 2) }}</td><td>@if($file['selectable'])<a class="button secondary" href="{{ route('sftp.transfers.review', $file['id']) }}" aria-label="{{ __('Choose :name', ['name' => $file['name']]) }}">{{ __('Choose backup') }}</a>@else<span class="muted">{{ __('Download limit reached') }}</span>@endif</td></tr>
        @endforeach
        </tbody></table></div>
        @include('sftp::pagination', ['paginator' => $files, 'label' => __('Backup pages')])
    @endif
@endsection
