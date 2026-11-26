{{--
    Telegram Mini App layout (plan 20 §2 A4; MA-3, MA-16, MA-18; plan 45). Pages @extends('layouts.tma')
    and fill @section('content'). Variables: $title (screen title), $back (parent screen URL for
    Telegram's BackButton; none on the home screen), $cartCount (null while the cart is off),
    $cartBar (TmaCartSummary on home, category, search and product: the sticky cart bar, mirrored to
    Telegram's MainButton by tma.js), $headerLink (['url', 'label'], a text link in the header), $titleIcon (an icon before the title),
    $home (the full logo instead of the small one), $boot (the sign-in page).
    Only here: telegram-web-app.js (first in <head>) and the tma bundle. No Livewire, Alpine,
    analytics, web chat widget, banners, webfont, inline <script> / <style> or style= attributes.
    The status flash is a fixed toast (role="status"); tma.js reuses it for its own messages.
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
        <header class="sticky top-0 z-10 flex min-h-14 items-center gap-3 bg-tg-bg px-4 py-1.5">
            @if (! empty($home))
                <span class="inline-flex rounded-lg bg-white px-2.5 py-1.5" data-logo="full">
                    <img src="{{ asset('assets/site/logo.svg') }}" alt="POSON" width="120" height="32" class="h-8 w-auto">
                </span>
                <span class="flex-1"></span>
            @else
                <a href="{{ route('tma.home') }}" class="inline-flex min-h-11 shrink-0 items-center" data-logo="small" aria-label="{{ __('tma.nav.home') }}">
                    <span class="inline-flex rounded-md bg-white px-1.5 py-1"><img src="{{ asset('assets/site/logo.svg') }}" alt="POSON" width="60" height="16" class="h-4 w-auto"></span>
                </a>
                @isset($titleIcon)
                    <x-tma.icon :name="$titleIcon" class="text-tg-hint" />
                @endisset
                <h1 class="min-w-0 flex-1 truncate text-[17px] font-semibold">{{ $title ?? '' }}</h1>
            @endif

            @isset($headerLink)
                <a href="{{ $headerLink['url'] }}" class="inline-flex min-h-11 items-center px-1 text-sm font-medium text-tg-link" data-header-link>{{ $headerLink['label'] }}</a>
            @endisset

            @if (($cartCount ?? null) !== null)
                <a href="{{ route('tma.cart') }}" class="relative inline-flex min-h-11 items-center gap-1.5 rounded-full bg-tg-secondary px-3 text-sm font-medium" data-cart-count="{{ $cartCount }}" aria-label="{{ __('tma.nav.cart') }}">
                    <x-tma.icon name="cart" />
                    <span class="rounded-full bg-tg-button px-1.5 text-xs font-semibold text-tg-button-text" data-cart-badge @if ($cartCount < 1) hidden @endif>{{ $cartCount }}</span>
                </a>
            @endif
        </header>
    @endunless

    <main class="mx-auto grid max-w-xl gap-5 px-4 pt-3 {{ isset($cartBar) ? 'pb-28' : 'pb-24' }}">
        @if (isset($errors) && $errors->any())
            <div role="alert" class="tma-card text-sm font-medium text-tg-danger" data-errors>
                @foreach (array_unique($errors->all()) as $message)
                    <p>{{ $message }}</p>
                @endforeach
            </div>
        @endif

        @yield('content')
    </main>

    @isset($cartBar)
        @php($barText = __('tma.bar.label').' ('.$cartBar->count.') · '.$cartBar->subtotalText())
        <div class="tma-bar" data-cart-bar-wrap @if ($cartBar->count < 1) hidden @endif>
            <a href="{{ route('tma.cart') }}" class="tma-btn mx-auto max-w-xl justify-between" data-cart-bar data-main-button="{{ $barText }}" data-bar-label="{{ __('tma.bar.label') }}">
                <span class="inline-flex items-center gap-2"><x-tma.icon name="cart" /> {{ __('tma.bar.label') }} (<span data-bar-count>{{ $cartBar->count }}</span>)</span>
                <span data-bar-total>{{ $cartBar->subtotalText() }}</span>
            </a>
        </div>
    @endisset

    @unless (isset($boot))
        @php($status = app('session')->isStarted() ? session('tma_status') : null)
        <p role="status" aria-live="polite" class="tma-toast {{ $status ? 'tma-toast-on' : '' }}" data-toast data-fail="{{ __('tma.toast.error') }}" data-retry="{{ __('tma.toast.retry') }}" data-added="{{ __('tma.status.added') }}" @if ($status) data-status="{{ $status }}" @endif>{{ $status ? __('tma.status.'.$status) : '' }}</p>
    @endunless

    {!! \App\Support\Telegram\MiniApp\TmaIcons::inlineSprite() !!}
</body>
</html>
