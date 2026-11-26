/*
 * Telegram Mini App (plan 20 §2 A5): sign-in bootstrap, BackButton,
 * MainButton, haptics, openLink / openTelegramLink and the checkout field
 * toggles. Reads only data- attributes; every URL is built and checked on
 * the server (MA-22). No inline scripts, no third-party code but
 * telegram-web-app.js (loaded before this file by layouts/tma).
 *
 * Plan 45 (MA-43, MA-47): cart forms ([data-cart-form]) go by fetch with
 * Accept: application/json; the answer only toggles server-rendered markup
 * (hidden, classes, textContent, button values); no markup strings, no styles.
 * A redirect or a non-JSON answer is a failure; 401 / 419 reload the page
 * (it signs in again); an add is never sent twice, an update / remove falls
 * back to the plain form. No timers, no polling.
 */
const tg = window.Telegram && window.Telegram.WebApp;
const body = document.body;
const data = body.dataset;

if (tg) {
    tg.ready();
    tg.expand();
}

/* Sign-in: POST the signed initData, follow the internal URL the server returns. */
let bootedInPage = 0;
if ('tmaBoot' in data) {
    let boots = [];
    bootedInPage += 1;
    try {
        boots = JSON.parse(sessionStorage.getItem('tma-boots') || '[]').filter((t) => Date.now() - t < 60000);
        boots.push(Date.now());
        sessionStorage.setItem('tma-boots', JSON.stringify(boots));
    } catch (e) {
        // No sessionStorage: a page that boots again without leaving still counts as a loop.
        boots = Array(bootedInPage).fill(Date.now());
    }

    if (!tg || !tg.initData || boots.length > 3) {
        location.replace(data.browserUrl);
    } else {
        fetch(data.authUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({ init_data: tg.initData, next: data.next || null }),
        })
            .then((response) => response.json().catch(() => ({})))
            .then((result) => {
                if (result && typeof result.redirect === 'string' && result.redirect.startsWith(location.origin + '/tma/')) {
                    location.replace(result.redirect);
                } else {
                    document.querySelector('[data-boot-error]')?.removeAttribute('hidden');
                }
            })
            .catch(() => document.querySelector('[data-boot-error]')?.removeAttribute('hidden'));
    }
}

/* Back: Telegram's BackButton goes to the page's parent screen; none on top-level screens. */
if (tg && tg.BackButton) {
    if (data.back) {
        tg.BackButton.show();
        tg.BackButton.onClick(() => location.assign(data.back));
    } else {
        tg.BackButton.hide();
    }
}

/* MainButton mirrors the page's main HTML button (kept for old clients); the cart bar can take over. */
let main = null;
const setMain = (el) => {
    // The button that hands over keeps its text in data-main-off, so it can take over again.
    if (main && main !== el) {
        main.dataset.mainOff = main.dataset.mainButton || '';
        delete main.dataset.mainButton;
    }
    if (el && 'mainOff' in el.dataset) {
        el.dataset.mainButton = el.dataset.mainButton || el.dataset.mainOff;
        delete el.dataset.mainOff;
    }
    main = el;
    if (!tg || !tg.MainButton) {
        return;
    }
    if (el && !el.closest('[hidden]')) {
        tg.MainButton.setText(el.dataset.mainButton || el.textContent.trim());
        tg.MainButton.show();
        document.documentElement.classList.add('tma-main-button');
    } else {
        tg.MainButton.hide();
    }
};
setMain([...document.querySelectorAll('[data-main-button]')].find((el) => !el.closest('[hidden]')) || null);
if (tg && tg.MainButton) {
    tg.MainButton.onClick(() => main && main.click());
}

const haptic = (kind) => {
    const h = tg && tg.HapticFeedback;
    if (h) {
        kind === 'step' ? h.selectionChanged() : h.notificationOccurred(kind);
    }
};

