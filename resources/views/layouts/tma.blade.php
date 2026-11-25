{{--
    Telegram Mini App layout (plan 20 §2 A4; MA-3, MA-16, MA-18). Pages @extends('layouts.tma')
    and fill @section('content'). Variables: $title (screen title), $back (parent screen URL for
    Telegram's BackButton; none on the home screen), $cartCount (null while the cart is off),
    $home (the full logo instead of the small one), $boot (the sign-in page).
    Only here: telegram-web-app.js (first in <head>) and the tma bundle. No Livewire, Alpine,
    analytics, web chat widget, banners, webfont, inline <script> / <style> or style= attributes.
--}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' · ' : '' }}POSON</title>
    @vite(['resources/css/tma.css', 'resources/js/tma.js'])
</head>
<body
    class="min-h-screen bg-tg-secondary font-sans text-tg-text"
    @isset($back) data-back="{{ $back }}" @endisset
    @isset($boot) data-tma-boot data-auth-url="{{ route('tma.auth') }}" data-browser-url="{{ route('tma.unavailable', ['locale' => app()->getLocale(), 'reason' => 'browser']) }}" @if (! empty($next)) data-next="{{ $next }}" @endif @endisset
>
    @unless (isset($boot) || isset($bare))
        <header class="sticky top-0 z-10 flex items-center gap-3 bg-tg-bg px-4 py-2.5">
            @if (! empty($home))
                <span class="inline-flex rounded-lg bg-white px-2.5 py-1.5" data-logo="full">
                    <img src="{{ asset('assets/site/logo.svg') }}" alt="POSON" width="120" height="32" class="h-8 w-auto">
                </span>
            @else
                <a href="{{ route('tma.home') }}" class="inline-flex shrink-0 rounded-md bg-white px-1.5 py-1" data-logo="small" aria-label="{{ __('tma.nav.home') }}">
                    <img src="{{ asset('assets/site/logo.svg') }}" alt="POSON" width="60" height="16" class="h-4 w-auto">
                </a>
                <h1 class="min-w-0 flex-1 truncate text-base font-semibold">{{ $title ?? '' }}</h1>
            @endif

            @if (! empty($home))
                <span class="flex-1"></span>
            @endif

            @if (($cartCount ?? null) !== null)
                <a href="{{ route('tma.cart') }}" class="relative inline-flex items-center gap-1 rounded-full bg-tg-secondary px-3 py-1.5 text-sm font-medium" data-cart-count="{{ $cartCount }}">
                    {{ __('tma.nav.cart') }}
                    @if ($cartCount > 0)
                        <span class="rounded-full bg-tg-button px-1.5 text-xs font-semibold text-tg-button-text">{{ $cartCount }}</span>
                    @endif
                </a>
            @endif
        </header>
    @endunless

    <main class="mx-auto grid max-w-xl gap-3 px-3 py-3 pb-24">
        @if (app('session')->isStarted() && session('tma_status'))
            <p role="status" class="tma-card text-sm font-medium text-ok" data-status="{{ session('tma_status') }}">{{ __('tma.status.'.session('tma_status')) }}</p>
        @endif

        @if (isset($errors) && $errors->any())
            <div role="alert" class="tma-card text-sm font-medium text-tg-danger" data-errors>
                @foreach (array_unique($errors->all()) as $message)
                    <p>{{ $message }}</p>
                @endforeach
            </div>
        @endif

        @yield('content')
    </main>
</body>
</html>
