@props([
    'title' => null,
    'description' => null,
    'image' => null,
    'type' => 'website',
    'robots' => null,
])

{{--
    <head> SEO block: title, description, canonical, hreflang (uz/ru/en + x-default),
    Open Graph / Twitter basics and favicon. Canonical and hreflang use clean URLs
    (LocaleUrl::clean): only "page" survives from the query string.
--}}
@php
    $plain = fn (mixed $value): string => trim(html_entity_decode(strip_tags($value instanceof \Illuminate\Contracts\Support\Htmlable ? $value->toHtml() : (string) $value), ENT_QUOTES | ENT_HTML5));

    $siteName = __('site.meta.site_name');
    $pageTitle = $plain($title);
    $pageTitle = $pageTitle !== '' ? $pageTitle : $siteName.' — '.__('site.meta.tagline');
    $pageDescription = $plain($description);
    $pageDescription = $pageDescription !== '' ? $pageDescription : __('site.meta.description');

    $locale = app()->getLocale();
    $alternates = \App\Support\LocaleUrl::alternates();
    $canonical = $alternates[$locale] ?? \App\Support\LocaleUrl::canonical();
    $xDefault = $alternates[\App\Support\LocaleUrl::defaultLocale()] ?? $canonical;
    $ogLocales = ['uz' => 'uz_UZ', 'ru' => 'ru_RU', 'en' => 'en_US'];
@endphp

<title>{{ $pageTitle }}</title>
<meta name="description" content="{{ $pageDescription }}">
@if ($robots)
    <meta name="robots" content="{{ $robots }}">
@endif
<link rel="canonical" href="{{ $canonical }}">
@foreach ($alternates as $code => $url)
    <link rel="alternate" hreflang="{{ $code }}" href="{{ $url }}">
@endforeach
<link rel="alternate" hreflang="x-default" href="{{ $xDefault }}">

<meta property="og:type" content="{{ $type }}">
<meta property="og:site_name" content="{{ $siteName }}">
<meta property="og:title" content="{{ $pageTitle }}">
<meta property="og:description" content="{{ $pageDescription }}">
<meta property="og:url" content="{{ $canonical }}">
<meta property="og:locale" content="{{ $ogLocales[$locale] ?? $locale }}">
@foreach (array_keys($alternates) as $code)
    @if ($code !== $locale)
        <meta property="og:locale:alternate" content="{{ $ogLocales[$code] ?? $code }}">
    @endif
@endforeach
@if ($image)
    <meta property="og:image" content="{{ $image }}">
@endif
<meta name="twitter:card" content="{{ $image ? 'summary_large_image' : 'summary' }}">

<link rel="icon" type="image/svg+xml" href="{{ asset('assets/site/logo.svg') }}">
<meta name="theme-color" content="#1f9d55">
