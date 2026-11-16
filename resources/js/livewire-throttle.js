/*
 * T7: POST /livewire-{hash}/update is throttled (HTTP 429 + Retry-After).
 * Livewire's error modal is replaced by a localized toast, and while the
 * back-off lasts further component requests (wire:poll included) are held
 * back instead of extending the lock-out. The message comes from
 * <meta name="livewire-throttle" content="... {seconds} ...">.
 */
const MAX_BACKOFF_SECONDS = 300;
const CANCEL_NOTICE_INTERVAL_MS = 5000;
let backoffUntil = 0;
let lastCancelNotice = 0;

const message = (seconds) => {
    const template = document.querySelector('meta[name="livewire-throttle"]')?.content;

    return template ? template.replace('{seconds}', String(seconds)) : null;
};

const notify = (text) => {
    // Admin: the toast stack listens for this event.
    window.dispatchEvent(new CustomEvent('toast', { detail: { title: text, type: 'error' } }));

    if (document.querySelector('[data-admin-toasts]')) {
        return;
    }

    // Public site: one small live region.
    let box = document.getElementById('livewire-throttle-toast');

    if (!box) {
        box = document.createElement('div');
        box.id = 'livewire-throttle-toast';
        box.setAttribute('role', 'alert');
        box.className = 'fixed inset-x-3 bottom-3 z-[70] mx-auto max-w-sm rounded-box border border-line bg-white p-3.5 text-sm font-semibold text-ink shadow-card';
        document.body.appendChild(box);
    }

    box.textContent = text;
    box.hidden = false;
    clearTimeout(box.hideTimer);
    box.hideTimer = setTimeout(() => { box.hidden = true; }, 6000);
};

document.addEventListener('livewire:init', () => {
    window.Livewire.interceptRequest(({ request, onError }) => {
        const now = Date.now();

        if (now < backoffUntil) {
            request.cancel?.();

            // Tell the user why nothing happened, at most once per interval (pollers stay quiet).
            if (now - lastCancelNotice >= CANCEL_NOTICE_INTERVAL_MS) {
                lastCancelNotice = now;

                const text = message(Math.ceil((backoffUntil - now) / 1000));

                if (text) {
                    notify(text);
                }
            }

            return;
        }

        onError(({ response, preventDefault }) => {
            if (response?.status !== 429) {
                return;
            }

            preventDefault?.();

            const retry = parseInt(response.headers?.get('Retry-After') ?? '', 10);
            const seconds = Math.min(Math.max(Number.isFinite(retry) ? retry : 60, 1), MAX_BACKOFF_SECONDS);

            backoffUntil = Date.now() + seconds * 1000;
            lastCancelNotice = Date.now();

            const text = message(seconds);

            if (text) {
                notify(text);
            }
        });
    });
});
