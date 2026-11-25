// Public site bundle. Analytics only acts when the server printed its meta tag (resources/js/analytics.js).
import './analytics';
// Plan 40 (YD-2): the checkout's order_placed navigation, registered whatever the analytics config.
import './order-placed';
import './livewire-throttle';
import './web-chat';
// Plan 42: the quote request modal (Site\LeadModal) and its data-lead-modal triggers.
import './lead-modal';

/**
 * Plan 43 #47: product page tabs (livewire/site/catalog/show.blade.php), modelled on
 * the admin productTabs. ARIA tabs keyboard pattern: Left/Right wrap, Home/End;
 * focus moves to the tab and activates it. The keys are read from the server-rendered
 * data-tab attributes, so no data is passed into the expression.
 */
document.addEventListener('livewire:init', () => {
    window.Alpine.data('siteTabs', () => ({
        tab: 'description',
        keys: [],

        init() {
            this.keys = this.readKeys();
            this.tab = this.keys[0] ?? this.tab;
        },

        /** Tab keys from the rendered [data-tab] buttons (re-read: a Livewire re-render may add or remove tabs). */
        readKeys() {
            return Array.from(this.$el.querySelectorAll('[role="tab"][data-tab]'), (button) => button.dataset.tab);
        },

        show(key) {
            this.keys = this.readKeys();

            if (this.keys.includes(key)) {
                this.tab = key;
            }
        },

        key(event) {
            this.keys = this.readKeys();
            const last = this.keys.length - 1;
            const current = this.keys.indexOf(this.tab);
            const target = {
                ArrowRight: current === last ? 0 : current + 1,
                ArrowLeft: current <= 0 ? last : current - 1,
                Home: 0,
                End: last,
            }[event.key];

            if (target === undefined || last < 0) {
                return;
            }

            event.preventDefault();
            this.show(this.keys[target]);
            this.$nextTick(() => document.getElementById(`tab-${this.keys[target]}`)?.focus());
        },
    }));
});

/**
 * Plan 43 #5: below 900px the header's search row (x-site.header, data-header-search) hides
 * while the visitor scrolls down and comes back on scroll up; the first row stays sticky.
 * The row is absolutely placed under the first row, so hiding it moves no content. Small
 * moves (under SCROLL_DELTA px) and the top of the page never hide it; focus inside the search
 * shows it. prefers-reduced-motion: the CSS drops the transition (motion-reduce:transition-none).
 */
const HEADER_DESKTOP = '(min-width: 56.25rem)';
const SCROLL_DELTA = 12;
const SCROLL_TOP_ZONE = 80;

document.addEventListener('livewire:init', () => {
    window.Alpine.data('siteHeader', () => ({
        searchHidden: false,
        lastY: 0,
        ticking: false,

        init() {
            this.lastY = Math.max(0, window.scrollY);
        },

        scrolled() {
            if (this.ticking) {
                return;
            }

            this.ticking = true;
            window.requestAnimationFrame(() => {
                this.ticking = false;
                this.update();
            });
        },

        update() {
            const y = Math.max(0, window.scrollY);
            const search = this.$el.querySelector('[data-header-search]');

            if (window.matchMedia(HEADER_DESKTOP).matches || y <= SCROLL_TOP_ZONE || search?.contains(document.activeElement)) {
                this.searchHidden = false;
                this.lastY = y;

                return;
            }

            if (Math.abs(y - this.lastY) < SCROLL_DELTA) {
                return;
            }

            this.searchHidden = y > this.lastY;
            this.lastY = y;
        },
    }));
});

/**
 * Plan 43 #40 (UQ2-5, UQ2-6): header search suggestions as an ARIA combobox with a listbox.
 * - After 2 characters (spaces not counted), a 300 ms debounce, then GET the same-origin URL
 *   from data-suggest-url (a URL on another origin is refused); the previous request is
 *   aborted (AbortController). Any error or a 429 just closes the list quietly.
 * - The JSON is rendered only through x-text and attribute bindings (no HTML directives or HTML string sinks);
 *   the match highlight splits the name into text parts.
 * - Keys: Down / Up move the active option (wrapping), Enter opens it (or submits the form when
 *   none is active), Esc closes the list. The last option "Show all results" submits the form.
 */
const SUGGEST_MIN_LENGTH = 2;
const SUGGEST_DEBOUNCE_MS = 300;

const sameOrigin = (value) => {
    try {
        const url = new URL(value, window.location.origin);

        return url.origin === window.location.origin ? url : null;
    } catch {
        return null;
    }
};

