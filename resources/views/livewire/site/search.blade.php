{{--
    Search results (App\Livewire\Site\Search). Same visual language as the catalog
    (catalog.html): breadcrumbs, page head, the product grid and the site pager.
--}}
<div class="site-container">
    <x-site.breadcrumbs :items="$breadcrumbs" />

    <div class="mb-6">
        <h1 class="text-[26px] leading-[1.15] font-bold tracking-[-0.025em] sm:text-[34px]">
            {{ $term !== '' ? __('site.search.results_for', ['query' => $term]) : __('site.search.title') }}
        </h1>
        @if ($products !== null)
            <p class="text-ink-2" data-total="{{ $products->total() }}">{{ trans_choice('site.catalog.found', $products->total(), ['count' => \App\Support\NumberFormat::format($products->total())]) }}</p>
        @endif
    </div>

    <form role="search" wire:submit.prevent class="relative mb-8 max-w-2xl" action="{{ route('site.search') }}" method="get">
        <label for="search-page-q" class="sr-only">{{ __('site.header.search_label') }}</label>
        <x-site.icon name="search" class="pointer-events-none absolute top-[13px] left-3.5 text-ink-2" />
        <input
            id="search-page-q"
            type="search"
            name="q"
            wire:model.live.debounce.400ms="q"
            maxlength="{{ \App\Livewire\Site\Search::MAX_LENGTH }}"
            autocomplete="off"
            placeholder="{{ __('site.header.search_placeholder') }}"
            class="h-[46px] w-full rounded-btn border-[1.5px] border-line bg-bg-soft pr-4 pl-11 text-ink transition-colors placeholder:text-ink-2 focus:border-brand-600 focus:bg-white focus:outline-none"
        >
    </form>

    <section id="catalog-results" class="scroll-mt-36" wire:loading.class="opacity-60" aria-label="{{ __('site.catalog.results') }}">
        @if ($cards !== [])
            <div class="grid grid-cols-2 gap-3 sm:gap-5 md:grid-cols-3 lg:grid-cols-4">
                @foreach ($cards as $card)
                    <x-site.product-card :product="$card" wire:key="search-{{ $card->slug }}" />
                @endforeach
            </div>

            {{ $products->links('vendor.pagination.site') }}
        @else
            <div class="grid place-items-center rounded-box-lg border border-dashed border-line px-6 py-16 text-center" data-empty>
                <span class="mb-4 grid size-14 place-items-center rounded-2xl bg-bg-tint text-brand-600"><x-site.icon name="search" size="size-7" /></span>
                @if ($term === '')
                    <p class="max-w-md text-ink-2">{{ __('site.search.empty_query') }}</p>
                @else
                    <h2 class="mb-1.5 text-lg font-bold">{{ __('site.search.nothing', ['query' => $term]) }}</h2>
                    <p class="max-w-md text-ink-2">{{ __('site.search.hint') }}</p>
                @endif
                <div class="mt-5 flex flex-wrap justify-center gap-3">
                    <a href="{{ route('site.catalog.index') }}" class="inline-flex h-[46px] items-center justify-center rounded-btn border-[1.5px] border-line bg-white px-5 font-semibold transition-colors hover:border-brand hover:text-brand-600">{{ __('site.home.categories.all') }}</a>
                    <a href="{{ \App\Support\Site\Navigation::quote() }}" class="inline-flex h-[46px] items-center justify-center rounded-btn bg-brand-600 px-5 font-semibold text-white hover:bg-brand-700">{{ __('site.header.request_quote') }}</a>
                </div>
            </div>
        @endif
    </section>
</div>
