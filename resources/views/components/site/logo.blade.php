@props(['variant' => 'light'])

{{-- Logo link to the localized home page. variant "dark" is for the dark footer (white wordmark). --}}
<a
    href="{{ route('site.home', ['locale' => app()->getLocale()]) }}"
    {{ $attributes->class(['inline-flex shrink-0 items-center', 'focus-visible:outline-white' => $variant === 'dark']) }}
    aria-label="{{ __('site.header.logo_alt') }}"
>
    <img src="{{ asset($variant === 'dark' ? 'assets/site/logo-light.svg' : 'assets/site/logo.svg') }}" alt="" width="149" height="40" class="h-9 w-auto md:h-10">
</a>
