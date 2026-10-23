{{--
    News list (App\Livewire\Site\News\Index), in the catalog's visual language:
    breadcrumbs, heading with the RSS link, cards (x-site.news-card), pager.
--}}
<div class="site-container">
    @push('head')
        <link rel="alternate" type="application/rss+xml" title="{{ __('site.news.rss_title') }}" href="{{ $rssUrl }}">
        @foreach ($jsonLd as $block)
            <script type="application/ld+json">{!! $block !!}</script>
        @endforeach
    @endpush

    <x-site.breadcrumbs :items="$breadcrumbs" />

    <header class="mb-7 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-[26px] leading-[1.15] font-bold tracking-[-0.025em] sm:text-[34px]">{{ __('site.news.title') }}</h1>
            <p class="mt-2 text-ink-2">{{ __('site.news.subtitle') }}</p>
        </div>
        <a href="{{ $rssUrl }}" class="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-600 hover:underline" data-news-rss>
            <x-site.icon name="rss" size="size-4" />{{ __('site.news.rss') }}
        </a>
    </header>

    <div id="news-results" class="scroll-mt-28">
        @if ($cards === [])
            <p class="rounded-box-lg border border-dashed border-line bg-bg-soft p-8 text-center text-ink-2" data-news-empty>{{ __('site.news.empty') }}</p>
        @else
            <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($cards as $card)
                    <x-site.news-card :card="$card" heading-level="h2" wire:key="news-{{ $card->slug }}" />
                @endforeach
            </div>

            {{ $posts->links('vendor.pagination.site', ['scrollTarget' => 'news-results']) }}
        @endif
    </div>
</div>
