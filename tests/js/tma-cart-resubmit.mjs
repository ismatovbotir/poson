// Plan 45 MA-43 behaviour check for resources/js/tma.js (run by MiniAppRedesignTest with node):
// a cart form whose fetch fails is never sent again for "add", and falls back to the plain form
// once for "update". Minimal DOM stubs only; prints a JSON result.
import { readFileSync } from 'node:fs';

const source = readFileSync(process.argv[2], 'utf8');
const handlers = {};
const results = {};

globalThis.window = { addEventListener() {}, fetch: true, FormData: true };
globalThis.location = { reload() {}, origin: 'https://x' };
globalThis.FormData = class { set() {} };
globalThis.document = {
    body: { dataset: {} },
    documentElement: { classList: { add() {} } },
    addEventListener: (type, fn) => (handlers[type] = fn),
    querySelector: (selector) => (selector.includes('csrf') ? { content: 't' } : null),
    querySelectorAll: () => [],
};

new Function(source)();

const run = async (kind) => {
    let fetches = 0;
    let resubmits = 0;
    globalThis.fetch = () => {
        fetches += 1;
        return Promise.reject(new Error('offline'));
    };
    const form = {
        dataset: { cartForm: kind },
        action: 'https://x/tma/uz/cart',
        closest: () => null,
        querySelector: () => null,
        requestSubmit: () => (resubmits += 1),
        submit: () => (resubmits += 1),
    };
    handlers.submit({ target: form, submitter: null, preventDefault() {} });
    await new Promise((resolve) => setImmediate(resolve));
    await new Promise((resolve) => setImmediate(resolve));
    results[kind] = { fetches, resubmits };
};

await run('add');
await run('update');
console.log(JSON.stringify(results));
