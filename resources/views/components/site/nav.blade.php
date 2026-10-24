{{--
    Main nav (900px and up), Navigation::main(): Brands, B2B, Services (accent),
    Warranty & service, Delivery & payment, News, About, Contacts; categories are in
    the header Catalog dropdown. Below 900px the same items live in x-site.mobile-menu.
    When the row overflows (long ru labels at 900–1100px) it scrolls horizontally
    and the right edge fades until the end is reached.
--}}
<nav class="hidden border-t border-line md:block" aria-label="{{ __('site.nav.aria_label') }}">
    <ul
        class="site-container flex h-12 items-center gap-7 overflow-x-auto [scrollbar-width:none]"
        x-data="{ atEnd: true, check() { this.atEnd = this.$el.scrollLeft + this.$el.clientWidth >= this.$el.scrollWidth - 1 } }"
        x-init="check()"
        x-on:scroll.passive="check()"
        x-on:resize.window.debounce.100ms="check()"
        x-bind:class="atEnd ? '' : 'mask-fade-r'"
    >
        @foreach (\App\Support\Site\Navigation::main() as $item)
            <li class="shrink-0">
                <a
                    href="{{ $item['url'] }}"
                    data-nav="{{ $item['key'] }}"
                    @if ($item['current'] !== null) aria-current="{{ $item['current'] }}" @endif
                    @class([
                        'text-sm whitespace-nowrap transition-colors hover:text-brand-600',
                        'font-medium' => ! $item['accent'],
                        'font-semibold text-brand-600' => $item['accent'],
                        'text-brand-600' => ! $item['accent'] && $item['current'] !== null,
                        'text-ink-2' => ! $item['accent'] && $item['current'] === null,
                    ])
                >{{ $item['label'] }}</a>
            </li>
        @endforeach
    </ul>
</nav>
