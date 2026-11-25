{{--
    Sticky site header: burger (below 900px), logo, catalog menu (x-site.catalog-menu), search
    (/{locale}/search), a call icon button (below 1100px), phone (from 1100px), the cart link with its counter (plan 11a,
    while the cart is on; Site\Cart\Counter) and the quote CTA, followed by the
    main nav.
    The burger toggles "menuOpen" from the x-data on <body> (see x-site.mobile-menu).
--}}
@inject('company', \App\Support\CompanySettings::class)

<header class="sticky top-0 z-40 border-b border-line bg-white" x-data="siteHeader" x-on:scroll.window.passive="scrolled()" x-on:header-search-focus="searchHidden = false" data-site-header>
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

        {{--
            Below 900px the search is its own row, absolutely placed under the first row (a spacer after the
            header keeps its room at the top of the page), so hiding it on scroll down (plan 43 #5, siteHeader
            in app.js) moves no content; the first row stays sticky. From 900px it sits in the row.
            Plan 43 #40: suggestions (searchSuggest in app.js, ARIA combobox) from the same-origin JSON route
            in data-suggest-url; every text is rendered with x-text. The route check keeps the header safe
            outside localized routes.
        --}}
        @if (\Illuminate\Support\Facades\Route::has('site.search'))
            <div
                class="max-md:absolute max-md:inset-x-0 max-md:top-full max-md:-z-10 max-md:border-b max-md:border-line max-md:bg-white max-md:px-5 max-md:py-2 max-md:transition-[translate,opacity,visibility] max-md:duration-200 max-md:motion-reduce:transition-none md:flex-1"
                x-bind:class="searchHidden ? 'max-md:-translate-y-full max-md:opacity-0 max-md:invisible' : ''"
                data-header-search
            >
                <form
                    role="search"
                    method="get"
                    action="{{ route('site.search', ['locale' => app()->getLocale()]) }}"
                    class="relative"
                    x-data="searchSuggest"
                    x-on:click.outside="close()"
                    x-on:focusout="if (! $el.contains($event.relatedTarget)) close()"
                    x-on:focusin="$dispatch('header-search-focus')"
                    data-suggest-url="{{ route('site.search.suggest', ['locale' => app()->getLocale()]) }}"
                >
                    <label for="site-search" class="sr-only">{{ __('site.header.search_label') }}</label>
                    <x-site.icon name="search" class="pointer-events-none absolute top-[13px] left-3.5 text-ink-2" />
                    <input
                        id="site-search"
                        type="search"
                        name="q"
                        value="{{ request()->routeIs('site.search') && is_string(request()->query('q')) ? request()->query('q') : '' }}"
                        maxlength="100"
                        autocomplete="off"
                        role="combobox"
                        aria-autocomplete="list"
                        aria-controls="site-search-listbox"
                        aria-expanded="false"
                        x-bind:aria-expanded="open.toString()"
                        x-bind:aria-activedescendant="active >= 0 ? 'site-search-option-' + active : null"
                        x-on:input="typed($event.target.value)"
                        x-on:keydown="key($event)"
                        placeholder="{{ __('site.header.search_placeholder') }}"
                        class="h-[46px] w-full rounded-btn border-[1.5px] border-line bg-bg-soft pr-28 pl-11 text-ink transition-colors placeholder:text-ink-2 focus:border-brand-600 focus:bg-white sm:pr-32"
                    >
                    <button type="submit" class="absolute top-1 right-1 h-[38px] rounded-lg bg-ink px-4 font-semibold text-white hover:bg-ink-line sm:px-[18px]">
                        {{ __('site.header.search_button') }}
                    </button>

                    <div
                        id="site-search-listbox"
                        role="listbox"
                        aria-label="{{ __('site.header.suggest_label') }}"
                        class="absolute inset-x-0 top-full z-50 mt-1.5 max-h-[min(70vh,32rem)] overflow-y-auto rounded-box border border-line bg-white py-1.5 shadow-card"
                        x-show="open"
                        x-cloak
                        data-suggest-listbox
                    >
                        <template x-if="categories.length > 0">
                            <div role="group" aria-labelledby="site-search-group-categories">
                                <div id="site-search-group-categories" role="presentation" class="px-4 pt-2 pb-1 text-xs font-bold tracking-wide text-ink-muted uppercase">{{ __('site.header.suggest_categories') }}</div>
                                <template x-for="(item, index) in categories" x-bind:key="'c' + index">
                                    <div
                                        role="option"
                                        x-bind:id="'site-search-option-' + index"
                                        x-bind:aria-selected="(active === index).toString()"
                                        x-bind:class="active === index ? 'bg-bg-soft text-brand-600' : 'text-ink'"
                                        class="flex cursor-pointer items-center gap-2.5 px-4 py-2 text-sm"
                                        x-on:mousedown.prevent
                                        x-on:mouseenter="active = index"
                                        x-on:click="go(item.url)"
                                    >
                                        <x-site.icon name="grid" size="size-4" class="text-brand-600" />
                                        <span class="min-w-0 truncate">
                                            <template x-for="(part, partIndex) in parts(item.name)" x-bind:key="partIndex"><span x-bind:class="part.hit ? 'font-bold' : ''" x-text="part.text"></span></template>
                                        </span>
                                    </div>
                                </template>
                            </div>
                        </template>

                        <template x-if="products.length > 0">
                            <div role="group" aria-labelledby="site-search-group-products">
                                <div id="site-search-group-products" role="presentation" class="px-4 pt-2 pb-1 text-xs font-bold tracking-wide text-ink-muted uppercase">{{ __('site.header.suggest_products') }}</div>
                                <template x-for="(item, index) in products" x-bind:key="'p' + index">
                                    <div
                                        role="option"
                                        x-bind:id="'site-search-option-' + (categories.length + index)"
                                        x-bind:aria-selected="(active === categories.length + index).toString()"
                                        x-bind:class="active === categories.length + index ? 'bg-bg-soft' : ''"
                                        class="flex cursor-pointer items-center gap-3 px-4 py-2"
                                        x-on:mousedown.prevent
                                        x-on:mouseenter="active = categories.length + index"
                                        x-on:click="go(item.url)"
                                    >
                                        <span class="grid size-10 shrink-0 place-items-center overflow-hidden rounded-lg border border-line bg-bg-soft">
                                            <template x-if="item.image"><img x-bind:src="item.image" alt="" width="40" height="40" loading="lazy" class="size-full object-contain"></template>
                                        </span>
                                        <span class="grid min-w-0">
                                            <span class="truncate text-sm text-ink"><template x-for="(part, partIndex) in parts(item.name)" x-bind:key="partIndex"><span x-bind:class="part.hit ? 'font-bold' : ''" x-text="part.text"></span></template></span>
                                            <span class="truncate text-xs text-ink-muted" x-text="[item.brand, item.category].filter(Boolean).join(' · ')"></span>
                                        </span>
                                    </div>
                                </template>
                            </div>
                        </template>

                        <div
                            role="option"
                            x-bind:id="'site-search-option-' + (categories.length + products.length)"
                            x-bind:aria-selected="(active === categories.length + products.length).toString()"
                            x-bind:class="active === categories.length + products.length ? 'bg-bg-soft' : ''"
                            class="mt-1 cursor-pointer border-t border-line px-4 pt-2.5 pb-1.5 text-sm font-semibold text-brand-600"
                            x-on:mousedown.prevent
                            x-on:mouseenter="active = categories.length + products.length"
                            x-on:click="submit()"
                        >{{ __('site.header.suggest_all') }}</div>
                    </div>
                </form>
            </div>
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

        {{-- Plan 43 #6: a 44px call button in the first row below 1100px (the text phone starts at lg) (the topbar phone scrolls away); E.164 only. --}}
        @if ($company->phone() !== '')
            <a
                href="tel:{{ $company->phone() }}"
                class="grid size-11 shrink-0 place-items-center rounded-btn text-ink transition-colors hover:bg-bg-soft hover:text-brand-600 lg:hidden"
                aria-label="{{ __('site.header.call') }}"
                data-header-call
            >
                <x-site.icon name="phone" size="size-6" />
            </a>
        @endif

        {{-- Only on localized pages (the cart route needs the locale), and while the cart is on. --}}
        @if (config('site.cart_enabled') && isset(\Illuminate\Support\Facades\URL::getDefaultParameters()['locale']))
            <livewire:site.cart.counter />
        @endif

        <a
            href="{{ \App\Support\Site\Navigation::quote() }}"
            data-lead-modal="callback"
            class="hidden h-[46px] shrink-0 items-center justify-center rounded-btn border-[1.5px] border-brand-600 px-5 font-semibold whitespace-nowrap text-brand-600 transition-colors hover:bg-brand-50 md:inline-flex"
        >
            {{ __('site.header.request_quote') }}
        </a>
    </div>

    <x-site.nav />
</header>
{{-- Below 900px: the room of the search row under the sticky first row (it scrolls away with the page). --}}
@if (\Illuminate\Support\Facades\Route::has('site.search'))
    <div class="h-[63px] md:hidden" aria-hidden="true" data-header-search-spacer></div>
@endif
