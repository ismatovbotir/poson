{{--
    Catalog listing, ported from .claude/html-templates/site/catalog.html.
    Changes vs. the template (brand rule, plan 03): no favorites, no sale tags or old
    prices, Retail price only; the sort labels are neutral ("Price ↑ / ↓"). Filters
    apply immediately (Livewire); below 900px the filter column is a sheet.
--}}
@php
    $checkClass = 'size-[18px] shrink-0 cursor-pointer rounded-[5px] accent-brand-600';
    $fieldClass = 'h-10 w-full min-w-0 rounded-btn border-[1.5px] border-line bg-white px-2.5 text-sm text-ink placeholder:text-ink-3 focus:border-brand-600 focus:outline-none';
    $number = fn (int|string|null $value): string => $value === null ? '' : \App\Support\NumberFormat::format($value);
    $total = $number($products->total());
@endphp

<div
    class="site-container"
    x-data="{ filtersOpen: false }"
    x-on:keydown.escape.window="filtersOpen = false"
    x-on:resize.window.debounce.150ms="if (window.innerWidth >= 900) filtersOpen = false"
>
    @push('head')
        <script type="application/ld+json">{!! $breadcrumbsJson !!}</script>
    @endpush

    <x-site.breadcrumbs :items="$breadcrumbs" />

    <div class="mb-6 flex flex-wrap items-end justify-between gap-5">
        <div>
            <h1 class="text-[26px] leading-[1.15] font-bold tracking-[-0.025em] sm:text-[34px]">{{ $heading }}</h1>
            <p class="text-ink-2" data-total="{{ $products->total() }}">{{ trans_choice('site.catalog.found', $products->total(), ['count' => $total]) }}</p>
        </div>
    </div>

    @if ($subcategories !== [])
        <nav class="mb-6" aria-label="{{ __('site.catalog.subcategories') }}">
            <ul class="flex flex-wrap gap-2">
                @foreach ($subcategories as $subcategory)
                    <li wire:key="subcategory-{{ $loop->index }}">
                        <a href="{{ $subcategory['url'] }}" class="inline-flex h-9 items-center rounded-full border border-line bg-white px-4 text-sm font-medium text-ink-2 transition-colors hover:border-brand hover:text-brand-600">{{ $subcategory['label'] }}</a>
                    </li>
                @endforeach
            </ul>
        </nav>
    @endif

    <div class="grid items-start gap-8 md:grid-cols-[276px_1fr]">
        {{-- Filters: a column from 900px, a sheet below. --}}
        <aside
            id="catalog-filters"
            class="max-md:fixed max-md:inset-0 max-md:z-50 max-md:hidden md:sticky md:top-36"
            x-bind:class="{ 'max-md:hidden': ! filtersOpen }"
            aria-labelledby="catalog-filters-title"
        >
            <div class="absolute inset-0 bg-ink/50 md:hidden" aria-hidden="true" x-on:click="filtersOpen = false"></div>

            <div
                class="relative flex h-full flex-col bg-white max-md:ml-auto max-md:w-[min(24rem,92vw)] max-md:overflow-y-auto max-md:shadow-card md:rounded-box-lg md:border md:border-line"
                x-trap.noscroll="filtersOpen"
            >
                <div class="flex items-center justify-between border-b border-line px-5 py-3 md:hidden">
                    <h2 id="catalog-filters-title" class="text-lg font-bold">{{ __('site.catalog.filters') }}</h2>
                    <button type="button" class="-mr-2 grid size-11 place-items-center rounded-btn hover:bg-bg-soft" x-on:click="filtersOpen = false">
                        <x-site.icon name="close" size="size-6" />
                        <span class="sr-only">{{ __('site.catalog.close_filters') }}</span>
                    </button>
                </div>

                <div class="divide-y divide-line px-5 md:py-1.5">
                    {{-- Price, whole soʻm --}}
                    @if ($facets['price']['min'] !== null)
                        <fieldset class="py-[18px]" wire:key="filter-price">
                            <legend class="mb-3 text-sm font-bold">{{ __('site.catalog.price', ['currency' => __('site.currency')]) }}</legend>
                            <div class="flex items-center gap-2">
                                <label class="sr-only" for="filter-price-min">{{ __('site.catalog.price_min') }}</label>
                                <input id="filter-price-min" type="text" inputmode="numeric" autocomplete="off" maxlength="15" wire:model.live.debounce.700ms="priceMin" placeholder="{{ $number($facets['price']['min']) }}" class="{{ $fieldClass }}">
                                <span class="text-ink-3" aria-hidden="true">—</span>
                                <label class="sr-only" for="filter-price-max">{{ __('site.catalog.price_max') }}</label>
                                <input id="filter-price-max" type="text" inputmode="numeric" autocomplete="off" maxlength="15" wire:model.live.debounce.700ms="priceMax" placeholder="{{ $number($facets['price']['max']) }}" class="{{ $fieldClass }}">
                            </div>
                        </fieldset>
                    @endif

                    {{-- Brand --}}
                    @if ($facets['brands'] !== [])
                        <fieldset class="py-[18px]" wire:key="filter-brand">
                            <legend class="mb-3 text-sm font-bold">{{ __('site.catalog.brand') }}</legend>
                            @foreach ($facets['brands'] as $item)
                                <label class="flex cursor-pointer items-center gap-2.5 py-[5px] text-sm text-ink-2" wire:key="filter-brand-{{ $item['slug'] }}">
                                    <input type="checkbox" class="{{ $checkClass }}" @checked($item['checked']) wire:click="toggleBrand(@js($item['slug']))">
                                    <span>{{ $item['name'] }}</span>
                                    <em class="ml-auto text-xs text-ink-3 not-italic">{{ $item['count'] }}</em>
                                </label>
                            @endforeach
                        </fieldset>
                    @endif

                    {{-- Attributes of the category --}}
                    @foreach ($facets['attributes'] as $facet)
                        <fieldset class="py-[18px]" wire:key="filter-attr-{{ $facet['code'] }}" data-filter="{{ $facet['code'] }}">
                            <legend class="mb-3 text-sm font-bold">{{ $facet['name'] }}@if ($facet['unit'] && ! $facet['type']->hasOptions()), {{ $facet['unit'] }}@endif</legend>

                            @if ($facet['type']->hasOptions())
                                @foreach ($facet['options'] as $option)
                                    <label class="flex cursor-pointer items-center gap-2.5 py-[5px] text-sm text-ink-2" wire:key="filter-{{ $facet['code'] }}-{{ $option['id'] }}">
                                        <input type="checkbox" class="{{ $checkClass }}" @checked($option['checked']) wire:click="toggleValue(@js($facet['code']), '{{ $option['id'] }}')">
                                        <span>{{ $option['label'] }}</span>
                                        <em class="ml-auto text-xs text-ink-3 not-italic">{{ $option['count'] }}</em>
                                    </label>
                                @endforeach
                            @elseif ($facet['type'] === \App\Enums\AttributeType::Boolean)
                                <label class="flex cursor-pointer items-center gap-2.5 py-[5px] text-sm text-ink-2">
                                    <input type="checkbox" class="{{ $checkClass }}" @checked($facet['checked']) wire:click="toggleValue(@js($facet['code']), '1')">
                                    <span>{{ __('site.product.yes') }}</span>
                                    <em class="ml-auto text-xs text-ink-3 not-italic">{{ $facet['count'] }}</em>
                                </label>
                            @else
                                <div class="flex items-center gap-2">
                                    <label class="sr-only" for="filter-{{ $facet['code'] }}-min">{{ $facet['name'] }}: {{ __('site.catalog.min') }}</label>
                                    <input id="filter-{{ $facet['code'] }}-min" type="text" inputmode="decimal" autocomplete="off" maxlength="16" wire:model.live.debounce.700ms="f.{{ $facet['code'] }}.min" placeholder="{{ $number($facet['min']) }}" class="{{ $fieldClass }}">
                                    <span class="text-ink-3" aria-hidden="true">—</span>
                                    <label class="sr-only" for="filter-{{ $facet['code'] }}-max">{{ $facet['name'] }}: {{ __('site.catalog.max') }}</label>
                                    <input id="filter-{{ $facet['code'] }}-max" type="text" inputmode="decimal" autocomplete="off" maxlength="16" wire:model.live.debounce.700ms="f.{{ $facet['code'] }}.max" placeholder="{{ $number($facet['max']) }}" class="{{ $fieldClass }}">
                                </div>
                            @endif
                        </fieldset>
                    @endforeach

                    {{-- Availability --}}
                    @if ($facets['stock'] !== [])
                        <fieldset class="py-[18px]" wire:key="filter-stock">
                            <legend class="mb-3 text-sm font-bold">{{ __('site.catalog.stock') }}</legend>
                            @foreach ($facets['stock'] as $item)
                                <label class="flex cursor-pointer items-center gap-2.5 py-[5px] text-sm text-ink-2" wire:key="filter-stock-{{ $item['value'] }}">
                                    <input type="checkbox" class="{{ $checkClass }}" @checked($item['checked']) wire:click="toggleStock(@js($item['value']))">
                                    <span>{{ $item['label'] }}</span>
                                    <em class="ml-auto text-xs text-ink-3 not-italic">{{ $item['count'] }}</em>
                                </label>
                            @endforeach
                        </fieldset>
                    @endif
                </div>

                <div class="mt-auto grid gap-2 px-5 pt-3.5 pb-4 max-md:sticky max-md:bottom-0 max-md:border-t max-md:border-line max-md:bg-white">
                    <button type="button" class="inline-flex h-[46px] items-center justify-center rounded-btn bg-brand-600 px-5 font-semibold text-white hover:bg-brand-700 md:hidden" x-on:click="filtersOpen = false">
                        {{ trans_choice('site.catalog.show_results', $products->total(), ['count' => $total]) }}
                    </button>
                    @if ($activeCount > 0)
                        <button type="button" wire:click="resetFilters" class="inline-flex h-[46px] items-center justify-center rounded-btn border-[1.5px] border-line bg-white px-5 font-semibold text-ink transition-colors hover:border-brand hover:text-brand-600">
                            {{ __('site.catalog.reset') }}
                        </button>
                    @endif
                </div>
            </div>
        </aside>

        <section id="catalog-results" class="min-w-0 scroll-mt-36" aria-labelledby="catalog-results-title">
            <h2 id="catalog-results-title" class="sr-only">{{ __('site.catalog.results') }}</h2>

            <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                <button
                    type="button"
                    class="inline-flex h-[42px] items-center gap-2 rounded-btn border-[1.5px] border-line bg-white px-4 font-semibold transition-colors hover:border-brand hover:text-brand-600 md:hidden"
                    aria-controls="catalog-filters"
                    x-bind:aria-expanded="filtersOpen.toString()"
                    x-on:click="filtersOpen = true"
                >
                    <x-site.icon name="grid" />
                    {{ __('site.catalog.filters') }}
                    @if ($activeCount > 0)
                        <span class="grid h-5 min-w-5 place-items-center rounded-full bg-brand-600 px-1 text-xs text-white">{{ $activeCount }}</span>
                    @endif
                </button>

                <div class="ml-auto flex items-center gap-2">
                    <label for="catalog-sort" class="text-sm text-ink-2 max-sm:sr-only">{{ __('site.catalog.sort.label') }}</label>
                    <select id="catalog-sort" wire:model.live="sort" class="h-[42px] rounded-btn border-[1.5px] border-line bg-white px-3 text-sm focus:border-brand-600 focus:outline-none">
                        @foreach ($sorts as $value)
                            <option value="{{ $value }}">{{ __('site.catalog.sort.'.$value) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            @if ($chips !== [])
                <ul class="mb-5 flex flex-wrap gap-2" aria-label="{{ __('site.catalog.active_filters') }}">
                    @foreach ($chips as $chip)
                        <li wire:key="chip-{{ implode('-', $chip['remove']) }}">
                            <button
                                type="button"
                                wire:click="removeFilter(@js($chip['remove'][0]), @js($chip['remove'][1]), @js($chip['remove'][2]))"
                                class="inline-flex items-center gap-2 rounded-full bg-brand-50 px-3 py-1.5 text-[13px] font-semibold text-brand-600 transition-colors hover:bg-brand-100"
                                data-chip
                            >
                                {{ $chip['label'] }}
                                <x-site.icon name="close" size="size-3.5" />
                                <span class="sr-only">{{ __('site.catalog.remove_filter') }}</span>
                            </button>
                        </li>
                    @endforeach
                    <li>
                        <button type="button" wire:click="resetFilters" class="inline-flex items-center px-2 py-1.5 text-[13px] font-semibold text-ink-2 underline hover:text-brand-600">{{ __('site.catalog.reset') }}</button>
                    </li>
                </ul>
            @endif

            <div wire:loading.class="opacity-60" class="transition-opacity">
                @if ($cards !== [])
                    <div class="grid grid-cols-2 gap-3 sm:gap-5 lg:grid-cols-3">
                        @foreach ($cards as $card)
                            <x-site.product-card :product="$card" wire:key="product-{{ $card->slug }}" />
                        @endforeach
                    </div>

                    {{ $products->links('vendor.pagination.site') }}
                @else
                    <div class="grid place-items-center rounded-box-lg border border-dashed border-line px-6 py-16 text-center" data-empty>
                        <span class="mb-4 grid size-14 place-items-center rounded-2xl bg-bg-tint text-brand-600"><x-site.icon name="search" size="size-7" /></span>
                        <h3 class="mb-1.5 text-lg font-bold">{{ __('site.catalog.empty_title') }}</h3>
                        <p class="max-w-md text-ink-2">{{ $activeCount > 0 ? __('site.catalog.empty_filtered') : __('site.catalog.empty_text') }}</p>
                        <div class="mt-5 flex flex-wrap justify-center gap-3">
                            @if ($activeCount > 0)
                                <button type="button" wire:click="resetFilters" class="inline-flex h-[46px] items-center justify-center rounded-btn bg-brand-600 px-5 font-semibold text-white hover:bg-brand-700">{{ __('site.catalog.reset') }}</button>
                            @endif
                            <a href="{{ \App\Support\Site\Navigation::quote() }}" class="inline-flex h-[46px] items-center justify-center rounded-btn border-[1.5px] border-line bg-white px-5 font-semibold transition-colors hover:border-brand hover:text-brand-600">{{ __('site.header.request_quote') }}</a>
                        </div>
                    </div>
                @endif
            </div>
        </section>
    </div>
</div>
