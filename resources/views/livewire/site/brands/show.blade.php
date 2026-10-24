{{--
    Brand page (App\Livewire\Site\Brands\Show), in the catalog's visual language:
    breadcrumbs, a head with the logo, name, description and the official website
    (non-software brands only, see Brand::publicWebsite()), the section filter, then
    the product cards (x-site.product-card) and the pager.
--}}
@php
    $proseClass = 'text-ink-2 [&_a]:font-medium [&_a]:text-brand-600 [&_a]:underline [&_li]:mb-2 [&_ol]:mb-3.5 [&_ol]:list-decimal [&_ol]:pl-5 [&_p]:mb-3.5 [&_p:last-child]:mb-0 [&_strong]:text-ink [&_ul]:mb-3.5 [&_ul]:list-disc [&_ul]:pl-5 [&_li]:marker:text-brand';
    $chipClass = 'inline-flex items-center gap-1.5 rounded-full border-[1.5px] px-3.5 py-1.5 text-sm font-semibold transition-colors';
@endphp

<div class="site-container">
    @push('head')
        @foreach ($jsonLd as $block)
            <script type="application/ld+json">{!! $block !!}</script>
        @endforeach
    @endpush

    <x-site.breadcrumbs :items="$breadcrumbs" />

    <header class="mb-8 flex flex-col gap-5 rounded-box-lg border border-line bg-white p-5 sm:flex-row sm:items-start sm:gap-7 sm:p-7">
        @if ($logo)
            <span class="grid h-24 w-full shrink-0 place-items-center rounded-box bg-bg-soft p-4 sm:w-48">
                <img src="{{ $logo }}" alt="{{ $brand->name }}" width="160" height="64" class="max-h-16 w-auto max-w-full object-contain" data-brand-logo>
            </span>
        @endif
        <div class="min-w-0 flex-1">
            <h1 class="text-[26px] leading-[1.15] font-bold tracking-[-0.025em] sm:text-[34px]">{{ $brand->name }}</h1>
            @if (trim(strip_tags($description)) !== '')
                <div class="mt-3 max-w-[760px] {{ $proseClass }}" data-brand-description>{!! $description !!}</div>
            @endif
            @if ($website)
                <a href="{{ $website }}" target="_blank" rel="noopener noreferrer" class="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold text-brand-600 hover:underline" data-brand-website>
                    {{ __('site.brands.show.website') }}: {{ $websiteHost }}
                    <x-site.icon name="arrow" size="size-4" />
                </a>
            @endif
        </div>
    </header>

    <section id="catalog-results" class="scroll-mt-36" aria-labelledby="brand-products-title">
        <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
            <h2 id="brand-products-title" class="text-xl font-bold sm:text-2xl">{{ __('site.brands.show.products') }}</h2>
            <p class="text-ink-2" data-total="{{ $products->total() }}">{{ trans_choice('site.catalog.found', $products->total(), ['count' => \App\Support\NumberFormat::format($products->total())]) }}</p>
        </div>

        @if ($sections !== [])
            <ul class="mb-6 flex flex-wrap gap-2" aria-label="{{ __('site.brands.show.categories') }}" data-brand-sections>
                <li>
                    <button
                        type="button"
                        wire:click="filterCategory('')"
                        aria-pressed="{{ $category === '' ? 'true' : 'false' }}"
                        @class([$chipClass, 'border-brand-600 bg-brand-50 text-brand-600' => $category === '', 'border-line bg-white text-ink hover:border-brand hover:text-brand-600' => $category !== ''])
                    >{{ __('site.brands.show.all_categories') }}</button>
                </li>
                @foreach ($sections as $section)
                    <li wire:key="section-{{ $section['slug'] }}">
                        <button
                            type="button"
                            wire:click="filterCategory(@js($section['slug']))"
                            aria-pressed="{{ $section['checked'] ? 'true' : 'false' }}"
                            data-brand-section="{{ $section['slug'] }}"
                            @class([$chipClass, 'border-brand-600 bg-brand-50 text-brand-600' => $section['checked'], 'border-line bg-white text-ink hover:border-brand hover:text-brand-600' => ! $section['checked']])
                        >
                            {{ $section['label'] }}
                            <span class="text-xs font-medium text-ink-3">{{ \App\Support\NumberFormat::format($section['count']) }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif

        <div wire:loading.class="opacity-60" class="transition-opacity">
            @if ($cards !== [])
                <div class="grid grid-cols-2 gap-3 sm:gap-5 lg:grid-cols-4">
                    @foreach ($cards as $card)
                        <x-site.product-card :product="$card" wire:key="product-{{ $card->slug }}" />
                    @endforeach
                </div>

                {{ $products->links('vendor.pagination.site') }}
            @else
                <p class="rounded-box-lg border border-dashed border-line bg-bg-soft p-8 text-center text-ink-2" data-empty>{{ __('site.brands.show.empty') }}</p>
            @endif
        </div>
    </section>
</div>
