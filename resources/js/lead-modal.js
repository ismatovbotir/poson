/*
 * Quote request modal (plan 42): Site\LeadModal in layouts::site.
 *
 * Triggers are real links with data-lead-modal="callback|product|cart"
 * (product: data-product-id). Only a plain left click is intercepted, and
 * only once Livewire runs and the modal is on the page (checkout has none);
 * otherwise the link navigates as before (#lead, product#quote, #cart-quote).
 * The last "roll-selected" of the product page's RollPicker goes with a
 * product open, only on the page where it was chosen (kept per pathname,
 * cleared on livewire:navigating); the server re-checks it (QM-4).
 * Which clicks count: opensLeadModal() in ./lead-modal-click.js.
 *
 * posonLeadModal (Alpine, the modal root): opens at once with a loading
 * state, closes the chat panel without moving focus to its launcher
 * (window event "lead-modal-opened", QM-10), calls $wire.open() and focuses
 * the heading. Esc, the backdrop, Close and the success "Close" button
 * (window event "lead-modal-close") hide it, unmount the form ($wire.close())
 * and return focus to the trigger. When the form can't be loaded, the
 * trigger's link is followed instead.
 */
import { opensLeadModal } from './lead-modal-click';

const KINDS = ['callback', 'product', 'cart'];
const LOAD_TIMEOUT = 15000;
const lastRolls = new Map();

const positiveInt = (value) => {
    const number = typeof value === 'number' ? value : Number.parseInt(String(value ?? ''), 10);

    return Number.isInteger(number) && number > 0 ? number : null;
};

window.addEventListener('roll-selected', (event) => {
    const detail = event.detail ?? {};
    const productId = positiveInt(detail.productId);

    if (productId !== null) {
        lastRolls.set(productId, { path: window.location.pathname, rollTypeId: positiveInt(detail.rollTypeId), rolls: positiveInt(detail.rolls) });
    }
});

// A roll chosen on an earlier page is never carried to the next one.
document.addEventListener('livewire:navigating', () => lastRolls.clear());

document.addEventListener('click', (event) => {
    const trigger = event.target instanceof Element ? event.target.closest('a[data-lead-modal]') : null;

    if (!trigger || !opensLeadModal(event, trigger) || !window.Livewire || !document.querySelector('[data-lead-modal-root][data-lead-modal-ready]')) {
        return;
    }

    const kind = trigger.dataset.leadModal;

    if (!KINDS.includes(kind)) {
        return;
    }

    event.preventDefault();

    const detail = { kind, trigger, href: trigger.href, productId: null, rollTypeId: null, rolls: null };

    if (kind === 'product') {
        detail.productId = positiveInt(trigger.dataset.productId);
        const roll = detail.productId !== null ? lastRolls.get(detail.productId) : undefined;

        if (roll && roll.path === window.location.pathname) {
            detail.rollTypeId = roll.rollTypeId;
            detail.rolls = roll.rolls;
        }
    }

    window.dispatchEvent(new CustomEvent('poson-lead-modal', { detail }));
});

document.addEventListener('livewire:init', () => {
    window.Alpine.data('posonLeadModal', () => ({
        open: false,
        loading: false,
        trigger: null,
        sequence: 0,

        init() {
            this.$el.setAttribute('data-lead-modal-ready', '');
        },

        show(detail = {}) {
            const sequence = ++this.sequence;
            const href = typeof detail.href === 'string' ? detail.href : null;

            this.trigger = detail.trigger instanceof HTMLElement ? detail.trigger : null;
            this.open = true;
            this.loading = true;
            window.dispatchEvent(new CustomEvent('lead-modal-opened'));
            this.$nextTick(() => this.$refs.title?.focus());

            const fallback = () => {
                if (sequence !== this.sequence) {
                    return;
                }

                this.hide();

                if (href) {
                    window.location.assign(href);
                }
            };
            const timer = setTimeout(fallback, LOAD_TIMEOUT);

            Promise.resolve(this.$wire.open(detail.kind, detail.productId ?? null, detail.rollTypeId ?? null, detail.rolls ?? null))
                .then(() => {
                    clearTimeout(timer);

                    if (sequence !== this.sequence || !this.open) {
                        return;
                    }

                    // A failed request (500, 419) can resolve instead of reject: no form was opened.
                    if (this.$wire.kind === null || this.$wire.kind === undefined) {
                        fallback();

                        return;
                    }

                    this.loading = false;
                    this.$nextTick(() => {
                        const active = document.activeElement;

                        // Don't take focus from a visitor who already moved it (e.g. to Close).
                        if (!active || active === document.body || active === this.$refs.title) {
                            this.$refs.title?.focus();
                        }
                    });
                })
                .catch(() => {
                    clearTimeout(timer);
                    fallback();
                });
        },

        hide() {
            if (!this.open) {
                return;
            }

            this.sequence++;
            this.open = false;
            this.loading = false;
            this.$wire.close();

            const trigger = this.trigger;
            this.trigger = null;

            // A trigger in a closed drawer (mobile menu) is hidden: leave focus where the page puts it.
            if (trigger && trigger.isConnected && trigger.getClientRects().length > 0) {
                this.$nextTick(() => trigger.focus({ preventScroll: true }));
            }
        },
    }));
});