document.addEventListener('livewire:init', () => {
    window.Alpine.data('searchSuggest', () => ({
        open: false,
        active: -1,
        term: '',
        categories: [],
        products: [],
        timer: null,
        controller: null,

        destroy() {
            this.abort();
            clearTimeout(this.timer);
        },

        get count() {
            return this.categories.length + this.products.length + 1;
        },

        typed(value) {
            this.term = String(value ?? '').trim();
            clearTimeout(this.timer);

            if (this.term.replace(/\s+/g, '').length < SUGGEST_MIN_LENGTH) {
                this.abort();
                this.close();

                return;
            }

            this.timer = setTimeout(() => this.fetch(this.term), SUGGEST_DEBOUNCE_MS);
        },

        abort() {
            this.controller?.abort();
            this.controller = null;
        },

        async fetch(term) {
            const url = sameOrigin(this.$el.dataset.suggestUrl ?? '');

            if (url === null) {
                return;
            }

            this.abort();
            const controller = new AbortController();
            this.controller = controller;
            url.searchParams.set('q', term);

            try {
                const response = await window.fetch(url.toString(), {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    signal: controller.signal,
                });

                if (!response.ok) {
                    this.close();

                    return;
                }

                const data = await response.json();

                // A newer request (or a cleared field) wins.
                if (this.controller !== controller || this.term !== term) {
                    return;
                }

                this.categories = this.clean(data?.categories);
                this.products = this.clean(data?.products);
                this.active = -1;
                // Only while the visitor is still in the field.
                this.open = this.categories.length + this.products.length > 0 && this.$el.contains(document.activeElement) && document.activeElement?.id === 'site-search';
            } catch {
                if (!controller.signal.aborted) {
                    this.close();
                }
            }
        },

        /** Plain objects with a same-origin URL only; every value as a string. */
        clean(items) {
            if (!Array.isArray(items)) {
                return [];
            }

            return items
                .filter((item) => item && typeof item.name === 'string' && typeof item.url === 'string' && sameOrigin(item.url) !== null)
                .slice(0, 5)
                .map((item) => ({
                    name: item.name,
                    url: item.url,
                    brand: typeof item.brand === 'string' ? item.brand : '',
                    category: typeof item.category === 'string' ? item.category : '',
                    image: this.safeImage(item.image),
                }));
        },

        /** An image URL on this origin or on https:, else none. */
        safeImage(value) {
            if (typeof value !== 'string' || value === '') {
                return '';
            }

            try {
                const url = new URL(value, window.location.origin);

                return url.origin === window.location.origin || url.protocol === 'https:' ? url.toString() : '';
            } catch {
                return '';
            }
        },

        /** The name as text parts; the parts matching a word of the term are marked. */
        parts(name) {
            const words = this.term.toLowerCase().split(/\s+/).filter((word) => word.length > 0);
            const lower = name.toLowerCase();
            const hits = new Array(name.length).fill(false);

            // Only when lower-casing keeps the length (index-safe).
            if (lower.length === name.length) {
                for (const word of words) {
                    let from = lower.indexOf(word);

                    while (from !== -1) {
                        hits.fill(true, from, from + word.length);
                        from = lower.indexOf(word, from + word.length);
                    }
                }
            }

            const parts = [];

            for (let index = 0; index < name.length; index++) {
                const last = parts[parts.length - 1];

                if (last && last.hit === hits[index]) {
                    last.text += name[index];
                } else {
                    parts.push({ text: name[index], hit: hits[index] });
                }
            }

            return parts;
        },

        close() {
            this.abort();
            clearTimeout(this.timer);
            this.open = false;
            this.active = -1;
        },

        go(value) {
            const url = sameOrigin(value);

            if (url !== null) {
                window.location.assign(url.toString());
            }
        },

        submit() {
            this.close();
            this.$el.requestSubmit ? this.$el.requestSubmit() : this.$el.submit();
        },

        choose() {
            if (this.active < this.categories.length) {
                this.go(this.categories[this.active].url);
            } else if (this.active < this.categories.length + this.products.length) {
                this.go(this.products[this.active - this.categories.length].url);
            } else {
                this.submit();
            }
        },

        key(event) {
            if (event.key === 'Escape') {
                if (this.open) {
                    event.preventDefault();
                    this.close();
                }

                return;
            }

            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                if (!this.open) {
                    if (this.categories.length + this.products.length === 0) {
                        return;
                    }

                    this.open = true;
                }

                event.preventDefault();
                const step = event.key === 'ArrowDown' ? 1 : -1;
                this.active = this.active < 0 && step < 0 ? this.count - 1 : (this.active + step + this.count) % this.count;
                this.$nextTick(() => document.getElementById(`site-search-option-${this.active}`)?.scrollIntoView({ block: 'nearest' }));

                return;
            }

            if (event.key === 'Enter' && this.open && this.active >= 0) {
                event.preventDefault();
                this.choose();
            }
        },
    }));
});
