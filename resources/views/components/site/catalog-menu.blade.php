{{--
    Header "Catalog" disclosure (x-site.header). The trigger is a link to the
    catalog index, so without JS it still opens the catalog; Alpine turns it
    into a button that toggles the panel (click, Enter or Space).
    Panel: "Show all", then the visible top-level categories (CatalogMenu,
    from the cached CategoryTree). From 900px a category with subcategories
    shows them in a second column on hover or focus; below 900px the panel is
    full width under the header and lists the top-level categories only.
    Hovering switches the open column after HOVER_DELAY ms (hover intent, so
    a diagonal move to the submenu doesn't switch it); keyboard focus switches
    at once. While a column is open the panel is at least as tall as it
    (capped at the viewport), so the submenu doesn't scroll in a small box.
    Closes on Esc (window listener, focus returns to the trigger), click
    outside, focus moving out of the menu, and focus leaving the document.
    A mouse click inside, where some browsers report no focus target, is left
    to click.outside. The <!-- catalog-menu:* --> comments mark the panel for tests.
--}}
@php
    $items = \App\Support\Site\CatalogMenu::items();
    $trail = \App\Support\Site\CatalogMenu::currentTrail();
    $currentRoot = collect($items)->first(fn (array $item): bool => in_array($item['id'], $trail, true));
    // Second column height: 2.25rem per link (text-sm + py-2) plus the list's py-2.
    $columnHeights = (object) collect($items)
        ->filter(fn (array $item): bool => $item['children'] !== [])
        ->mapWithKeys(fn (array $item): array => [$item['id'] => (count($item['children']) * 2.25 + 1).'rem'])
        ->all();
@endphp

<div
    class="ml-auto md:relative md:ml-0"
    x-data="{
        open: false,
        active: @js($currentRoot !== null && $currentRoot['children'] !== [] ? $currentRoot['id'] : null),
        heights: @js($columnHeights),
        hoverTimer: null,
        toggle() { this.open ? this.close() : (this.open = true) },
        close(focusTrigger = false) {
            if (! this.open) return
            clearTimeout(this.hoverTimer)
            this.open = false
            if (focusTrigger) this.$refs.trigger.focus()
        },
        hover(id) {
            clearTimeout(this.hoverTimer)
            this.hoverTimer = setTimeout(() => this.active = id, 150)
        },
        focusItem(id) {
            clearTimeout(this.hoverTimer)
            this.active = id
        },
        focusLeft(event) {
            if (event.relatedTarget !== null) {
                if (! this.$el.contains(event.relatedTarget)) this.close()
                return
            }
            this.$nextTick(() => { if (! document.hasFocus()) this.close() })
        },
    }"
    x-on:keydown.escape.window="close(true)"
    x-on:click.outside="close()"
    x-on:focusout="focusLeft($event)"
