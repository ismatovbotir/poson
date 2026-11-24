/**
 * Plan 40 (YD-1): validation of the checkout's "next" URL, without any
 * browser dependency (tests/Feature/Site/Analytics/OrderPlacedUrlJsTest.php
 * runs it with node when available).
 */
const ORDER_PATH = /^\/o\/[0-7][0-9A-HJKMNP-TV-Z]{25}$/;
const LOCALES = ['uz', 'ru', 'en'];

/**
 * The validated absolute order URL for next, or null.
 */
export function safeOrderUrl(next, origin) {
    if (typeof next !== 'string' || next.length > 200 || !next.startsWith('/o/') || next.includes('\\') || next.includes('#')) {
        return null;
    }

    let url;

    try {
        url = new URL(next, origin);
    } catch {
        return null;
    }

    if (url.origin !== origin || url.username !== '' || url.password !== '' || url.hash !== '' || !ORDER_PATH.test(url.pathname)) {
        return null;
    }

    const clean = new URL(url.pathname, origin);

    for (const [key, value] of url.searchParams) {
        if (key === 'lang' && LOCALES.includes(value) && !clean.searchParams.has('lang')) {
            clean.searchParams.set('lang', value);
        } else if (key === 'pay' && value === 'uzqr' && !clean.searchParams.has('pay')) {
            clean.searchParams.set('pay', 'uzqr');
        } else {
            return null;
        }
    }

    return clean.toString();
}
