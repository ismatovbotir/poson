{{--
    Search results (App\Livewire\Site\Search). Same visual language as the catalog
    (catalog.html): breadcrumbs, page head, the product grid and the site pager.
    Plan 43: category chips and sort (#39), no page field below 900px (#41),
    direct contacts and popular categories in the empty result (#42).
--}}
@php
    $number = fn (int|string $value): string => \App\Support\NumberFormat::format($value);
    $chipClass = 'inline-flex h-9 items-center gap-1.5 rounded-full border px-3.5 text-sm font-medium transition-colors';
    $chipOn = 'border-brand-600 bg-brand-600 text-white';
    $chipOff = 'border-line bg-white text-ink-2 hover:border-brand hover:text-brand-600';
@endphp

<div class="site-container">
    <x-site.breadcrumbs :items="$breadcrumbs" />

    <div class="mb-6">
        <h1 class="text-[26px] leading-[1.15] font-bold tracking-[-0.025em] sm:text-[34px]">
            {{ $term !== '' ? __('site.search.results_for', ['query' => $term]) : __('site.search.title') }}
        </h1>
        @if ($products !== null)
            <p class="text-ink-2" data-total="{{ $products->total() }}">{{ trans_choice('site.catalog.found', $products->total(), ['count' => $number($products->total())]) }}</p>
        @endif
    </div>

    {{-- Plan 43 #41: below 900px the header search row is the only search field. --}}
    <form role="search" wire:submit.prevent class="relative mb-8 max-w-2xl max-md:hidden" action="{{ route('site.search') }}" method="get" data-search-page-form>
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
            class="h-[46px] w-full rounded-btn border-[1.5px] border-line bg-bg-soft pr-4 pl-11 text-ink transition-colors placeholder:text-ink-2 focus:border-brand-600 focus:bg-white"
        >
    </form>

    {{-- Plan 43 #39 (UQ2-9): category chips of the results and the catalog sort. --}}
    @if ($products !== null && $products->total() > 0)
        <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
            @if ($chips !== [])
                <ul class="flex flex-wrap gap-2" aria-label="{{ __('site.search.categories') }}" data-search-chips>
                    <li wire:key="search-chip-all">
                        <button type="button" wire:click="selectCategory" aria-pressed="{{ $category === null ? 'true' : 'false' }}" class="{{ $chipClass }} {{ $category === null ? $chipOn : $chipOff }}">
                            {{ __('site.search.all_categories') }}
                            <span class="text-xs opacity-80">{{ $number($chipsTotal) }}</span>
                        </button>
                    </li>
                    @foreach ($chips as $chip)
                        <li wire:key="search-chip-{{ $chip['id'] }}">
                            <button
                                type="button"
                                wire:click="selectCategory({{ (int) $chip['id'] }})"
                                aria-pressed="{{ $category === $chip['id'] ? 'true' : 'false' }}"
                                class="{{ $chipClass }} {{ $category === $chip['id'] ? $chipOn : $chipOff }}"
                                data-search-chip="{{ $chip['id'] }}"
                            >
                                {{ $chip['label'] }}
                                <span class="text-xs opacity-80">{{ $number($chip['count']) }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif

            <div class="ml-auto flex items-center gap-2">
                <label for="search-sort" class="text-sm text-ink-2 max-sm:sr-only">{{ __('site.catalog.sort.label') }}</label>
                <select id="search-sort" wire:model.live="sort" class="h-[42px] rounded-btn border-[1.5px] border-line bg-white px-3 text-sm focus:border-brand-600">
                    @foreach ($sorts as $value)
                        <option value="{{ $value }}">{{ __('site.catalog.sort.'.$value) }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    @endif

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

                {{-- Plan 43 #42 (UQ2-11): direct contact, escaped; tel: from digits, Telegram https://t.me/ only. --}}
                @if ($term !== '' && ($phoneHref !== null || $telegramHref !== null))
                    <div class="mt-5 flex flex-wrap justify-center gap-x-5 gap-y-2 text-sm font-semibold" data-search-contacts>
                        @if ($phoneHref !== null)
                            <a href="{{ $phoneHref }}" class="inline-flex items-center gap-2 text-ink hover:text-brand-600" data-search-phone>
                                <x-site.icon name="phone" class="text-brand-600" />
                                <span class="sr-only">{{ __('site.topbar.call_us') }}:</span>
                                {{ $phoneDisplay }}
                            </a>
                        @endif
                        @if ($telegramHref !== null)
                            <a href="{{ $telegramHref }}" data-poson-goal="click_telegram" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 text-ink hover:text-brand-600" data-search-telegram>
                                <x-site.icon name="telegram" class="text-brand-600" />
                                {{ __('site.search.write_telegram') }}
                            </a>
                        @endif
                    </div>
                @endif

                @if ($popularCategories !== [])
                    <nav class="mt-6 w-full max-w-2xl" aria-labelledby="search-popular-title" data-search-popular>
                        <h3 id="search-popular-title" class="mb-3 text-sm font-bold text-ink-2">{{ __('site.search.popular_categories') }}</h3>
                        <ul class="flex flex-wrap justify-center gap-2">
                            @foreach ($popularCategories as $tile)
                                <li wire:key="search-popular-{{ $loop->index }}">
                                    <a href="{{ $tile->url }}" class="inline-flex h-9 items-center gap-2 rounded-full border border-line bg-white px-3.5 text-sm font-medium text-ink-2 transition-colors hover:border-brand hover:text-brand-600">
                                        <x-site.icon :name="$tile->icon" size="size-[18px]" class="text-brand-600" />
                                        {{ $tile->name }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </nav>
                @endif

                <div class="mt-5 flex flex-wrap justify-center gap-3">
                    <a href="{{ route('site.catalog.index') }}" class="inline-flex h-[46px] items-center justify-center rounded-btn border-[1.5px] border-line bg-white px-5 font-semibold transition-colors hover:border-brand hover:text-brand-600">{{ __('site.home.categories.all') }}</a>
                    <a href="{{ \App\Support\Site\Navigation::quote() }}" data-lead-modal="callback" class="inline-flex h-[46px] items-center justify-center rounded-btn bg-brand-600 px-5 font-semibold text-white hover:bg-brand-700">{{ __('site.header.request_quote') }}</a>
                </div>
            </div>
        @endif
    </section>
</div>
