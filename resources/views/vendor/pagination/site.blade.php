{{--
    Public site pager (template .pager), used with $paginator->links('vendor.pagination.site').
    Real links (crawlable, open in a new tab) that Livewire intercepts with wire:click.
--}}
@if ($paginator->hasPages())
    @php
        $linkClass = 'grid h-[42px] min-w-[42px] place-items-center rounded-btn border border-line px-2 font-semibold transition-colors hover:border-brand hover:text-brand-600';
        $scroll = "document.getElementById('catalog-results')?.scrollIntoView({ behavior: 'smooth' })";
    @endphp
    <nav class="mt-9 flex flex-wrap justify-center gap-1.5" aria-label="{{ __('site.pagination.label') }}">
        @unless ($paginator->onFirstPage())
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" wire:click.prevent="previousPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scroll }}" class="{{ $linkClass }}">
                <span aria-hidden="true">←</span><span class="sr-only">{{ __('site.pagination.previous') }}</span>
            </a>
        @endunless

        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="grid h-[42px] min-w-[42px] place-items-center text-ink-3" aria-hidden="true">{{ $element }}</span>
            @endif

            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span aria-current="page" class="grid h-[42px] min-w-[42px] place-items-center rounded-btn border border-brand bg-brand-600 px-2 font-semibold text-white">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}" wire:key="site-page-{{ $page }}" wire:click.prevent="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')" x-on:click="{{ $scroll }}" class="{{ $linkClass }}" aria-label="{{ __('site.pagination.page', ['page' => $page]) }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" wire:click.prevent="nextPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scroll }}" class="{{ $linkClass }}">
                <span aria-hidden="true">→</span><span class="sr-only">{{ __('site.pagination.next') }}</span>
            </a>
        @endif
    </nav>
@endif