const toast = document.querySelector('[data-toast]');
const say = (text, error) => {
    if (!toast || !text) {
        return;
    }
    toast.textContent = text;
    toast.classList.toggle('tma-toast-error', !!error);
    toast.classList.remove('tma-toast-on');
    void toast.offsetWidth;
    toast.classList.add('tma-toast-on');
};

/* The cart bar, the header badge and the line's add button / stepper after a JSON answer. */
const bar = document.querySelector('[data-cart-bar]');
const paint = (form, result) => {
    const badge = document.querySelector('[data-cart-badge]');
    if (badge) {
        badge.textContent = result.count;
        badge.hidden = result.count < 1;
        badge.parentNode.dataset.cartCount = result.count;
    }
    if (bar) {
        const text = `${bar.dataset.barLabel} (${result.count}) · ${result.subtotalText}`;
        bar.querySelector('[data-bar-count]').textContent = result.count;
        bar.querySelector('[data-bar-total]').textContent = result.subtotalText;
        bar.dataset.mainButton = text;
        bar.parentNode.hidden = result.count < 1;
        // An empty cart hands the MainButton back to the page's own main button (if one is shown).
        setMain(result.count > 0 ? bar : [...document.querySelectorAll('[data-main-button],[data-main-off]')].find((el) => el !== bar && !el.closest('[hidden]')) || null);
    }
    const box = form.closest('[data-cart-box]');
    const step = box && box.querySelector('[data-cart-form="update"]');
    const rolls = box && box.querySelector('[data-roll-in-cart]');
    if (rolls && result.qty > 0) {
        rolls.querySelector('[data-roll-qty]').textContent = result.qty;
        rolls.hidden = false;
    }
    if (!step) {
        return;
    }
    const qty = result.qty;
    const add = box.querySelector('[data-cart-form="add"]');
    const shown = step.closest('[data-in-cart]') || step;
    add && (add.hidden = qty > 0);
    shown.hidden = qty < 1;
    step.hidden = qty < 1;
    if (qty > 0) {
        const min = +step.dataset.min;
        const minus = step.querySelector('[data-step="-1"]');
        const plus = step.querySelector('[data-step="1"]');
        step.querySelector('[data-qty]').textContent = qty;
        minus.value = qty - 1;
        plus.value = Math.max(qty + 1, min);
        plus.disabled = qty >= +step.dataset.max;
        qty <= min ? minus.setAttribute('formaction', minus.dataset.remove) : minus.removeAttribute('formaction');
    }
};

const send = (form, button, kind) => {
    const body = new FormData(form);
    const url = (button && button.getAttribute('formaction')) || form.action;
    const busy = button || form.querySelector('[type="submit"]');
    const error = form.closest('[data-cart-box]')?.querySelector('[data-cart-error]');
    const retry = () => {
        form.dataset.native = '1';
        form.requestSubmit ? form.requestSubmit(button) : form.submit();
    };
    if (button && button.name) {
        body.set(button.name, button.value);
    }
    form.dataset.busy = '1';
    busy && busy.setAttribute('aria-busy', 'true');
    error && (error.hidden = true);

    fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
        body,
    })
        .then((response) => {
            const json = !response.redirected && (response.headers.get('Content-Type') || '').includes('application/json');
            return (json ? response.json() : Promise.resolve(null)).catch(() => null).then((result) => [response, result]);
        })
        .then(([response, result]) => {
            if (response.status === 401 || response.status === 419) {
                location.reload();
            } else if (response.ok && result && typeof result.count === 'number') {
                paint(form, result);
                haptic(kind === 'add' ? 'success' : 'step');
                kind === 'add' && say(toast && toast.dataset.added);
            } else if (!result && kind !== 'add') {
                retry();
            } else {
                const invalid = response.status === 422 && result && typeof result.message === 'string';
                const text = invalid ? result.message : toast && toast.dataset[response.status === 429 ? 'retry' : 'fail'];
                haptic('error');
                say(text, true);
                if (error && invalid) {
                    error.textContent = text;
                    error.hidden = false;
                }
            }
        })
        .catch(() => (kind === 'add' ? (haptic('error'), say(toast && toast.dataset.fail, true)) : retry()))
        .finally(() => {
            delete form.dataset.busy;
            busy && busy.removeAttribute('aria-busy');
        });
};

