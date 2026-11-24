@props(['item', 'badge' => null])

{{--
    One admin menu entry from App\Support\Admin\Menu (sidebar + drawer).
    Available: a wire:navigate link with an active state.
    Soon (route not registered yet): a non-focusable <span aria-disabled="true">
    with a muted "Soon" badge, so keyboard users skip it.
    badge: optional counter (e.g. new leads, Menu::badges()).
--}}
@php
    /** @var \App\Support\Admin\MenuItem $item */
    $available = $item->isAvailable();
    $active = $item->isActive();
    $current = $available && request()->routeIs($item->activePattern());
@endphp
<li data-menu-item="{{ $item->key }}">
    <div x-bind:title="collapsed ? @js(__($item->label)) : null">
    @if ($available)
        <a
            href="{{ $item->url() }}"
            wire:navigate
            @if ($current) aria-current="page" @endif
            @class([
                'group relative flex h-10 items-center gap-3 rounded-lg px-3 text-sm font-medium rail:lg:justify-center rail:lg:px-0',
                'bg-white/[.07] text-white' => $active,
                'text-slate-300 hover:bg-white/5 hover:text-white' => ! $active,
            ])
        >
            @if ($active)<span class="absolute inset-y-1.5 left-0 w-0.5 rounded-full bg-brand-400" aria-hidden="true"></span>@endif
            <x-admin.icon :name="$item->icon" @class(['text-brand-300' => $active, 'text-slate-400 group-hover:text-slate-200' => ! $active]) />
            <span class="truncate rail:lg:sr-only">{{ __($item->label) }}</span>
            @if ($badge)
                <span class="ml-auto shrink-0 rounded-full bg-brand-600 px-1.5 rail:lg:absolute rail:lg:right-1 rail:lg:top-0.5 rail:lg:ml-0 rail:lg:px-1 py-0.5 text-[0.6875rem] font-bold tabular-nums leading-none text-white" data-menu-badge>{{ $badge > 99 ? '99+' : $badge }}<span class="sr-only"> {{ trans_choice('menu.badge_new', $badge, ['count' => $badge]) }}</span></span>
            @endif
        </a>
    @else
        <span
            aria-disabled="true"
            title="{{ __('menu.soon_hint') }}"
            data-soon
            class="flex h-10 cursor-default select-none items-center gap-3 rounded-lg px-3 text-sm font-medium text-slate-400 rail:lg:justify-center rail:lg:px-0"
        >
            <x-admin.icon :name="$item->icon" class="text-slate-600" />
            <span class="truncate rail:lg:sr-only">{{ __($item->label) }}</span>
            <span class="ml-auto shrink-0 rounded-full bg-white/[.06] px-1.5 py-0.5 text-xs font-semibold uppercase tracking-wide text-slate-400 rail:lg:hidden">{{ __('menu.soon') }}</span>
        </span>
    @endif
    </div>

    @if ($item->children !== [])
        <ul class="ml-5 mt-0.5 space-y-0.5 border-l border-white/10 pl-2 rail:lg:ml-0 rail:lg:border-l-0 rail:lg:pl-0">
            @foreach ($item->children as $child)
                <x-admin.menu-item :item="$child" />
            @endforeach
        </ul>
    @endif
</li>
