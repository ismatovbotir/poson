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
    Plan 04: site name and the default OG image come from Settings → Company,
    plus search engine verification metas and the analytics config tag
    (read by resources/js/analytics.js; no inline script).
--}}
@inject('company', \App\Support\CompanySettings::class)
@inject('analytics', \App\Support\Site\Analytics::class)

@php
    $plain = fn (mixed $value): string => trim(html_entity_decode(strip_tags($value instanceof \Illuminate\Contracts\Support\Htmlable ? $value->toHtml() : (string) $value), ENT_QUOTES | ENT_HTML5));

    $siteName = $company->name();
    $image = $image ?: ($company->ogImageUrl() ?: null);
    $verification = $company->verification();
    $analyticsJson = $analytics->json();
    $pageTitle = $plain($title);
    $pageTitle = $pageTitle !== '' ? $pageTitle : $siteName.' — '.__('site.meta.tagline');
    $pageDescription = $plain($description);
    $pageDescription = $pageDescription !== '' ? $pageDescription : __('site.meta.description');

    $locale = app()->getLocale();
    $alternates = \App\Support\LocaleUrl::alternates();
    $canonical = $alternates[$locale] ?? \App\Support\LocaleUrl::canonical();
    // Like the sitemap: the default locale, else the first listed one (a page restricted to ru + en).
    $xDefault = $alternates[\App\Support\LocaleUrl::defaultLocale()] ?? (reset($alternates) ?: $canonical);
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

@if ($verification['google'] !== '')
    <meta name="google-site-verification" content="{{ $verification['google'] }}">
@endif
@if ($verification['yandex'] !== '')
    <meta name="yandex-verification" content="{{ $verification['yandex'] }}">
@endif
@if ($verification['bing'] !== '')
    <meta name="msvalidate.01" content="{{ $verification['bing'] }}">
@endif
@if ($analyticsJson !== null)
    <meta name="poson-analytics" content='{{ $analyticsJson }}'>
@endif

<link rel="icon" type="image/svg+xml" href="{{ asset('assets/site/logo.svg') }}">
<meta name="theme-color" content="#1f9d55">
