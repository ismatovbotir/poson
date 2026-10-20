@props(['variant' => 'light'])

{{-- Logo link to the localized home page. variant "dark" is for the ink footer. --}}
<a
    href="{{ route('site.home', ['locale' => app()->getLocale()]) }}"
    {{ $attributes->class([
        'inline-flex shrink-0 items-center gap-2.5 text-2xl font-extrabold tracking-[-0.03em]',
        'text-ink' => $variant === 'light',
        'text-white focus-visible:outline-white' => $variant === 'dark',
    ]) }}
    aria-label="{{ __('site.header.logo_alt') }}"
>
    <img src="{{ asset('assets/site/logo.svg') }}" alt="" width="40" height="40" class="size-9 md:size-10">
    <span>poson<b @class(['font-extrabold', 'text-brand-600' => $variant === 'light', 'text-logo' => $variant === 'dark'])>.uz</b></span>
</a>
