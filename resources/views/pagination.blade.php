@if($paginator->total() > 0)
<nav class="pagination" aria-label="{{ $label }}">
    <span>{{ __(':first–:last of :total', ['first' => $paginator->firstItem(), 'last' => $paginator->lastItem(), 'total' => $paginator->total()]) }}</span>
    @if($paginator->previousPageUrl())<a class="button secondary" href="{{ $paginator->previousPageUrl() }}">{{ __('Previous') }}</a>@endif
    <span>{{ __('Page :current of :last', ['current' => $paginator->currentPage(), 'last' => $paginator->lastPage()]) }}</span>
    @if($paginator->nextPageUrl())<a class="button secondary" href="{{ $paginator->nextPageUrl() }}">{{ __('Next') }}</a>@endif
</nav>
@endif
