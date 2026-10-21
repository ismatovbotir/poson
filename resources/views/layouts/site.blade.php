@props([
    'title' => null,
    'description' => null,
    'image' => null,
    'robots' => null,
    'type' => 'website',
])

{{--
    Public site layout (layouts::site), ported from .claude/html-templates/site/index.html.
    Blade:    <x-layouts::site :title="..." :description="...">...</x-layouts::site>
    Livewire: default component_layout; #[Title] fills $title.
    Extra <head> tags (JSON-LD, robots): @push('head'); content after the footer
    (e.g. room for a fixed mobile bar): @push('after-footer').
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <x-site.seo-head :title="$title" :description="$description" :image="$image" :robots="$robots" :type="$type" />
    @stack('head')

    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body
    class="min-h-screen bg-white font-sans text-ink"
    x-data="{ menuOpen: false }"
    x-on:keydown.escape.window="menuOpen = false"
    x-on:resize.window.debounce.150ms="if (window.innerWidth >= 900) menuOpen = false"
>
    <a
        href="#main"
        class="sr-only z-[60] rounded-btn bg-white px-4 py-2 font-semibold text-brand-600 shadow-card focus:not-sr-only focus:fixed focus:top-2 focus:left-2"
    >{{ __('site.a11y.skip_to_content') }}</a>

    <x-site.icon-sprite />
    <x-site.topbar />
    <x-site.header />
    <x-site.mobile-menu />

    <main id="main" tabindex="-1" class="focus:outline-none">
        {{ $slot }}
    </main>

    <x-site.footer />
    @stack('after-footer')

    @livewireScripts
</body>
</html>
