/*
 * Website chat widget (plan 28 C5b, CB-20, WC-11): Alpine state of
 * Site\Chat\Widget. The poll is a back-off timer, not wire:poll, so the
 * interval can change while refresh() skips renders:
 *  - bot mode: no requests at all (replies are synchronous);
 *  - manager mode (or a pending AI turn, Plan 33 B): every 5 s for 2 min
 *    after the last activity, then 15 s up to 10 min, then 60 s; after
 *    30 min idle it stops and shows "Check for replies";
 *  - paused while the tab is hidden;
 *  - a failed poll (429 from throttle:livewire, 419, 5xx, offline) shows no
 *    error modal: the interval doubles (up to 60 s) and a quiet line shows.
 * Worst case one open tab: 12 requests per minute, well below the
 * per-IP /livewire/update throttle (180 per minute) and refresh()'s own budget (30).
 */
const STEPS = [
    { until: 2 * 60 * 1000, every: 5000 },
    { until: 10 * 60 * 1000, every: 15000 },
    { until: 30 * 60 * 1000, every: 60000 },
];
const MAX_INTERVAL = 60000;
const IDLE_CHECK = 5000;
// A poll held back by the throttle back-off (livewire-throttle.js) may never settle: give up on it after this.
const POLL_TIMEOUT = 20000;

let polling = 0;
let widgetId = null;
let sending = 0;

document.addEventListener('livewire:init', () => {
    // Failures of the widget's own poll stay quiet (no Livewire error modal); other requests are untouched.
    window.Livewire.interceptRequest(({ request, onError }) => {
        // A concurrent send() keeps its normal error feedback.
        if (polling === 0 || sending > 0) {
            return;
        }

        // Only requests that carry the widget's own component (when the request lists its components).
        const messages = request?.messages;
        const list = messages ? Array.from(messages) : null;

        if (widgetId !== null && list !== null && !list.some((m) => m?.component?.id === widgetId)) {
            return;
        }

        onError(({ preventDefault }) => {
            preventDefault?.();
        });
    });

    window.Alpine.data('posonWebChat', ({ mode = 'bot', pending = false } = {}) => ({
        draft: '',
        sending: false,
        stalled: false,
        failed: false,
        lastActivity: Date.now(),
        errorFactor: 1,
        timer: null,

        init() {
            widgetId = this.$root.closest('[wire\\:id]')?.getAttribute('wire:id') ?? null;
            this.scrollDown();
            this.schedule(this.interval(mode === 'manager' || pending));

            this.onActivity = () => this.activity();
            this.onVisible = () => {
                if (!document.hidden && !this.stalled) {
                    this.schedule(1000);
                }
            };

            window.addEventListener('chat-activity', this.onActivity);
            window.addEventListener('chat-updated', this.onActivity);
            document.addEventListener('visibilitychange', this.onVisible);

            this.$wire.$hook?.('morphed', () => this.scrollDown());
        },

        destroy() {
            clearTimeout(this.timer);
            widgetId = null;
            window.removeEventListener('chat-activity', this.onActivity);
            window.removeEventListener('chat-updated', this.onActivity);
            document.removeEventListener('visibilitychange', this.onVisible);
        },

        polls() {
            return this.$wire.mode === 'manager' || this.$wire.pending === true;
        },

        interval(active) {
            if (!active) {
                return IDLE_CHECK;
            }

            const idle = Date.now() - this.lastActivity;
            const step = STEPS.find((item) => idle < item.until);

            return step ? Math.min(step.every * this.errorFactor, MAX_INTERVAL) : null;
        },

        schedule(delay) {
            clearTimeout(this.timer);

            if (delay === null) {
                this.stalled = true;

                return;
            }

            this.timer = setTimeout(() => this.tick(), delay);
        },

        async tick() {
            if (document.hidden) {
                return;
            }

            // Bot mode: nothing to ask the server; look again later (no request).
            if (!this.polls()) {
                this.schedule(IDLE_CHECK);

                return;
            }

            await this.poll();
            this.schedule(this.interval(true));
        },

        async poll() {
            polling++;

            try {
                await Promise.race([
                    this.$wire.refresh(),
                    new Promise((resolve, reject) => setTimeout(() => reject(new Error('timeout')), POLL_TIMEOUT)),
                ]);
                this.failed = false;
                this.errorFactor = 1;
            } catch (error) {
                this.failed = true;
                this.errorFactor = Math.min(this.errorFactor * 2, 12);
            } finally {
                polling--;
            }
        },

        async checkNow() {
            this.stalled = false;
            this.lastActivity = Date.now();
            await this.poll();
            this.schedule(this.interval(this.polls()));
        },

        activity() {
            this.lastActivity = Date.now();
            this.stalled = false;
            this.schedule(this.interval(this.polls()));
        },

        async submit() {
            const text = this.draft.trim();

            if (text === '' || this.sending) {
                return;
            }

            this.sending = true;
            sending++;

            try {
                if (await this.$wire.send(text)) {
                    this.draft = '';
                }
            } catch (error) {
                this.failed = true;
            } finally {
                this.sending = false;
                sending--;
                this.scrollDown();
            }
        },

        scrollDown() {
            this.$nextTick(() => {
                const list = this.$refs.list;

                if (list) {
                    list.scrollTop = list.scrollHeight;
                }
            });
        },
    }));
});
