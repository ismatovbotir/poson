@props(['title' => null])

{{--
    Admin layout (layouts::admin), ported from .claude/html-templates/admin/index.html.
    Every admin Livewire page declares #[Layout('layouts::admin')] and #[Title(...)].
    ≥ lg: fixed dark sidebar. < lg: top bar + bottom tab bar + sidebar as a drawer.
    Closed drawer (< lg) is "invisible" (visibility transitions after the slide), so it can't take focus.
    Menu (sidebar, drawer, bottom tab bar) comes from App\Support\Admin\Menu: the full
    structure filtered by permission/role. Items whose route doesn't exist yet show "Soon".
--}}
@php
    /** @var \App\Models\User $user */
    $user = auth()->user();
    $role = $user->staffRole();

    $menu = \App\Support\Admin\Menu::for($user);
    $badges = \App\Support\Admin\Menu::badges($user);
    $activeGroup = collect($menu)->first(fn (\App\Support\Admin\MenuGroup $group): bool => $group->isActive())?->key;

    // Bottom tab bar: Home, Leads, Catalog (first available catalog screen), Orders + Menu.
    $catalogGroup = \App\Support\Admin\Menu::group($menu, 'catalog');
    $tabs = array_filter([
        ['key' => 'home', 'label' => 'menu.tabs.home', 'icon' => 'home', 'item' => \App\Support\Admin\Menu::find($menu, 'dashboard')],
        ['key' => 'leads', 'label' => 'menu.tabs.leads', 'icon' => 'inbox', 'item' => \App\Support\Admin\Menu::find($menu, 'leads')],
        ['key' => 'catalog', 'label' => 'menu.tabs.catalog', 'icon' => 'box', 'group' => $catalogGroup, 'item' => $catalogGroup
            ? (collect($catalogGroup->items)->first(fn (\App\Support\Admin\MenuItem $item): bool => $item->isAvailable()) ?? $catalogGroup->items[0])
            : null],
        ['key' => 'orders', 'label' => 'menu.tabs.orders', 'icon' => 'bag', 'item' => \App\Support\Admin\Menu::find($menu, 'orders')],
    ], fn (array $tab): bool => $tab['item'] !== null);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0f172a">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ? $title.' · ' : '' }}{{ __('admin.meta.app_name') }}</title>

    @fonts
    @vite(['resources/css/admin.css', 'resources/js/admin.js'])
    @livewireStyles
</head>
<body
    class="h-full font-sans"
    x-data="{ nav: false }"
    x-on:keydown.escape.window="nav = false"
    x-on:livewire:navigated.window="nav = false"