>
    <a
        href="{{ \App\Support\Site\Navigation::catalog() }}"
        x-ref="trigger"
        x-bind:role="'button'"
        x-bind:aria-controls="'site-catalog-menu'"
        x-bind:aria-expanded="open.toString()"
        x-on:click.prevent="toggle()"
        x-on:keydown.space.prevent="toggle()"
        class="inline-flex h-11 items-center gap-2.5 rounded-btn bg-brand-600 px-3 font-semibold text-white transition-colors hover:bg-brand-700 aria-expanded:bg-brand-700 md:h-[46px] md:px-[18px]"
    >
        <x-site.icon name="grid" />
        <span class="sr-only md:not-sr-only">{{ __('site.header.catalog') }}</span>
        <svg class="size-4 shrink-0 fill-none stroke-current transition-transform motion-reduce:transition-none" x-bind:class="open ? 'rotate-180' : ''" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m6 9 6 6 6-6" /></svg>
    </a>

    <!-- catalog-menu:start -->
    <div
        id="site-catalog-menu"
        x-cloak
        x-show="open"
        x-transition.opacity.duration.150ms
        x-bind:class="active !== null ? 'md:w-[36rem] md:min-h-[min(var(--catalog-column-h),calc(100dvh-9rem))]' : 'md:w-72'"
        x-bind:style="{ '--catalog-column-h': heights[active] ?? '0rem' }"
        class="absolute inset-x-0 top-full z-50 border-b border-line bg-white shadow-card md:inset-x-auto md:left-0 md:mt-2 md:rounded-box md:border"
    >
        <ul class="max-h-[calc(100dvh-9rem)] overflow-y-auto py-2 md:w-72">
            <li class="mb-2 border-b border-line px-2 pb-2">
                <a
                    href="{{ \App\Support\Site\Navigation::catalog() }}"
                    x-on:mouseenter="hover(null)"
                    x-on:focus="focusItem(null)"
                    @if (request()->routeIs('site.catalog.index')) aria-current="page" @endif
                    class="flex items-center justify-between gap-3 rounded-lg px-3 py-2.5 font-semibold text-brand-600 hover:bg-brand-50 focus-visible:bg-brand-50"
                >
                    {{ __('site.header.show_all') }}
                    <x-site.icon name="arrow" size="size-4" />
                </a>
            </li>
            @foreach ($items as $item)
                @php($inTrail = in_array($item['id'], $trail, true))
                <li class="px-2">
                    <a
                        href="{{ $item['url'] }}"
                        x-on:mouseenter="hover(@js($item['children'] !== [] ? $item['id'] : null))"
                        x-on:focus="focusItem(@js($item['children'] !== [] ? $item['id'] : null))"
                        @if ($trail !== [] && $trail[0] === $item['id']) aria-current="page" @endif
                        @class([
                            'flex items-center gap-3 rounded-lg px-3 py-2 font-medium transition-colors hover:bg-bg-soft hover:text-brand-600 focus-visible:bg-bg-soft',
                            'text-brand-600' => $inTrail,
                            'text-ink' => ! $inTrail,
                        ])
                        @if ($item['children'] !== []) x-bind:class="active === {{ $item['id'] }} ? 'md:bg-bg-soft md:text-brand-600' : ''" @endif
                    >
                        <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-bg-tint text-brand-600">
                            <x-site.icon :name="$item['icon']" />
                        </span>
                        <span class="flex-1">{{ $item['label'] }}</span>
                        @if ($item['children'] !== [])
                            <svg class="hidden size-4 shrink-0 fill-none stroke-current text-ink-3 md:block" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m9 6 6 6-6 6" /></svg>
                        @endif
                    </a>
                    @if ($item['children'] !== [])
                        {{-- Desktop: rendered in the second column below; kept next to the parent for screen readers and the tab order. --}}
                        <ul
                            x-cloak
                            x-show="active === {{ $item['id'] }}"
                            x-on:mouseenter="clearTimeout(hoverTimer)"
                            aria-label="{{ $item['label'] }}"
                            class="hidden md:absolute md:top-0 md:bottom-0 md:left-72 md:block md:w-72 md:overflow-y-auto md:border-l md:border-line md:py-2"
                        >
                            @foreach ($item['children'] as $child)
                                <li class="px-2">
                                    <a
                                        href="{{ $child['url'] }}"
                                        @if ($trail !== [] && $trail[0] === $child['id']) aria-current="page" @endif
                                        @class([
                                            'flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm transition-colors hover:bg-bg-soft hover:text-brand-600 focus-visible:bg-bg-soft',
                                            'font-semibold text-brand-600' => in_array($child['id'], $trail, true),
                                            'text-ink-2' => ! in_array($child['id'], $trail, true),
                                        ])
                                    >
                                        <x-site.icon :name="$child['icon']" size="size-[18px]" class="text-brand-600" />
                                        <span class="flex-1">{{ $child['label'] }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
    <!-- catalog-menu:end -->
</div>
