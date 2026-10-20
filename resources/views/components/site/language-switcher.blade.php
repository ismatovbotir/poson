@props(['variant' => 'dark'])

{{--
    Language switcher: same page and query string in another locale (LocaleUrl::switch).
    variant "dark" sits on the ink topbar, "light" on white (mobile drawer).
--}}
@php
    $current = app()->getLocale();
@endphp

<nav {{ $attributes->class([
    'flex gap-0.5 rounded-md p-0.5',
    'bg-white/10' => $variant === 'dark',
    'bg-bg-soft' => $variant === 'light',
]) }} aria-label="{{ __('site.locale.switch_label') }}">
    @foreach (config('app.supported_locales') as $locale)
        <a
            href="{{ \App\Support\LocaleUrl::switch($locale) }}"
            hreflang="{{ $locale }}"
            lang="{{ $locale }}"
            title="{{ __("site.locale.{$locale}", [], $locale) }}"
            data-locale-link="{{ $locale }}"
            @if ($locale === $current) aria-current="true" @endif
            @class([
                'rounded px-2 py-px text-[13px] font-medium leading-5 uppercase transition-colors',
                'bg-white text-ink' => $locale === $current && $variant === 'dark',
                'text-ink-soft hover:text-white focus-visible:outline-white' => $locale !== $current && $variant === 'dark',
                'bg-brand-600 text-white' => $locale === $current && $variant === 'light',
                'text-ink-2 hover:text-brand-600' => $locale !== $current && $variant === 'light',
            ])
        ><span class="sr-only">{{ __("site.locale.{$locale}", [], $locale) }}</span><span aria-hidden="true">{{ $locale }}</span></a>
    @endforeach
</nav>
