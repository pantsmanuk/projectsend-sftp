@extends('sftp::layout')
@section('title', __('Delete destination'))
@section('breadcrumbs')<a href="{{ route('sftp.destinations.index') }}">{{ __('SFTP destinations') }}</a><span aria-hidden="true">/</span><span aria-current="page">{{ __('Delete destination') }}</span>@endsection
@section('content')
    <div class="page-heading"><div><h1>{{ __('Delete destination') }}</h1><p class="muted">{{ __('Review the destination before permanently removing its configuration.') }}</p></div></div>
    @if($errors->any())<div class="notice error" role="alert"><h2>{{ __('Destination not deleted') }}</h2>@foreach($errors->all() as $message)<p>{{ $message }}</p>@endforeach</div>@endif
    <section class="panel form-section danger-zone" aria-labelledby="destination-heading">
        <div class="section-heading"><h2 id="destination-heading">{{ $destination->name }}</h2><p class="muted"><code>{{ $destination->username.'@'.$destination->host.':'.$destination->port }}</code></p><p class="muted"><code>{{ $destination->directory }}</code></p></div>
        <div><p>{{ __('This permanently removes the destination and its saved SSH private key and passphrase from ProjectSend. You cannot undo this action.') }}</p><p class="muted">{{ __('The remote server, its authorised keys, and any files already on it are unaffected.') }}</p></div>
    </section>
    <form method="post" action="{{ route('sftp.destinations.destroy', $destination) }}" class="actions" novalidate data-delete-form>
        @csrf
        @method('DELETE')
        <input type="hidden" name="_revision" value="{{ $destination->revision() }}">
        <input type="hidden" name="confirm_delete" value="1">
        <a class="button secondary" href="{{ route('sftp.destinations.edit', $destination) }}" autofocus>{{ __('Keep destination') }}</a>
        <button class="button danger" type="submit" data-saving="{{ __('Deleting…') }}">{{ __('Delete destination') }}</button>
    </form>
@endsection
