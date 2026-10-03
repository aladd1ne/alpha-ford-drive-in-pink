/*
 * Drive in Pink — background music for the reservation wizard (loaded on every page).
 *
 * Browsers only allow sound during a user gesture on the current page, and a page load
 * stops any audio. So a click on a "Réserver" link that opens a booking form
 * ([data-music-start]) starts the music during that click, then loads the form page in
 * place (its <main> is swapped in, its scripts run, the URL is updated) so the music keeps
 * playing. If that fails, the link is followed normally and the music starts on the
 * visitor's first tap on the form page. On a form page reached directly, it starts when
 * the visitor starts the form.
 *
 * The music fades in, loops, and resumes where it was if the page reloads (e.g. after a
 * server-side validation error). While it plays, the title is shown next to the
 * [data-music-toggle] button, which pauses / resumes it; a pause is remembered for the
 * browser session.
 */
(() => {
    const SRC = document.currentScript?.dataset.src;
    if (!SRC) {
        return;
    }

    const OFF_KEY = 'dp-wizard-music-off';     // visitor paused the music
    const WANTED_KEY = 'dp-wizard-music';      // music asked for / playing: start on the next form page
    const TIME_KEY = 'dp-wizard-music-time';   // playback position to resume from
    const VOLUME = 0.35;
    const GESTURES = ['pointerdown', 'keydown', 'touchend', 'click'];

    const store = {
        get: (key) => { try { return sessionStorage.getItem(key); } catch { return null; } },
        set: (key, value) => { try { sessionStorage.setItem(key, value); } catch { /* not remembered */ } },
        del: (key) => { try { sessionStorage.removeItem(key); } catch { /* not remembered */ } },
    };
    const off = () => store.get(OFF_KEY) === '1';

    let audio = null;
    let fade = null;
    let playing = false;
    let toggle = null;
    let stopWaiting = null;
    let swapped = false;

    // Toggle state + "En lecture : <title>" next to it while the music plays.
    function render() {
        if (!toggle) {
            return;
        }
        const player = toggle.closest('[data-music]');
        const now = player.querySelector('[data-music-now]');
        player.hidden = false;
        toggle.setAttribute('aria-pressed', String(playing));
        toggle.classList.toggle('is-playing', playing);
        if (playing && !now.textContent) {
            const title = document.createElement('strong');
            title.textContent = player.dataset.musicTitle;
            now.replaceChildren('En lecture : ', title);
        } else if (!playing) {
            now.replaceChildren();
        }
    }

    function fadeTo(target, done) {
        clearInterval(fade);
        fade = setInterval(() => {
            const before = audio.volume;
            const step = target > before ? 0.02 : -0.04;
            audio.volume = Math.min(1, Math.max(0, before + step));
            // iOS ignores volume changes: stop fading instead of looping forever.
            if (audio.volume === before || (step > 0 && audio.volume >= target) || (step < 0 && audio.volume <= target)) {
                clearInterval(fade);
                done?.();
            }
        }, 60);
    }

    // Must be called from a user gesture (or after one on this page).
    function play() {
        if (!audio) {
            audio = new Audio(SRC);
            audio.loop = true;
            audio.preload = 'auto';
            const time = parseFloat(store.get(TIME_KEY));
            if (time > 0) {
                audio.currentTime = time;
            }
        }
        audio.volume = 0;
        stopWaiting?.();

        return audio.play().then(() => {
            playing = true;
            store.set(WANTED_KEY, '1');
            render();
            fadeTo(VOLUME);
        });
    }

    function pause() {
        playing = false;
        store.del(WANTED_KEY);
        render();
        fadeTo(0, () => audio.pause());
    }

    // Start on the first gesture within `scope` (the toggle handles its own clicks).
    function startOnGesture(scope) {
        stopWaiting?.();
        const start = (event) => {
            if (toggle?.contains(event.target)) {
                return;
            }
            stopWaiting();
            if (!playing && !off()) {
                play().catch(() => {});
            }
        };
        GESTURES.forEach((type) => scope.addEventListener(type, start, true));
        stopWaiting = () => {
            GESTURES.forEach((type) => scope.removeEventListener(type, start, true));
            stopWaiting = null;
        };
    }

    // Wire the toggle of the form currently in the page (on load, and after a swap).
    function attach() {
        const form = document.querySelector('form[data-wizard]');
        toggle = document.querySelector('[data-music-toggle]');
        if (!form || !toggle) {
            toggle = null;
            return;
        }
        render();
        toggle.addEventListener('click', () => {
            if (playing) {
                store.set(OFF_KEY, '1');
                pause();
            } else {
                store.del(OFF_KEY);
                play().catch(() => {});
            }
        });
        if (playing || off()) {
            return;
        }
        if (store.get(WANTED_KEY) === '1') {
            // Try right away; if the browser wants a gesture on this page, take the first one anywhere.
            play().catch(() => startOnGesture(document));
        } else {
            store.del(TIME_KEY); // fresh visit: start from the beginning
            startOnGesture(form);
        }
    }

    // Replace this page's <main> with the form page's, keeping the music playing.
    async function openInPlace(url) {
        const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'text/html' } });
        const page = new DOMParser().parseFromString(await response.text(), 'text/html');
        const main = page.querySelector('main');
        if (!response.ok || !main || !main.querySelector('form[data-wizard]')) {
            throw new Error('Not a booking form');
        }

        document.querySelector('main').replaceWith(document.adoptNode(main));
        document.title = page.title;
        history.pushState({ dpMusic: true }, '', response.url);
        swapped = true;
        window.scrollTo(0, 0);

        // Run the scripts this page doesn't have yet (e.g. reservation.js).
        const loaded = new Set([...document.scripts].map((script) => script.src));
        page.querySelectorAll('script[src]').forEach((script) => {
            if (!loaded.has(script.src)) {
                const copy = document.createElement('script');
                copy.src = script.src;
                document.body.append(copy);
            }
        });

        // Forget scroll animations of the removed content; the footer moved.
        const { ScrollTrigger } = window;
        if (ScrollTrigger) {
            ScrollTrigger.getAll().forEach((trigger) => trigger.trigger?.isConnected === false && trigger.kill());
            ScrollTrigger.refresh();
        }

        attach();
    }

    document.addEventListener('click', (event) => {
        const link = event.target.closest('a[data-music-start]');
        if (!link || off()) {
            return;
        }
        store.set(WANTED_KEY, '1');
        const newTab = event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey;
        if (newTab || link.origin !== location.origin || !window.fetch || !window.DOMParser) {
            return; // normal navigation; the form page starts the music on the first tap
        }

        event.preventDefault();
        play().catch(() => {}); // during the click: allowed by every browser
        openInPlace(link.href).catch(() => {
            if (audio) {
                audio.pause();
                playing = false;
            }
            location.href = link.href;
        });
    });

    // Back / forward after a swap: load the real page (the music resumes on the first tap).
    window.addEventListener('popstate', () => swapped && location.reload());

    // Leaving while the music plays (submit, reload, link): pick it up on the next form page.
    window.addEventListener('pagehide', () => {
        if (playing) {
            store.set(WANTED_KEY, '1');
            store.set(TIME_KEY, String(audio.currentTime));
        }
    });

    attach();
})();
