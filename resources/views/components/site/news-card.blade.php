@props(['card', 'headingLevel' => 'h3'])

{{--
    News card (App\Data\Site\NewsCard): cover (16:9, lazy; a tinted panel with
    the news icon until the WebP conversion exists), date, title and excerpt.
    The whole card is one link.
--}}
<article {{ $attributes->class('group relative flex flex-col overflow-hidden rounded-box-lg border border-line bg-white transition hover:-translate-y-[3px] hover:border-brand hover:shadow-card motion-reduce:hover:translate-y-0') }} data-news-card="{{ $card->slug }}">
    @if ($card->image)
        <img src="{{ $card->image }}" alt="{{ $card->imageAlt }}" width="1200" height="675" loading="lazy" class="aspect-video w-full object-cover">
    @else
        <span class="grid aspect-video w-full place-items-center bg-bg-tint text-brand-600" aria-hidden="true"><x-site.icon name="doc" size="size-10" /></span>
    @endif
    <div class="flex flex-1 flex-col gap-2 p-5">
        @if ($card->publishedAt)
            <time datetime="{{ $card->publishedAt->toDateString() }}" class="text-[13px] text-ink-3">{{ \App\Support\DateFormat::iso($card->publishedAt, 'D MMMM YYYY') }}</time>
        @endif
        <{{ $headingLevel }} class="text-[17px] leading-snug font-bold text-ink group-hover:text-brand-600">
            <a href="{{ $card->url }}" class="after:absolute after:inset-0 focus-visible:outline-none">{{ $card->title }}</a>
        </{{ $headingLevel }}>
        @if ($card->excerpt !== '')
            <p class="line-clamp-3 text-sm text-ink-2">{{ $card->excerpt }}</p>
        @endif
    </div>
</article>