>
    <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-[80] focus:rounded-lg focus:bg-white focus:px-4 focus:py-2 focus:shadow">{{ __('admin.a11y.skip_to_content') }}</a>

    <x-admin.icon-sprite />

    {{-- Sidebar: fixed on desktop, drawer below lg --}}
    <div x-cloak x-show="nav" x-transition.opacity class="fixed inset-0 z-40 bg-slate-900/60 backdrop-blur-[2px] lg:hidden" x-on:click="nav = false"></div>
    <aside
        id="admin-sidebar"
        x-trap.inert.noscroll="nav"
        x-bind:class="nav ? 'translate-x-0! visible!' : ''"
        class="invisible fixed inset-y-0 left-0 z-50 flex w-[18rem] max-w-[85vw] -translate-x-full flex-col bg-slate-900 transition-[translate,transform,visibility] duration-200 ease-out lg:visible lg:translate-x-0"
        aria-label="{{ __('admin.a11y.main_menu') }}"
    >
        <div class="flex h-16 shrink-0 items-center gap-2.5 px-5">
            <a href="{{ route('admin.dashboard') }}" wire:navigate class="flex items-center gap-2.5" aria-label="{{ __('admin.a11y.home') }}">
                <x-admin.logo />
                <span class="text-lg font-extrabold tracking-tight text-white">poson<span class="text-brand-400">.uz</span></span>
            </a>
            <span class="rounded-md bg-white/10 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-slate-300">{{ __('admin.meta.badge') }}</span>
            <button type="button" class="ml-auto grid size-9 place-items-center rounded-lg text-slate-400 hover:bg-white/10 hover:text-white lg:hidden" x-on:click="nav = false" aria-label="{{ __('admin.a11y.close_menu') }}"><x-admin.icon name="x" /></button>
        </div>

        <nav class="scrollbar-none flex-1 overflow-y-auto px-3 pb-4 pt-2" x-data="adminMenu(@js($activeGroup))">
            @foreach ($menu as $group)
                <div class="mt-4 first:mt-0" data-menu-group="{{ $group->key }}">
                    @if ($group->collapsible)
                        <button
                            type="button"
                            x-on:click="toggle(@js($group->key))"
                            aria-expanded="true"
                            x-bind:aria-expanded="isOpen(@js($group->key)).toString()"
                            aria-controls="menu-group-{{ $group->key }}"
                            class="flex w-full items-center gap-2 rounded-md px-3 py-1.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500 hover:text-slate-300 focus-visible:outline-2 focus-visible:outline-brand-400"
                        >
                            <span class="flex-1 truncate">{{ __($group->label) }}</span>
                            <x-admin.icon name="chevron-down" size="size-3.5" class="shrink-0 transition-transform duration-150" x-bind:class="isOpen(@js($group->key)) ? '' : '-rotate-90'" />
                        </button>
                    @endif
                    <ul
                        id="menu-group-{{ $group->key }}"
                        class="space-y-0.5"
                        @if ($group->collapsible) x-cloak x-show="isOpen(@js($group->key))" x-collapse @endif
                    >
                        @foreach ($group->items as $item)
                            <x-admin.menu-item :item="$item" :badge="$badges[$item->key] ?? null" />
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </nav>

        <div class="border-t border-white/10 p-3">
            <div class="flex items-center gap-3 rounded-lg p-2">
                <span class="grid size-9 shrink-0 place-items-center rounded-full bg-brand-600 text-sm font-semibold text-white" aria-hidden="true">{{ $user->initials() }}</span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-semibold text-white">{{ $user->name }}</span>
                    <span class="block truncate text-xs text-slate-400">{{ $role?->label() }}</span>
                </span>
            </div>
        </div>
    </aside>

    {{-- Main column --}}
    <div class="min-h-full lg:pl-[18rem]">
        <header class="sticky top-0 z-30 flex h-16 items-center gap-1.5 border-b border-slate-200 bg-white/85 px-3 backdrop-blur-md sm:gap-3 sm:px-6 lg:px-8">
            <button type="button" class="grid size-10 place-items-center rounded-lg text-slate-600 hover:bg-slate-100 lg:hidden" x-on:click="nav = true" aria-controls="admin-sidebar" x-bind:aria-expanded="nav.toString()" aria-label="{{ __('admin.a11y.open_menu') }}"><x-admin.icon name="menu" size="size-6" /></button>
            <a href="{{ route('admin.dashboard') }}" wire:navigate class="mr-1 lg:hidden" aria-label="{{ __('admin.a11y.home') }}"><x-admin.logo /></a>

            <div class="ml-auto flex items-center gap-0.5 sm:gap-1">
                <a href="{{ route('site.home', ['locale' => app()->getLocale()]) }}" target="_blank" rel="noopener" class="hidden h-9 items-center gap-1.5 rounded-lg px-3 text-sm font-medium text-slate-600 hover:bg-slate-100 xl:flex"><x-admin.icon name="external" size="size-4" />{{ __('admin.topbar.open_site') }}</a>

                <x-admin.locale-switch />

                {{-- User menu --}}
                <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
                    <button type="button" x-on:click="open = !open" x-bind:aria-expanded="open.toString()" aria-haspopup="true" class="flex items-center gap-2 rounded-full p-1 hover:bg-slate-100 sm:rounded-lg sm:pr-2" aria-label="{{ __('admin.topbar.user_menu') }}">
                        <span class="grid size-8 place-items-center rounded-full bg-brand-600 text-xs font-semibold text-white" aria-hidden="true">{{ $user->initials() }}</span>
                        <x-admin.icon name="chevron-down" size="size-4" class="hidden text-slate-400 sm:block" />
                    </button>
                    <div x-cloak x-show="open" x-transition.origin.top.right class="absolute right-0 mt-2 w-64 rounded-xl border border-slate-200 bg-white p-1.5 shadow-xl shadow-slate-900/10">
                        <div class="px-3 py-2.5">
                            <p class="truncate text-sm font-semibold">{{ $user->name }}</p>
                            <p class="truncate text-xs text-slate-500">{{ $user->email }}</p>
                            @if ($role)
                                <x-admin.badge :color="$role->color()" class="mt-2 text-[11px]">{{ $role->label() }}</x-admin.badge>
                            @endif
                        </div>
                        <div class="my-1 h-px bg-slate-100"></div>
                        <form method="POST" action="{{ route('admin.logout') }}">
                            @csrf
                            <button type="submit" class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium text-danger hover:bg-red-50"><x-admin.icon name="logout" size="size-4" />{{ __('admin.topbar.logout') }}</button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <main id="main" tabindex="-1" class="mx-auto w-full max-w-[1600px] px-4 pt-5 pb-28 focus:outline-none sm:px-6 sm:pt-6 lg:px-8 lg:pb-12">
            {{ $slot }}
        </main>
    </div>

    {{-- Bottom tab bar (below lg) --}}
    <nav class="fixed inset-x-0 bottom-0 z-30 flex border-t border-slate-200 bg-white/95 pb-[env(safe-area-inset-bottom)] backdrop-blur-md lg:hidden" aria-label="{{ __('admin.a11y.quick_menu') }}">
        @foreach ($tabs as $tab)
            @php($tabItem = $tab['item'])
            @if ($tabItem->isAvailable())
                @php($tabActive = isset($tab['group']) ? $tab['group']->isActive() : $tabItem->isActive())
                @php($tabBadge = $badges[$tab['key']] ?? null)
                <a href="{{ $tabItem->url() }}" wire:navigate data-tab="{{ $tab['key'] }}" @if ($tabActive) aria-current="page" @endif @class(['flex min-w-0 flex-1 flex-col items-center gap-1 py-2 text-[11px] font-medium', 'text-brand-600' => $tabActive, 'text-slate-500' => ! $tabActive])><span class="relative"><x-admin.icon :name="$tab['icon']" size="size-6" />@if ($tabBadge)<span class="absolute -right-2.5 -top-1.5 min-w-4 rounded-full bg-danger px-1 text-center text-[10px] font-bold leading-4 text-white ring-2 ring-white" data-tab-badge>{{ $tabBadge > 99 ? '99+' : $tabBadge }}</span>@endif</span><span class="max-w-full truncate px-0.5">{{ __($tab['label']) }}@if ($tabBadge)<span class="sr-only"> ({{ trans_choice('menu.badge_new', $tabBadge, ['count' => $tabBadge]) }})</span>@endif</span></a>
            @else
                <span aria-disabled="true" title="{{ __('menu.soon_hint') }}" data-tab="{{ $tab['key'] }}" data-soon class="flex min-w-0 flex-1 cursor-default select-none flex-col items-center gap-1 py-2 text-[11px] font-medium text-slate-400">
                    <span class="relative"><x-admin.icon :name="$tab['icon']" size="size-6" /><span class="absolute -right-1 -top-0.5 size-2 rounded-full bg-slate-400 ring-2 ring-white" aria-hidden="true"></span></span>
                    <span class="max-w-full truncate px-0.5">{{ __($tab['label']) }}<span class="sr-only"> ({{ __('menu.soon') }})</span></span>
                </span>
            @endif
        @endforeach
        <button type="button" x-on:click="nav = true" aria-controls="admin-sidebar" x-bind:aria-expanded="nav.toString()" data-tab="menu" class="flex min-w-0 flex-1 flex-col items-center gap-1 py-2 text-[11px] font-medium text-slate-500"><x-admin.icon name="menu" size="size-6" /><span class="max-w-full truncate px-0.5">{{ __('menu.tabs.menu') }}</span></button>
    </nav>

    <x-admin.toast-stack />
    <x-admin.confirm />

    @livewireScripts
</body>
</html>
