@props(['slides'])

{{--
    Home banner slider below the static hero (plan 07 7c, K12): the live
    home_hero banners (App\Support\Site\BannerDirectory::home()) in sort
    order. Hidden when there are none. No autoplay (decision 2026-10-23):
    the visitor moves it with the prev / next buttons, the dots, a swipe or
    the arrow keys. WAI-ARIA carousel pattern: the region has
    aria-roledescription="carousel", every slide is a labelled group, hidden
    slides are aria-hidden and inert. The first image loads eagerly with
    fetchpriority="high", the others lazily. Alpine only.
--}}
@php
    $count = count($slides);
    $trackId = 'banner-slides';
@endphp

@if ($count > 0)
    <section
        {{ $attributes->class('pb-6 md:pb-10') }}
        aria-roledescription="carousel"
        aria-label="{{ __('site.banners.label') }}"
        data-banner-slider
        x-data="{
            current: 0,
            count: {{ $count }},
            startX: null,
            go(index) { this.current = (index + this.count) % this.count },
            next() { this.go(this.current + 1) },
            prev() { this.go(this.current - 1) },
            swipeEnd(event) {
                if (this.startX === null) return;
                const dx = event.changedTouches[0].clientX - this.startX;
                this.startX = null;
                if (Math.abs(dx) > 40) dx < 0 ? this.next() : this.prev();
            },
        }"
        @if ($count > 1)
            x-on:keydown.arrow-right="next()"
            x-on:keydown.arrow-left="prev()"
        @endif
    >
        <div class="site-container">
            <div class="relative">
                <div class="overflow-hidden rounded-box-lg" @if ($count > 1) x-on:touchstart.passive="startX = $event.touches[0].clientX" x-on:touchend="swipeEnd($event)" @endif>
                    <div
                        id="{{ $trackId }}"
                        class="flex transition-transform duration-500 ease-out motion-reduce:transition-none"
                        aria-live="polite"
                        x-bind:style="'transform: translateX(-' + (current * 100) + '%)'"
                    >
                        @foreach ($slides as $slide)
                            <div
                                class="w-full shrink-0"
                                role="group"
                                aria-roledescription="slide"
                                aria-label="{{ __('site.banners.slide', ['current' => $loop->iteration, 'total' => $count]) }}: {{ $slide->title }}"
                                @unless ($loop->first) aria-hidden="true" inert @endunless
                                x-bind:aria-hidden="(current !== {{ $loop->index }}).toString()"
                                x-bind:inert="current !== {{ $loop->index }}"
                                wire:key="banner-slide-{{ $slide->id }}"
                                data-banner-slide="{{ $slide->id }}"
                            >
                                <x-site.banner :slide="$slide" :eager="$loop->first" />
                            </div>
                        @endforeach
                    </div>
                </div>

                @if ($count > 1)
                    <button
                        type="button"
                        class="absolute top-1/2 left-3 hidden size-11 -translate-y-1/2 place-items-center rounded-full bg-white/90 text-ink shadow-card transition-colors hover:bg-white hover:text-brand-600 focus-visible:outline-2 focus-visible:outline-brand-600 sm:grid"
                        aria-controls="{{ $trackId }}"
                        aria-label="{{ __('site.banners.prev') }}"
                        x-on:click="prev()"
                        data-banner-prev
                    ><x-site.icon name="arrow" class="rotate-180" /></button>
                    <button
                        type="button"
                        class="absolute top-1/2 right-3 hidden size-11 -translate-y-1/2 place-items-center rounded-full bg-white/90 text-ink shadow-card transition-colors hover:bg-white hover:text-brand-600 focus-visible:outline-2 focus-visible:outline-brand-600 sm:grid"
                        aria-controls="{{ $trackId }}"
                        aria-label="{{ __('site.banners.next') }}"
                        x-on:click="next()"
                        data-banner-next
                    ><x-site.icon name="arrow" /></button>
                @endif
            </div>

            @if ($count > 1)
                <div class="mt-3 flex items-center justify-center gap-1">
                    <button type="button" class="grid size-10 place-items-center rounded-full text-ink-2 hover:text-brand-600 focus-visible:outline-2 focus-visible:outline-brand-600 sm:hidden" aria-controls="{{ $trackId }}" aria-label="{{ __('site.banners.prev') }}" x-on:click="prev()"><x-site.icon name="arrow" class="rotate-180" size="size-4" /></button>
                    @foreach ($slides as $slide)
                        <button
                            type="button"
                            class="group grid size-6 place-items-center rounded-full focus-visible:outline-2 focus-visible:outline-brand-600"
                            aria-controls="{{ $trackId }}"
                            aria-label="{{ __('site.banners.go_to', ['number' => $loop->iteration]) }}"
                            @if ($loop->first) aria-current="true" @endif
                            x-bind:aria-current="current === {{ $loop->index }} ? 'true' : 'false'"
                            x-on:click="go({{ $loop->index }})"
                            wire:key="banner-dot-{{ $slide->id }}"
                            data-banner-dot
                        >
                            <span class="block h-2 w-2 rounded-full bg-ink-soft transition-all group-aria-[current=true]:w-6 group-aria-[current=true]:bg-brand-600" aria-hidden="true"></span>
                        </button>
                    @endforeach
                    <button type="button" class="grid size-10 place-items-center rounded-full text-ink-2 hover:text-brand-600 focus-visible:outline-2 focus-visible:outline-brand-600 sm:hidden" aria-controls="{{ $trackId }}" aria-label="{{ __('site.banners.next') }}" x-on:click="next()"><x-site.icon name="arrow" size="size-4" /></button>
                </div>
            @endif
        </div>
    </section>
@endif
