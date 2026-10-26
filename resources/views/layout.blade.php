<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ in_array(request()->cookie('appearance'), ['light', 'dark']) ? request()->cookie('appearance') : 'system' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') · ProjectSend</title>
    <script>
        // Read the same appearance preference as the host before first paint.
        try {
            const appearance = localStorage.getItem('appearance');
            if (['light', 'dark', 'system'].includes(appearance)) document.documentElement.dataset.theme = appearance;
        } catch { /* System/cookie appearance still works with storage disabled. */ }
    </script>
    <style>@include('sftp::styles')</style>
</head>
<body>
    <a class="skip-link" href="#main">{{ __('Skip to content') }}</a>
    <header class="topbar"><a class="brand" href="{{ url('/dashboard') }}">ProjectSend</a><span class="module-name">@yield('module-name', __('SFTP destinations'))</span><a class="back-link" href="{{ url('/dashboard') }}"><span aria-hidden="true">←</span> {{ __('Back to ProjectSend') }}</a></header>
    <main id="main" class="page" tabindex="-1">
        <nav class="breadcrumbs" aria-label="{{ __('Breadcrumb') }}"><a href="{{ url('/dashboard') }}">{{ __('ProjectSend') }}</a><span aria-hidden="true">/</span>@yield('breadcrumbs')</nav>
        <nav class="module-nav" aria-label="{{ __('SFTP navigation') }}">
            <a href="{{ route('sftp.transfers.index') }}" @if(request()->routeIs('sftp.transfers.index', 'sftp.transfers.show')) aria-current="page" @endif>{{ __('My transfers') }}</a>
            <a href="{{ route('sftp.transfers.create') }}" @if(request()->routeIs('sftp.transfers.create', 'sftp.transfers.review')) aria-current="page" @endif>{{ __('Send backup') }}</a>
            @if(request()->routeIs('sftp.destinations.*'))
                @can('sftp.manage-destinations')<a href="{{ route('sftp.destinations.index') }}" aria-current="page">{{ __('Destinations') }}</a>@endcan
            @endif
        </nav>
        @if(session('success'))<div class="notice success" role="status">{{ session('success') }}</div>@endif
        @yield('content')
    </main>
    <dialog id="leave-dialog" aria-labelledby="leave-title" aria-describedby="leave-description">
        <h2 id="leave-title">{{ __('Discard unsaved changes?') }}</h2><p id="leave-description">{{ __('Your changes have not been saved.') }} @yield('leave-note', __('Any newly selected key will need to be selected again.'))</p>
        <div class="actions"><button type="button" class="button primary" data-stay autofocus>{{ __('Keep editing') }}</button><a class="button secondary" data-leave href="#">{{ __('Discard changes') }}</a></div>
    </dialog>
    <script>@include('sftp::scripts')</script>
</body>
</html>
