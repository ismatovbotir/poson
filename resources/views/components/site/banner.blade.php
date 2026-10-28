@props(['slide', 'eager' => false, 'headingLevel' => 'h2'])

{{--
    One banner (App\Data\Site\BannerSlide): a home slider slide or the
    category banner. The image fills a fixed-ratio box (4:3 on phones, then
    16:7 and 16:5), so nothing shifts while it loads; <picture> serves the
    mobile WebP below 640px when there is one. The texts are rendered over
    the image (no text is baked into banner images, K7). Eager images get
    fetchpriority="high" (the first slide); the others load lazily.
    Internal links are already in the current locale (BannerLink::href());
    external https links open in a new tab with rel="noopener".
--}}
@php
    use App\Models\Banner;
@endphp

<div {{ $attributes->class('relative aspect-[4/3] overflow-hidden rounded-box-lg bg-bg-tint sm:aspect-[16/7] lg:aspect-[16/5]') }} data-banner="{{ $slide->id }}">
    <picture>
        @if ($slide->mobileImage)
            <source media="(max-width: 639px)" srcset="{{ $slide->mobileImage }}" width="{{ Banner::MOBILE_WIDTH }}" height="{{ Banner::MOBILE_HEIGHT }}" type="image/webp">
        @endif
        <img
            src="{{ $slide->image }}"
            @if ($slide->imageMedium) srcset="{{ $slide->imageMedium }} {{ Banner::DESKTOP_MEDIUM_WIDTH }}w, {{ $slide->image }} {{ Banner::DESKTOP_WIDTH }}w" sizes="(min-width: 1280px) 1240px, 100vw" @endif
            alt="{{ $slide->imageAlt !== '' ? $slide->imageAlt : $slide->mobileImageAlt }}"
            width="{{ Banner::DESKTOP_WIDTH }}"
            height="{{ Banner::DESKTOP_HEIGHT }}"
            @if ($eager) loading="eager" fetchpriority="high" @else loading="lazy" @endif
            decoding="async"
            class="absolute inset-0 size-full object-cover"
        >
    </picture>
    <div class="absolute inset-0 bg-linear-to-t from-ink/80 via-ink/35 to-transparent sm:bg-linear-to-r sm:from-ink/75 sm:via-ink/40" aria-hidden="true"></div>

    <div class="relative flex h-full flex-col justify-end gap-2 p-5 text-white sm:max-w-[62%] sm:justify-center sm:gap-3 sm:p-8 lg:p-12">
        <{{ $headingLevel }} class="line-clamp-2 text-xl leading-tight font-extrabold tracking-[-0.02em] sm:line-clamp-none sm:text-3xl lg:text-[40px]">{{ $slide->title }}</{{ $headingLevel }}>
        @if ($slide->text !== '')
            <p class="line-clamp-2 text-sm text-white/90 sm:line-clamp-3 sm:text-base lg:text-lg">{{ $slide->text }}</p>
        @endif
        @if ($slide->href)
            <a
                href="{{ $slide->href }}"
                @if ($slide->external) target="_blank" rel="noopener" @endif
                class="mt-1 inline-flex h-11 w-fit items-center gap-2 rounded-btn bg-brand-600 px-5 text-sm font-semibold text-white transition-colors hover:bg-brand-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white sm:mt-2 sm:h-[50px] sm:px-6 sm:text-base"
                data-banner-link
            >
                {{ $slide->buttonLabel }}
                <x-site.icon name="arrow" />
                @if ($slide->external)<span class="sr-only">{{ __('site.banners.new_tab') }}</span>@endif
            </a>
        @endif
    </div>
</div>
