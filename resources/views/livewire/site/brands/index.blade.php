{{--
    Brands list (App\Livewire\Site\Brands\Index), in the catalog's visual language:
    breadcrumbs, heading, a grid of brand tiles (logo or name, product count).
--}}
<div class="site-container">
    @push('head')
        @foreach ($jsonLd as $block)
            <script type="application/ld+json">{!! $block !!}</script>
        @endforeach
    @endpush

    <x-site.breadcrumbs :items="$breadcrumbs" />

    <header class="mb-7">
        <h1 class="text-[26px] leading-[1.15] font-bold tracking-[-0.025em] sm:text-[34px]">{{ __('site.brands.title') }}</h1>
        <p class="mt-2 text-ink-2">{{ __('site.brands.subtitle') }}</p>
    </header>

    @if ($brands === [])
        <p class="rounded-box-lg border border-dashed border-line bg-bg-soft p-8 text-center text-ink-2" data-brands-empty>{{ __('site.brands.empty') }}</p>
    @else
        <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-5 lg:grid-cols-4 xl:grid-cols-5">
            @foreach ($brands as $brand)
                <li wire:key="brand-{{ $brand['slug'] }}">
                    <a
                        href="{{ $brand['url'] }}"
                        class="group flex h-full flex-col items-center gap-3 rounded-box-lg border border-line bg-white p-4 text-center transition-colors hover:border-brand hover:shadow-card sm:p-5"
                        data-brand-card="{{ $brand['slug'] }}"
                    >
                        <span class="grid h-16 w-full place-items-center" aria-hidden="true">
                            @if ($brand['logo'])
                                <img src="{{ $brand['logo'] }}" alt="" width="160" height="64" loading="lazy" decoding="async" class="max-h-16 w-auto max-w-full object-contain">
                            @else
                                <span class="text-lg font-extrabold tracking-[0.04em] text-ink-2 uppercase">{{ $brand['name'] }}</span>
                            @endif
                        </span>
                        <span class="grid gap-0.5">
                            <span class="font-bold text-ink transition-colors group-hover:text-brand-600">{{ $brand['name'] }}</span>
                            <span class="text-sm text-ink-2" data-brand-count="{{ $brand['count'] }}">{{ trans_choice('site.brands.products_count', $brand['count'], ['count' => \App\Support\NumberFormat::format($brand['count'])]) }}</span>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
