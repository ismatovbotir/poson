{{--
    News post (App\Livewire\Site\News\Show), in the static pages' visual language:
    breadcrumbs, title, date, excerpt as the lead, cover, body, then the related
    categories (chips) and products (cards) and a link back to the list.
--}}
@php
    $proseClass = 'text-ink-2 [&_a]:font-medium [&_a]:text-brand-600 [&_a]:underline [&_h2]:mt-8 [&_h2]:mb-3 [&_h2]:text-xl [&_h2]:font-bold [&_h2]:text-ink [&_h3]:mt-6 [&_h3]:mb-2.5 [&_h3]:text-lg [&_h3]:font-bold [&_h3]:text-ink [&_li]:mb-2 [&_ol]:mb-4 [&_ol]:list-decimal [&_ol]:pl-5 [&_p]:mb-4 [&_strong]:text-ink [&_ul]:mb-4 [&_ul]:list-disc [&_ul]:pl-5 [&_li]:marker:text-brand [&_table]:mb-4 [&_table]:w-full [&_td]:border [&_td]:border-line [&_td]:px-3 [&_td]:py-2 [&_th]:border [&_th]:border-line [&_th]:bg-bg-soft [&_th]:px-3 [&_th]:py-2 [&_th]:text-left';
@endphp

<div class="site-container">
    @push('head')
        <link rel="alternate" type="application/rss+xml" title="{{ __('site.news.rss_title') }}" href="{{ $rssUrl }}">
        @foreach ($jsonLd as $block)
            <script type="application/ld+json">{!! $block !!}</script>
        @endforeach
    @endpush

    <x-site.breadcrumbs :items="$breadcrumbs" />

    <article class="max-w-[900px]" data-news-post>
        <header class="mb-6">
            @if ($publishedAt)
                <time datetime="{{ $publishedAt->toAtomString() }}" class="mb-2 block text-sm text-ink-3" data-news-date>{{ \App\Support\DateFormat::iso($publishedAt, 'D MMMM YYYY') }}</time>
            @endif
            <h1 class="text-[26px] leading-[1.15] font-bold tracking-[-0.025em] sm:text-[34px]">{{ $title }}</h1>
            @if ($excerpt !== '')
                <p class="mt-4 text-lg text-ink-2" data-news-excerpt>{{ $excerpt }}</p>
            @endif
        </header>

        @if ($cover)
            <img src="{{ $cover }}" alt="{{ $coverAlt }}" width="1200" height="675" class="mb-8 aspect-video w-full rounded-box-lg object-cover" fetchpriority="high" data-news-cover>
        @endif

        <div class="{{ $proseClass }}" data-news-body>{!! $body !!}</div>

        @if ($categories !== [])
            <section class="mt-8" aria-labelledby="news-categories-title" data-news-categories>
                <h2 id="news-categories-title" class="mb-3 text-sm font-semibold text-ink-3">{{ __('site.news.related_categories') }}</h2>
                <ul class="flex flex-wrap gap-2">
                    @foreach ($categories as $category)
                        <li><a href="{{ $category['url'] }}" class="inline-flex h-9 items-center rounded-full border border-line bg-white px-4 text-sm font-medium text-ink-2 transition-colors hover:border-brand hover:text-brand-600">{{ $category['name'] }}</a></li>
                    @endforeach
                </ul>
            </section>
        @endif
    </article>

    @if ($products !== [])
        <section class="mt-12" aria-labelledby="news-products-title" data-news-products>
            <x-site.section-head id="news-products-title" :title="__('site.news.related_products')" />
            <div class="grid grid-cols-2 gap-3 sm:gap-5 md:grid-cols-3 lg:grid-cols-4">
                @foreach ($products as $card)
                    <x-site.product-card :product="$card" wire:key="news-product-{{ $card->slug }}" />
                @endforeach
            </div>
        </section>
    @endif

    <p class="mt-10">
        <a href="{{ route('site.news.index') }}" class="font-semibold text-brand-600 hover:underline"><span aria-hidden="true">←</span> {{ __('site.news.back') }}</a>
    </p>
</div>
