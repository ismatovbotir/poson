/**
 * Plan 40 (YD-1, YD-2): after checkout, while Metrika goals are on, the page
 * does not redirect. The server dispatches "poson-order-placed" with
 * next = the relative /o/{ulid} URL; this listener is ALWAYS registered
 * (whatever the analytics config) and:
 *
 *  - rebuilds next with safeOrderUrl() (same origin, /o/<ULID> only, query
 *    lang ∈ uz/ru/en and pay = uzqr only, no hash / user / password);
 *    an invalid next is ignored (the page shows a fallback link);
 *  - without Metrika navigates at once; otherwise sends order_placed and
 *    navigates on the goal callback or after 1500 ms, whichever is first;
 *  - order_placed is sent once per page (orderGoalSent) and only for an
 *    event carrying goal = order_placed: a replay (double click) navigates only.
 */
import { reachGoal } from './analytics';
import { safeOrderUrl } from './order-url';

const GOAL_TIMEOUT_MS = 1500;

let orderGoalSent = false;

export function listenForOrderPlaced() {
    if (typeof window === 'undefined' || window.__posonOrderPlaced) {
        return;
    }

    window.__posonOrderPlaced = true;

    window.addEventListener('poson-order-placed', (event) => {
        const detail = event.detail || {};
        const target = safeOrderUrl(detail.next, window.location.origin);

        if (target === null) {
            return;
        }

        let done = false;
        const go = () => {
            if (!done) {
                done = true;
                window.location.assign(target);
            }
        };

        const wantsGoal = detail.goal === 'order_placed' && !orderGoalSent;

        if (wantsGoal) {
            orderGoalSent = true;
        }

        if (!wantsGoal || !reachGoal('order_placed', go)) {
            go();

            return;
        }

        window.setTimeout(go, GOAL_TIMEOUT_MS);
    });
}

listenForOrderPlaced();
