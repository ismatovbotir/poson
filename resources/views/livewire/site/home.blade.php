{{--
    Home page, ported from .claude/html-templates/site/index.html.
    Categories, popular (featured) products and brands come from the catalog
    (App\Support\Site\HomeContent) and are hidden while empty.
    Copy changes vs. the template (brand rule): B2B block sells service, not price;
    no "−%" tags or old prices on product cards.
--}}
<div>
    @push('head')
        <script type="application/ld+json">{!! $organization !!}</script>
    @endpush

    {{-- Hero --}}
    <section class="overflow-hidden bg-linear-to-b from-bg-tint to-white pt-8 pb-12 md:pt-14 md:pb-[72px]" aria-labelledby="hero-title">
        <div class="site-container grid items-center gap-10 md:grid-cols-[1.1fr_0.9fr] md:gap-12">
            <div>
                <p class="mb-5 inline-flex items-center gap-2 rounded-full border border-line bg-white px-3.5 py-1.5 text-[13px] font-semibold text-brand-600">
                    <i class="size-2 rounded-full bg-ok" aria-hidden="true"></i>
                    {{ __('site.home.hero.eyebrow') }}
                </p>
                <h1 id="hero-title" class="text-[32px] leading-[1.08] font-extrabold tracking-[-0.035em] sm:text-4xl md:text-[44px] lg:text-[54px] lg:leading-[1.05]">
                    {{ __('site.home.hero.title_before') }}<span class="text-brand-600">{{ __('site.home.hero.title_highlight') }}</span>{{ __('site.home.hero.title_after') }}
                </h1>
                <p class="mt-5 mb-8 max-w-[520px] text-lg text-ink-2">{{ __('site.home.hero.text') }}</p>

                <div class="flex flex-wrap gap-3">
                    <a
                        href="{{ \App\Support\Site\Navigation::catalog() }}"
                        class="inline-flex h-[54px] items-center justify-center gap-2 rounded-btn bg-brand-600 px-[30px] text-base font-semibold text-white transition-colors hover:bg-brand-700"
                    >
                        {{ __('site.home.hero.cta_catalog') }}
                        <x-site.icon name="arrow" />
                    </a>
                    <a
                        href="#lead"
                        class="inline-flex h-[54px] items-center justify-center rounded-btn border-[1.5px] border-line bg-white px-[30px] text-base font-semibold text-ink transition-colors hover:border-brand-600 hover:text-brand-600"
                    >
                        {{ __('site.home.hero.cta_quote') }}
                    </a>
                </div>

                @if ($heroStats !== [])
                    <dl class="mt-11 flex flex-wrap gap-x-10 gap-y-5">
                        @foreach ($heroStats as $stat)
                            <div class="flex flex-col-reverse" wire:key="hero-stat-{{ $loop->index }}">
                                <dt class="text-[13px] text-ink-2">{{ $stat['label'] }}</dt>
                                <dd class="text-2xl font-bold tracking-[-0.02em] sm:text-[30px] sm:leading-tight">{{ $stat['value'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
            </div>

            <div class="relative mx-auto aspect-square w-full max-w-[380px] md:mr-0 md:ml-auto md:max-w-[520px]" aria-hidden="true">
                <div class="absolute inset-[6%_8%_14%_0] grid place-items-center rounded-box-lg border border-line bg-linear-135 from-white to-brand-50 shadow-card">
                    <svg viewBox="0 0 300 240" class="w-[78%]">
                        <use href="#p-barcode" x="150" y="10" width="140" height="140" />
                        <use href="#p-scanner" x="10" y="60" width="180" height="180" />
                        <path d="M180 100h70" stroke="#ef4444" stroke-width="3" stroke-dasharray="4 5" stroke-linecap="round" />
                    </svg>
                </div>
                <div class="absolute top-0 right-0 flex items-center gap-3 rounded-box-lg border border-line bg-white px-[18px] py-3.5 shadow-card">
                    <span class="grid size-10 place-items-center rounded-btn bg-brand-50 text-brand-600"><x-site.icon name="shield" /></span>
                    <span>
                        <strong class="block text-sm">{{ __('site.home.hero.badge_warranty_title') }}</strong>
                        <small class="block text-xs text-ink-2">{{ __('site.home.hero.badge_warranty_text') }}</small>
                    </span>
                </div>
                <div class="absolute bottom-0 left-[6%] flex items-center gap-3 rounded-box-lg border border-line bg-white px-[18px] py-3.5 shadow-card">
                    <span class="grid size-10 place-items-center rounded-btn bg-brand-50 text-brand-600"><x-site.icon name="truck" /></span>
                    <span>
                        <strong class="block text-sm">{{ __('site.home.hero.badge_delivery_title') }}</strong>
                        <small class="block text-xs text-ink-2">{{ __('site.home.hero.badge_delivery_text') }}</small>
                    </span>
                </div>
            </div>
        </div>
    </section>

    {{-- Categories --}}
    @if ($categories !== [])
        <section id="categories" class="scroll-mt-28 pt-6 pb-12 md:pb-[72px]" aria-labelledby="categories-title">
            <div class="site-container">
                <x-site.section-head
                    id="categories-title"
                    :title="__('site.home.categories.title')"
                    :subtitle="__('site.home.categories.subtitle')"
                    :link="\App\Support\Site\Navigation::catalog()"
                    :link-label="__('site.home.categories.all')"
                />
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                    @foreach ($categories as $tile)
                        <x-site.category-tile :tile="$tile" wire:key="category-{{ $loop->index }}" />
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- Popular products --}}
    @if ($products !== [])
        <section class="bg-bg-soft py-12 md:py-[72px]" aria-labelledby="popular-title">
            <div class="site-container">
                <x-site.section-head
                    id="popular-title"
                    :title="__('site.home.popular.title')"
                    :subtitle="__('site.home.popular.subtitle')"
                    :link="\App\Support\Site\Navigation::catalog()"
                    :link-label="__('site.home.popular.all')"
                />
                <div class="grid grid-cols-2 gap-3 sm:gap-5 md:grid-cols-3 lg:grid-cols-4">
                    @foreach ($products as $product)
                        <x-site.product-card :product="$product" wire:key="product-{{ $product->slug }}" />
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- B2B --}}
    <section class="py-12 md:py-[72px]" aria-labelledby="b2b-title">
        <div class="site-container">
            <div class="relative grid items-center gap-10 overflow-hidden rounded-[28px] bg-ink px-6 py-8 text-white md:grid-cols-[1.2fr_0.8fr] md:p-14">
                <div class="pointer-events-none absolute -top-20 -right-20 size-[360px] rounded-full bg-[radial-gradient(var(--color-brand),transparent_70%)] opacity-60" aria-hidden="true"></div>
                <div class="relative">
                    <h2 id="b2b-title" class="mb-3.5 text-[28px] leading-[1.15] font-bold tracking-[-0.025em] md:text-[34px]">{{ __('site.home.b2b.title') }}</h2>
                    <p class="mb-6 max-w-[520px] text-ink-soft">{{ __('site.home.b2b.text') }}</p>
                    <a
                        href="#lead"
                        class="inline-flex h-[54px] items-center justify-center rounded-btn bg-brand-600 px-[30px] text-base font-semibold text-white transition-colors hover:bg-brand-700 focus-visible:outline-white"
                    >{{ __('site.home.b2b.cta') }}</a>
                </div>
                <ul class="relative grid gap-3">
                    @foreach (__('site.home.b2b.points') as $point)
                        <li class="flex items-center gap-3 rounded-box bg-white/7 px-[18px] py-3.5 font-medium" wire:key="b2b-{{ $loop->index }}">
                            <x-site.icon :name="['check', 'doc', 'wrench', 'shield'][$loop->index % 4]" class="text-logo" />
                            {{ $point }}
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </section>

    {{-- Why us --}}
    <section class="bg-bg-soft py-12 md:py-[72px]" aria-labelledby="why-title">
        <div class="site-container">
            <x-site.section-head id="why-title" :title="__('site.home.why.title')" />
            <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                @foreach (['warranty' => 'shield', 'delivery' => 'truck', 'setup' => 'wrench', 'support' => 'phone'] as $key => $icon)
                    <div class="rounded-box-lg border border-line bg-white p-7" wire:key="why-{{ $key }}">
                        <span class="mb-4 grid size-[52px] place-items-center rounded-2xl bg-bg-tint text-brand-600"><x-site.icon :name="$icon" size="size-[26px]" /></span>
                        <h3 class="mb-1.5 text-[17px] font-bold">{{ __("site.home.why.items.{$key}.title") }}</h3>
                        <p class="text-sm text-ink-2">{{ __("site.home.why.items.{$key}.text") }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Services --}}
    <section id="services" class="scroll-mt-28 py-12 md:py-[72px]" aria-labelledby="services-title">
        <div class="site-container">
            <x-site.section-head
                id="services-title"
                :title="__('site.home.services.title')"
                :subtitle="__('site.home.services.subtitle')"
            />
            <div class="grid gap-5 md:grid-cols-3">
                @foreach ($services as $service)
                    <article class="flex flex-col gap-2.5 rounded-box-lg bg-bg-tint p-8" wire:key="service-{{ $service->key }}">
                        <h3 class="text-xl font-bold">{{ $service->title }}</h3>
                        <p class="text-ink-2">{{ $service->text }}</p>
                        <a href="{{ $service->url }}" class="mt-auto pt-2.5 font-semibold text-brand-600 hover:underline">
                            {{ __('site.home.services.more') }}<span class="sr-only">: {{ $service->title }}</span> <span aria-hidden="true">→</span>
                        </a>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Brands --}}
    @if ($brands !== [])
        <section class="bg-bg-soft py-12" aria-labelledby="brands-title">
            <div class="site-container">
                <h2 id="brands-title" class="sr-only">{{ __('site.home.brands.title') }}</h2>
                <ul class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                    @foreach ($brands as $brand)
                        <li wire:key="brand-{{ $brand->slug }}">
                            <a href="{{ $brand->url }}" class="grid h-[76px] place-items-center rounded-box border border-line bg-white px-4 font-extrabold tracking-[0.04em] text-ink-2 uppercase transition-colors hover:border-brand hover:text-brand-600">
                                @if ($brand->logo)
                                    <img src="{{ $brand->logo }}" alt="{{ $brand->name }}" width="160" height="40" loading="lazy" class="max-h-10 w-auto object-contain">
                                @else
                                    {{ $brand->name }}
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif

    {{-- Lead form --}}
    <section id="lead" class="scroll-mt-28 py-12 md:py-[72px]" aria-labelledby="lead-title">
        <div class="site-container">
            <div class="grid items-center gap-8 rounded-[28px] bg-bg-tint px-6 py-8 md:grid-cols-2 md:gap-12 md:p-14">
                <div>
                    <h2 id="lead-title" class="mb-3 text-[28px] leading-[1.15] font-bold tracking-[-0.02em] md:text-[32px]">{{ __('site.home.lead.title') }}</h2>
                    <p class="text-ink-2">{{ __('site.home.lead.text') }}</p>
                </div>
                <livewire:site.lead-form />
            </div>
        </div>
    </section>
</div>
