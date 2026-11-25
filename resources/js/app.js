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
