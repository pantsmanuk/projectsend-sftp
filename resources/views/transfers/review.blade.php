@extends('sftp::layout')
@section('title', __('Review transfer'))
@section('module-name', __('SFTP transfers'))
@section('leave-note', __('Your destination selection will be discarded. No backup has been sent.'))
@section('breadcrumbs')<a href="{{ route('sftp.transfers.index') }}">{{ __('My transfers') }}</a><span aria-hidden="true">/</span><a href="{{ route('sftp.transfers.create') }}">{{ __('Choose backup') }}</a><span aria-hidden="true">/</span><span aria-current="page">{{ __('Review transfer') }}</span>@endsection
@section('content')
    <div class="page-heading"><div><h1>{{ __('Review transfer') }}</h1><p class="muted">{{ __('Send one copy to an approved destination.') }}</p></div></div>
    @if($errors->any())<div class="notice error" role="alert" tabindex="-1" data-form-error><h2>{{ __('Transfer not sent') }}</h2>@foreach($errors->all() as $message)<p>{{ $message }}</p>@endforeach</div>@endif
    <section class="panel form-section"><div class="section-heading"><h2>{{ __('Selected backup') }}</h2><p class="muted">{{ __('The original filename is used at the destination.') }}</p></div><div class="selected-backup"><h2>{{ $file->name }}</h2><p><code>{{ $file->original_name }}</code></p><p class="muted">{{ Illuminate\Support\Number::fileSize($file->size, 2) }}</p><a href="{{ route('sftp.transfers.create') }}">{{ __('Choose a different backup') }}</a></div></section>
    @if(! $available)
        <div class="notice error" role="status">{{ __('This transfer is currently unavailable. Ask an administrator to check the file and transfer service.') }}</div>
    @elseif($destinations->isEmpty())
        <div class="notice" role="status"><h2>{{ __('No destinations available') }}</h2><p>{{ __('Ask an administrator to enable an approved destination before sending this backup.') }}</p></div>
    @else
    <form method="post" action="{{ route('sftp.transfers.store') }}" data-transfer-form novalidate>
        @csrf
        <input type="hidden" name="_request_id" value="{{ $requestId }}"><input type="hidden" name="file_id" value="{{ $file->id }}">
        <section class="panel form-section"><div class="section-heading"><h2>{{ __('Destination') }}</h2><p class="muted">{{ __('Only approved, enabled destinations are listed.') }}</p></div><div class="fields">@include('sftp::field', ['name' => 'destination_id', 'label' => __('Send to'), 'type' => 'select', 'options' => $destinations, 'required' => true, 'help' => __('Choose the database instance that should receive this backup.')])</div></section>
        <p class="notice">{{ __('Sending copies the backup only; it does not restore the database. Existing destination files are never replaced.') }}</p>
        <div class="actions form-actions"><a class="button secondary" href="{{ route('sftp.transfers.create') }}">{{ __('Back to backups') }}</a><button class="button primary" type="submit" data-saving="{{ __('Recording…') }}">{{ __('Send backup') }}</button></div>
    </form>
    @endif
@endsection