document.addEventListener('click', (event) => {
    const link = event.target.closest('[data-open-link]');
    if (link) {
        event.preventDefault();
        const url = link.dataset.openLink;
        if (/^https:\/\//.test(url)) {
            tg ? tg.openLink(url) : window.open(url, '_blank', 'noopener');
        }
        return;
    }

    const telegram = event.target.closest('[data-tg-link]');
    if (telegram) {
        event.preventDefault();
        const url = telegram.dataset.tgLink;
        if (/^https:\/\/t\.me\/[A-Za-z0-9_]+(\?start=[A-Za-z0-9_-]+)?$/.test(url)) {
            tg ? tg.openTelegramLink(url) : location.assign(url);
            if (tg && 'close' in telegram.dataset) {
                tg.close();
            }
        }
        return;
    }

    if (event.target.closest('[data-tma-close]') && tg) {
        tg.close();
    }

    /* The product page's quantity before adding: local only, the server checks it again. */
    const step = event.target.closest('[data-qty-step]');
    if (step) {
        const input = step.parentNode.querySelector('input');
        input.value = Math.min(+input.max, Math.max(+input.min, (+input.value || 0) + +step.dataset.qtyStep));
        haptic('step');
    }
});

/* One submit per form (double taps), a light haptic tap; cart forms by fetch. */
document.addEventListener('submit', (event) => {
    const form = event.target;
    const kind = form.dataset.cartForm;
    if (kind && !form.dataset.native && window.fetch && window.FormData) {
        event.preventDefault();
        form.dataset.busy || send(form, event.submitter, kind);
        return;
    }
    if (form.dataset.sent) {
        event.preventDefault();
        return;
    }
    form.dataset.sent = '1';
    if (tg) {
        tg.HapticFeedback && tg.HapticFeedback.impactOccurred('light');
        main && form.contains(main) && tg.MainButton.showProgress();
    }
});

/* Back from the next page (bfcache): forms can be sent again, the MainButton stops spinning. */
window.addEventListener('pageshow', (event) => {
    if (!event.persisted) {
        return;
    }
    document.querySelectorAll('form[data-sent], form[data-native]').forEach((form) => {
        delete form.dataset.sent;
        delete form.dataset.native;
    });
    if (tg && tg.MainButton) {
        tg.MainButton.hideProgress();
    }
});

/* Checkout: fields and payment methods that fit the buyer type and delivery. */
const checkout = document.querySelector('[data-checkout]');
if (checkout) {
    const value = (name) => (checkout.querySelector(`[name="${name}"]:checked`) || {}).value || '';
    const sync = () => {
        const buyer = value('buyer_type');
        const delivery = value('delivery_type');
        let offered = 0;
        checkout.querySelectorAll('[data-show-when]').forEach((el) => {
            const [field, list] = el.dataset.showWhen.split(':');
            el.hidden = !list.split(',').includes(field === 'buyer' ? buyer : delivery);
        });
        checkout.querySelectorAll('[data-pay-for]').forEach((el) => {
            const ok = el.dataset.payFor.split(' ').includes(`${buyer}:${delivery}`);
            el.hidden = !ok;
            offered += ok ? 1 : 0;
            const input = el.querySelector('input');
            if (!ok && input) {
                input.checked = false;
            }
        });
        checkout.querySelectorAll('[data-no-method]').forEach((el) => (el.hidden = offered > 0));
    };
    checkout.addEventListener('change', sync);
    sync();
}

/* The order was just placed. */
if (document.querySelector('[data-placed]')) {
    haptic('success');
}
