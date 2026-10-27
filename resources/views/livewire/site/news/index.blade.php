{{--
    News list (App\Livewire\Site\News\Index) and articles list (App\Livewire\Site\Articles\Index),
    in the catalog's visual language: breadcrumbs, heading with the RSS link (news only),
    category chips (articles), cards (x-site.news-card), pager.
--}}
<div class="site-container" data-kind="{{ $kind->value }}">
    @push('head')
        @if ($rssUrl)
            <link rel="alternate" type="application/rss+xml" title="{{ __('site.news.rss_title') }}" href="{{ $rssUrl }}">
        @endif
        @foreach ($jsonLd as $block)
            <script type="application/ld+json">{!! $block !!}</script>
        @endforeach
    @endpush

    <x-site.breadcrumbs :items="$breadcrumbs" />

    <header class="mb-7 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-[26px] leading-[1.15] font-bold tracking-[-0.025em] sm:text-[34px]">{{ $kind->siteText('title') }}</h1>
            <p class="mt-2 text-ink-2">{{ $kind->siteText('subtitle') }}</p>
        </div>
        @if ($rssUrl)
            <a href="{{ $rssUrl }}" class="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-600 hover:underline" data-news-rss>
                <x-site.icon name="rss" size="size-4" />{{ __('site.news.rss') }}
            </a>
        @endif
    </header>

    @if ($filters !== [])
        <nav class="-mt-2 mb-6" aria-label="{{ __('site.articles.filter_label') }}" data-article-filters>
            <ul class="flex flex-wrap gap-2">
                @foreach ($filters as $filter)
                    <li>
                        <a
                            href="{{ $filter['url'] }}"
                            @if ($filter['current']) aria-current="page" @endif
                            @class([
                                'inline-flex h-9 items-center rounded-full border px-4 text-sm font-medium transition-colors',
                                'border-brand bg-brand-600 text-white' => $filter['current'],
                                'border-line bg-white text-ink-2 hover:border-brand hover:text-brand-600' => ! $filter['current'],
                            ])
                        >{{ $filter['label'] }}</a>
                    </li>
                @endforeach
            </ul>
        </nav>
    @endif

    <div id="news-results" class="scroll-mt-28">
        @if ($cards === [])
            <p class="rounded-box-lg border border-dashed border-line bg-bg-soft p-8 text-center text-ink-2" data-news-empty>{{ $kind->siteText('empty') }}</p>
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
