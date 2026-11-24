/**
 * Analytics loader (plan 04 S8). The server decides whether to track and
 * prints <meta name="poson-analytics" content='{"ga4":"G-…","metrika":"123"}'>
 * only on the live public site, when enabled, and never for staff. IDs are
 * re-checked here; nothing is evaluated from the page, no inline script.
 */
const GA4 = /^G-[A-Z0-9]{4,16}$/;
const METRIKA = /^[1-9][0-9]{4,11}$/;

/**
 * Plan 40 (YD-3): goal IDs (App\Enums\AnalyticsGoal). Every name is checked
 * against GOAL; delegated clicks use the exact CLICK_GOALS allow-list.
 * Goals go out without any parameters (no PII, amounts or tokens).
 */
export const GOAL = /^[a-z][a-z0-9_]{2,39}$/;
const CLICK_GOALS = ['click_telegram'];

let activeConfig = null;
let firedClickGoals = new Set();

function readConfig() {
    const meta = document.querySelector('meta[name="poson-analytics"]');

    if (!meta) {
        return null;
    }

    try {
        const config = JSON.parse(meta.getAttribute('content') || '{}');

        return config && typeof config === 'object' ? config : null;
    } catch {
        return null;
    }
}

function loadScript(src) {
    const script = document.createElement('script');
    script.async = true;
    script.src = src;
    document.head.appendChild(script);
}

function loadGa4(id) {
    window.dataLayer = window.dataLayer || [];
    window.gtag = function gtag() {
        window.dataLayer.push(arguments);
    };
    window.gtag('js', new Date());
    window.gtag('config', id);

    loadScript(`https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(id)}`);
}

function loadMetrika(id) {
    const counter = Number(id);

    window.ym = window.ym || function ym() {
        (window.ym.a = window.ym.a || []).push(arguments);
    };
    window.ym.l = Date.now();
    window.ym(counter, 'init', {
        clickmap: true,
        trackLinks: true,
        accurateTrackBounce: true,
        webvisor: false,
    });

    loadScript('https://mc.yandex.ru/metrika/tag.js');
}

/**
 * Plan 11a: "Add to cart" (Livewire browser event "poson-add-to-cart",
 * dispatched by the server only while the site tracks visitors) → GA4
 * add_to_cart with the SKU and quantity; no price, cart or personal data.
 */
function listenForCartEvents(config) {
    window.addEventListener('poson-add-to-cart', (event) => {
        const detail = event.detail || {};
        const sku = typeof detail.sku === 'string' ? detail.sku.slice(0, 64) : '';
        const quantity = Number.isInteger(detail.quantity) ? detail.quantity : 1;

        if (sku === '') {
            return;
        }

        const payload = { currency: 'UZS', items: [{ item_id: sku, quantity }] };

        if (typeof window.gtag === 'function') {
            window.gtag('event', 'add_to_cart', payload);
        } else {
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push({ event: 'add_to_cart', ecommerce: payload });
        }

        if (typeof config.metrika === 'string' && METRIKA.test(config.metrika) && typeof window.ym === 'function') {
            window.ym(Number(config.metrika), 'reachGoal', 'add_to_cart');
        }
    });
}

/**
 * Send one goal to Metrika (and GA4). callback runs once Metrika confirmed
 * the hit; returns false (callback not called) when nothing was sent.
 */
export function reachGoal(goal, callback) {
    const config = activeConfig;

    if (!config || typeof goal !== 'string' || !GOAL.test(goal)) {
        return false;
    }

    if (typeof window.gtag === 'function') {
        window.gtag('event', goal);
    }

    if (typeof config.metrika === 'string' && METRIKA.test(config.metrika) && typeof window.ym === 'function') {
        if (typeof callback === 'function') {
            window.ym(Number(config.metrika), 'reachGoal', goal, undefined, callback);
        } else {
            window.ym(Number(config.metrika), 'reachGoal', goal);
        }

        return true;
    }

    return false;
}

/**
 * Livewire "poson-goal" events (detail.goal), sent by the server only after
 * a stored lead / chat while the visitor is tracked.
 */
function listenForGoalEvents() {
    window.addEventListener('poson-goal', (event) => {
        const goal = event.detail && typeof event.detail.goal === 'string' ? event.detail.goal : '';

        reachGoal(goal);
    });
}

/**
 * Delegated clicks, once per goal per page view: tel: links → click_phone,
 * [data-poson-goal] → its value when it is on the CLICK_GOALS allow-list.
 */
function listenForClickGoals() {
    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;

        if (!target) {
            return;
        }

        let goal = null;
        const tagged = target.closest('[data-poson-goal]');

        if (tagged) {
            const value = tagged.getAttribute('data-poson-goal') || '';
            goal = GOAL.test(value) && CLICK_GOALS.includes(value) ? value : null;
        } else if (target.closest('a[href^="tel:"]')) {
            goal = 'click_phone';
        }

        if (goal && !firedClickGoals.has(goal)) {
            firedClickGoals.add(goal);
            reachGoal(goal);
        }
    }, { capture: true });

    // wire:navigate swaps the page without a reload: a new page view.
    document.addEventListener('livewire:navigated', () => {
        firedClickGoals = new Set();
    });
}

/**
 * A goal reached by a form redirect (careers): <meta name="poson-goal">, fired once.
 */
function fireMetaGoal() {
    const meta = document.querySelector('meta[name="poson-goal"]');
    const goal = meta ? meta.getAttribute('content') || '' : '';

    if (meta) {
        meta.remove();
    }

    reachGoal(goal);
}

export function initAnalytics() {
    const config = readConfig();

    if (!config || window.__posonAnalytics) {
        return;
    }

    window.__posonAnalytics = true;
    activeConfig = config;

    // Further trackers (GTM, Meta Pixel) would be added here with their own ID check.
    if (typeof config.ga4 === 'string' && GA4.test(config.ga4)) {
        loadGa4(config.ga4);
    }

    if (typeof config.metrika === 'string' && METRIKA.test(config.metrika)) {
        loadMetrika(config.metrika);
    }

    listenForCartEvents(config);
    listenForGoalEvents();
    listenForClickGoals();
    fireMetaGoal();
}

initAnalytics();
