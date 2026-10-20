{{--
    Main nav (900px and up). Below 900px the same items live in x-site.mobile-menu.
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
            <li @class(['ml-auto' => $item['accent']])>
                <a
                    href="{{ $item['url'] }}"
                    @if (url()->current() === $item['url']) aria-current="page" @endif
                    @class([
                        'text-sm whitespace-nowrap transition-colors hover:text-brand-600 aria-[current=page]:text-brand-600',
                        'font-medium text-ink-2' => ! $item['accent'],
                        'font-semibold text-brand-600' => $item['accent'],
                    ])
                >{{ $item['label'] }}</a>
            </li>
        @endforeach
    </ul>
</nav>
