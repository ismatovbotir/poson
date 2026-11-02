@props(['video'])

{{--
    Click-to-load YouTube player (plan 19). Before the click nothing is requested from
    Google/YouTube: only a locally stored preview image (or a placeholder) is shown. The
    iframe exists only after the click, with the privacy-enhanced (nocookie) embed URL that
    reaches Alpine through Js::from. The "Watch on YouTube" link works without JavaScript.
--}}
<div {{ $attributes->class(['min-w-0']) }} x-data="{ playing: false, src: {{ \Illuminate\Support\Js::from($video['embed']) }} }" data-video-facade>
    <div class="relative aspect-video overflow-hidden rounded-box border border-line bg-bg-soft">
        <button
            type="button"
            x-show="! playing"
            x-on:click="playing = true; $nextTick(() => $refs.frame && $refs.frame.focus())"
            class="group absolute inset-0 grid place-items-center focus-visible:outline-2 focus-visible:outline-offset-[-3px] focus-visible:outline-brand-600"
            aria-label="{{ __('site.videos.play') }}: {{ $video['title'] }}"
            data-video-play
        >
            @if ($video['thumbnail'])
                <img src="{{ $video['thumbnail'] }}" alt="" width="1280" height="720" loading="lazy" class="absolute inset-0 size-full object-cover">
            @else
                <span class="absolute inset-0 bg-linear-to-br from-bg-tint to-bg-soft" aria-hidden="true"></span>
            @endif
            <span class="relative grid size-16 place-items-center rounded-full bg-ink/80 text-white shadow-card transition-colors group-hover:bg-brand-600" aria-hidden="true">
                <svg viewBox="0 0 24 24" class="ml-0.5 size-7" fill="currentColor" focusable="false"><path d="M8 5.5v13a1 1 0 0 0 1.52.85l10.4-6.5a1 1 0 0 0 0-1.7L9.52 4.65A1 1 0 0 0 8 5.5Z" /></svg>
            </span>
        </button>
        <template x-if="playing">
            <iframe
                x-ref="frame"
                x-bind:src="src"
                title="{{ $video['title'] }}"
                class="absolute inset-0 size-full"
                loading="lazy"
                allow="autoplay; encrypted-media; picture-in-picture; web-share"
                referrerpolicy="strict-origin-when-cross-origin"
                allowfullscreen
            ></iframe>
        </template>
    </div>
    <p class="mt-2 text-xs text-ink-2" data-video-notice>{{ __('site.videos.notice') }}</p>
    <a href="{{ $video['watch'] }}" target="_blank" rel="noopener noreferrer" class="mt-1 inline-flex items-center gap-1.5 text-sm font-medium text-brand-600 hover:underline" data-video-watch>{{ __('site.videos.watch_on_youtube') }} <span aria-hidden="true">→</span></a>
</div>
