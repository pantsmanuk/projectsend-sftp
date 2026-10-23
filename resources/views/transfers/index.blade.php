@extends('sftp::layout')
@section('title', __('My transfers'))
@section('module-name', __('SFTP transfers'))
@section('breadcrumbs')<span aria-current="page">{{ __('My transfers') }}</span>@endsection
@section('content')
    <div class="page-heading"><div><h1>{{ __('My transfers') }}</h1><p class="muted">{{ __('Backups you have requested to send, newest first.') }}</p></div><a class="button primary" href="{{ route('sftp.transfers.create') }}">{{ __('Send backup') }}</a></div>
    @if($transfers->isEmpty())
        <section class="panel empty"><h2>{{ __('No transfers yet') }}</h2><p class="muted">{{ __('Choose a backup and an approved destination to send your first copy.') }}</p><a class="button primary" href="{{ route('sftp.transfers.create') }}">{{ __('Choose backup') }}</a></section>
    @else
        <div class="panel table-wrap" role="region" aria-label="{{ __('Your backup transfers') }}" tabindex="0"><table><caption class="sr-only">{{ __('Your backup transfers') }}</caption><thead><tr><th scope="col">{{ __('Backup') }}</th><th scope="col">{{ __('Destination') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('Requested') }}</th></tr></thead><tbody>
        @foreach($rows as $row)
            <tr><th scope="row"><a href="{{ route('sftp.transfers.show', $row['id']) }}">{{ $row['file_name'] }}</a><span class="file-caption"><code>{{ $row['filename'] }}</code></span></th><td>{{ $row['destination_name'] }}</td><td><span class="badge {{ $row['status'] === 'completed' ? 'enabled' : '' }}">{{ $row['label'] }}</span>@if($row['elapsed_seconds'] !== null && ! in_array($row['status'], ['queued', 'running']))@include('sftp::transfers.duration')@endif</td><td><time datetime="{{ $row['created_at'] }}">{{ $row['created_label'] }}</time></td></tr>
        @endforeach
        </tbody></table></div>
        @include('sftp::pagination', ['paginator' => $transfers, 'label' => __('Transfer pages')])
    @endif
@endsection
