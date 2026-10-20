{{--
    Mobile drawer (below 900px). State: "menuOpen" from the x-data on <body>.
    Focus is trapped while open (x-trap, page made inert, scroll locked);
    Esc, the close button, the backdrop and any link close it.
--}}
@inject('company', \App\Support\CompanySettings::class)

<div
    x-cloak
    x-show="menuOpen"
    x-transition.opacity.duration.200ms
    class="fixed inset-0 z-50 md:hidden"
>
    <div class="absolute inset-0 bg-ink/50" aria-hidden="true" x-on:click="menuOpen = false"></div>

    <div
        id="site-drawer"
        role="dialog"
        aria-modal="true"
        aria-labelledby="site-drawer-title"
        x-trap.inert.noscroll="menuOpen"
        class="absolute inset-y-0 left-0 flex w-[min(22rem,88vw)] flex-col gap-6 overflow-y-auto bg-white px-5 pt-4 pb-8 shadow-card"
    >
        <div class="flex items-center justify-between">
            <h2 id="site-drawer-title" class="text-lg font-bold">{{ __('site.header.menu_title') }}</h2>
            <button
                type="button"
                class="-mr-2 grid size-11 place-items-center rounded-btn text-ink hover:bg-bg-soft"
                x-on:click="menuOpen = false"
            >
                <x-site.icon name="close" size="size-6" />
                <span class="sr-only">{{ __('site.header.menu_close') }}</span>
            </button>
        </div>

        <nav aria-label="{{ __('site.nav.aria_label') }}">
            <ul class="divide-y divide-line border-y border-line">
                @foreach (\App\Support\Site\Navigation::main() as $item)
                    <li>
                        <a
                            href="{{ $item['url'] }}"
                            x-on:click="menuOpen = false"
                            @class([
                                'flex items-center justify-between py-3 font-medium hover:text-brand-600',
                                'text-ink' => ! $item['accent'],
                                'text-brand-600' => $item['accent'],
                            ])
                        >
                            {{ $item['label'] }}
                            <x-site.icon name="arrow" size="size-4" class="text-ink-3" />
                        </a>
                    </li>
                @endforeach
                <li>
                    <a href="{{ route('site.home', ['locale' => app()->getLocale()]) }}#contacts" x-on:click="menuOpen = false" class="flex items-center justify-between py-3 font-medium text-ink hover:text-brand-600">
                        {{ __('site.nav.contacts') }}
                        <x-site.icon name="arrow" size="size-4" class="text-ink-3" />
                    </a>
                </li>
            </ul>
        </nav>

        <a
            href="{{ \App\Support\Site\Navigation::quote() }}"
            x-on:click="menuOpen = false"
            class="inline-flex h-[46px] items-center justify-center rounded-btn bg-brand-600 px-5 font-semibold text-white hover:bg-brand-700"
        >
            {{ __('site.header.request_quote') }}
        </a>

        <ul class="grid gap-3 text-sm text-ink-2">
            <li>
                <a href="tel:{{ $company->phone() }}" class="flex items-center gap-3 font-semibold text-ink hover:text-brand-600">
                    <x-site.icon name="phone" class="text-brand-600" />
                    {{ $company->phoneDisplay() }}
                </a>
            </li>
            @if ($company->telegramUrl() !== '')
                <li>
                    <a href="{{ $company->telegramUrl() }}" target="_blank" rel="noopener" class="flex items-center gap-3 hover:text-brand-600">
                        <x-site.icon name="send" class="text-brand-600" />
                        {{ __('site.topbar.telegram') }}
                    </a>
                </li>
            @endif
            @if ($company->email() !== '')
                <li>
                    <a href="mailto:{{ $company->email() }}" class="flex items-center gap-3 hover:text-brand-600">
                        <x-site.icon name="mail" class="text-brand-600" />
                        {{ $company->email() }}
                    </a>
                </li>
            @endif
            <li class="flex items-center gap-3">
                <x-site.icon name="clock" class="text-brand-600" />
                {{ $company->workingHours() }}
            </li>
        </ul>

        <x-site.language-switcher variant="light" class="self-start" />
    </div>
</div>
