/*
 * Drive in Pink — live solidarity fund.
 *
 * Keeps every fund counter in sync with the backend, without reloading the page.
 * Opt-in through data attributes:
 *   data-fund-live="/cagnotte/etat"   container to keep in sync (URL of the JSON state)
 *   data-fund-state='{"total":…}'     figures the page was rendered with
 *   data-fund="total"                 number to update (its first text node; any key of the state)
 *   data-fund-label="testDrives"      text switched between data-one / data-many with that figure
 *   data-fund-show-if="otherAmount"   hidden while that figure is 0
 *   data-fund-bump                    shows "+30 DT" when the total grows
 *   data-fund-updated                 time of the last successful check
 *   data-fund-status                  visually hidden live region for screen readers
 *
 * When the total grows, a toast with the new amount slides in from the top right (js/toast.js).
 *
 * The state endpoint is polled every few seconds while the tab is visible, and right away
 * when it becomes visible again. Unchanged figures answer 304 (ETag), so polling is cheap.
 */
(() => {
    const roots = document.querySelectorAll('[data-fund-live]');
    if (!roots.length) return;

    const url = roots[0].dataset.fundLive;
    const INTERVAL = 5000;
    const reduceMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;
    const format = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 });
    const display = (n) => format.format(Math.round(n)).replace(/ /g, ' ');
    const time = new Intl.DateTimeFormat('fr-FR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });

    let state = null;
    try { state = JSON.parse(roots[0].dataset.fundState || 'null'); } catch { /* first poll fills it */ }

    const all = (selector) => [...roots].flatMap((root) => [...root.querySelectorAll(selector)]);

    const setNumber = (el, value) => {
        const node = el.firstChild;
        if (!node || node.nodeType !== Node.TEXT_NODE) return;
        // Take over from the scroll count-up (js/scroll-animations.js) or a previous update.
        el.dpCountTween?.kill();
        el.dpCountTween = null;
        el.dataset.count = value;

        const { gsap } = window;
        if (reduceMotion || !gsap) {
            node.nodeValue = display(value);
            return;
        }
        const counter = { n: Number(node.nodeValue.replace(/\D/g, '')) || 0 };
        el.dpCountTween = gsap.to(counter, {
            n: value,
            duration: 1.4,
            ease: 'power2.out',
            onUpdate: () => { node.nodeValue = display(counter.n); },
        });
    };

    const bump = (amount) => {
        all('[data-fund-bump]').forEach((el) => {
            el.textContent = `+${display(amount)} DT`;
            el.classList.remove('is-shown');
            void el.offsetWidth; // restart the CSS animation
            el.classList.add('is-shown');
        });
        roots.forEach((root) => {
            root.classList.remove('is-bumped');
            void root.offsetWidth;
            root.classList.add('is-bumped');
        });
    };

    const render = (next, previous) => {
        Object.keys(next).forEach((key) => {
            if (previous && previous[key] === next[key]) return;
            all(`[data-fund="${key}"]`).forEach((el) => setNumber(el, next[key]));
            all(`[data-fund-label="${key}"]`).forEach((el) => {
                el.textContent = next[key] > 1 ? el.dataset.many : el.dataset.one;
            });
            all(`[data-fund-show-if="${key}"]`).forEach((el) => { el.hidden = !next[key]; });
        });

        if (previous && next.total > previous.total) {
            const testDrives = `${next.testDrives} test drive${next.testDrives > 1 ? 's' : ''} validé${next.testDrives > 1 ? 's' : ''}`;
            bump(next.total - previous.total);
            if (window.dpToast) {
                // The toast region announces it; avoid a second announcement.
                window.dpToast({
                    badge: `+${display(next.total - previous.total)} DT`,
                    title: 'Nouvelle contribution à la cagnotte',
                    message: `Total : ${display(next.total)} DT · ${testDrives}`,
                });
            } else {
                all('[data-fund-status]').forEach((el) => {
                    el.textContent = `Cagnotte mise à jour : ${display(next.total)} DT, ${testDrives}.`;
                });
            }
        }
    };

    let timer = null;
    let running = false;

    const poll = async () => {
        clearTimeout(timer);
        if (running) return;
        running = true;
        try {
            // no-cache: always revalidated with the server (If-None-Match → 304 when unchanged)
            const response = await fetch(url, { cache: 'no-cache', headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const next = await response.json();
            render(next, state);
            state = next;
            roots.forEach((root) => root.classList.remove('is-offline'));
            all('[data-fund-updated]').forEach((el) => { el.textContent = time.format(new Date()); });
        } catch {
            roots.forEach((root) => root.classList.add('is-offline'));
        } finally {
            running = false;
            if (!document.hidden) timer = setTimeout(poll, INTERVAL);
        }
    };

    document.addEventListener('visibilitychange', () => {
        clearTimeout(timer);
        if (!document.hidden) poll();
    });
    // Back/forward cache: the page comes back with stale figures.
    window.addEventListener('pageshow', (event) => { if (event.persisted) poll(); });

    poll();
})();
