{{-- Previous / next links of a paginator ($paginator). --}}
@if ($paginator->hasPages())
    <nav class="flex items-center justify-between gap-3 text-sm" aria-label="{{ __('tma.pager.label') }}" data-pager>
        @if ($paginator->onFirstPage())
            <span></span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" class="tma-btn-outline w-auto" rel="prev">{{ __('tma.pager.previous') }}</a>
        @endif
        <span class="text-tg-hint">{{ __('tma.pager.page', ['page' => $paginator->currentPage(), 'pages' => $paginator->lastPage()]) }}</span>
        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" class="tma-btn-outline w-auto" rel="next">{{ __('tma.pager.next') }}</a>
        @else
            <span></span>
        @endif
    </nav>
@endif
