/**
 * Analytics loader (plan 04 S8). The server decides whether to track and
 * prints <meta name="poson-analytics" content='{"ga4":"G-…","metrika":"123"}'>
 * only on the live public site, when enabled, and never for staff. IDs are
 * re-checked here; nothing is evaluated from the page, no inline script.
 */
const GA4 = /^G-[A-Z0-9]{4,16}$/;
const METRIKA = /^[1-9][0-9]{4,11}$/;

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

export function initAnalytics() {
    const config = readConfig();

    if (!config || window.__posonAnalytics) {
        return;
    }

    window.__posonAnalytics = true;

    // Further trackers (GTM, Meta Pixel) would be added here with their own ID check.
    if (typeof config.ga4 === 'string' && GA4.test(config.ga4)) {
        loadGa4(config.ga4);
    }

    if (typeof config.metrika === 'string' && METRIKA.test(config.metrika)) {
        loadMetrika(config.metrika);
    }
}

initAnalytics();
