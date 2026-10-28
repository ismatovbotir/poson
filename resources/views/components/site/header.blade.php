{{--
    Sticky site header: burger (below 900px), logo, catalog menu (x-site.catalog-menu), search
    (/{locale}/search), phone (from 1100px), the cart link with its counter (plan 11a,
    while the cart is on; Site\Cart\Counter) and the quote CTA, followed by the
    main nav.
    The burger toggles "menuOpen" from the x-data on <body> (see x-site.mobile-menu).
--}}
@inject('company', \App\Support\CompanySettings::class)

<header class="sticky top-0 z-40 border-b border-line bg-white">
    <div class="site-container flex flex-wrap items-center gap-x-3 gap-y-2 py-2.5 md:h-[76px] md:flex-nowrap md:gap-6 md:py-0">
        <button
            type="button"
            class="-ml-2 grid size-11 place-items-center rounded-btn text-ink hover:bg-bg-soft md:hidden"
            aria-controls="site-drawer"
            aria-expanded="false"
            x-bind:aria-expanded="menuOpen.toString()"
            x-on:click="menuOpen = true"
        >
            <x-site.icon name="menu" size="size-6" />
            <span class="sr-only">{{ __('site.header.menu_open') }}</span>
        </button>

        <x-site.logo />

        <x-site.catalog-menu />

        {{-- Below 900px the search is its own row. The route check keeps the header safe outside localized routes. --}}
        @if (\Illuminate\Support\Facades\Route::has('site.search'))
            <form role="search" method="get" action="{{ route('site.search', ['locale' => app()->getLocale()]) }}" class="relative order-last w-full md:order-none md:flex-1">
                <label for="site-search" class="sr-only">{{ __('site.header.search_label') }}</label>
                <x-site.icon name="search" class="pointer-events-none absolute top-[13px] left-3.5 text-ink-2" />
                <input
                    id="site-search"
                    type="search"
                    name="q"
                    value="{{ request()->routeIs('site.search') && is_string(request()->query('q')) ? request()->query('q') : '' }}"
                    maxlength="100"
                    autocomplete="off"
                    placeholder="{{ __('site.header.search_placeholder') }}"
                    class="h-[46px] w-full rounded-btn border-[1.5px] border-line bg-bg-soft pr-28 pl-11 text-ink transition-colors placeholder:text-ink-2 focus:border-brand-600 focus:bg-white focus:outline-none sm:pr-32"
                >
                <button type="submit" class="absolute top-1 right-1 h-[38px] rounded-lg bg-ink px-4 font-semibold text-white hover:bg-ink-line sm:px-[18px]">
                    {{ __('site.header.search_button') }}
                </button>
            </form>
        @else
            <div class="hidden md:block md:flex-1" aria-hidden="true"></div>
        @endif

        @if ($company->phone() !== '')
            <a href="tel:{{ $company->phone() }}" class="hidden shrink-0 flex-col leading-tight lg:flex">
                <span class="sr-only">{{ __('site.topbar.call_us') }}:</span>
                <span class="font-bold whitespace-nowrap text-ink">{{ $company->phoneDisplay() }}</span>
                <span class="text-xs whitespace-nowrap text-ink-2">{{ $company->workingHours() }}</span>
            </a>
        @endif

        {{-- Only on localized pages (the cart route needs the locale), and while the cart is on. --}}
        @if (config('site.cart_enabled') && isset(\Illuminate\Support\Facades\URL::getDefaultParameters()['locale']))
            <livewire:site.cart.counter />
        @endif

        <a
            href="{{ \App\Support\Site\Navigation::quote() }}"
            class="hidden h-[46px] shrink-0 items-center justify-center rounded-btn border-[1.5px] border-brand-600 px-5 font-semibold whitespace-nowrap text-brand-600 transition-colors hover:bg-brand-50 md:inline-flex"
        >
            {{ __('site.header.request_quote') }}
        </a>
    </div>

    <x-site.nav />
</header>
