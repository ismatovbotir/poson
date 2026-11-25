/*
 * Telegram Mini App (plan 20 §2 A5): sign-in bootstrap, BackButton,
 * MainButton, haptics, openLink / openTelegramLink and the checkout field
 * toggles. Reads only data- attributes; every URL is built and checked on
 * the server (MA-22). No inline scripts, no third-party code but
 * telegram-web-app.js (loaded before this file by layouts/tma).
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

/* MainButton mirrors the page's main HTML button (kept for old clients). */
const main = document.querySelector('[data-main-button]');
if (tg && tg.MainButton && main) {
    tg.MainButton.setText(main.dataset.mainButton || main.textContent.trim());
    tg.MainButton.show();
    tg.MainButton.onClick(() => main.click());
    document.documentElement.classList.add('tma-main-button');
}

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
});

/* One submit per form (double taps), a light haptic tap. */
document.addEventListener('submit', (event) => {
    const form = event.target;
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
    document.querySelectorAll('form[data-sent]').forEach((form) => delete form.dataset.sent);
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
