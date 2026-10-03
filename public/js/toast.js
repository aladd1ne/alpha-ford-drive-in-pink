/*
 * Drive in Pink — toasts sliding in from the top right.
 *
 *   window.dpToast({ title, message, badge, variant })   show one from JS (see js/fund-live.js)
 *   <template data-toast data-title="…" data-badge="…">…</template>
 *                                                        server-rendered toast (back-office flash),
 *                                                        shown once the page is loaded
 *
 * Each toast closes by itself after a few seconds (paused while hovered or focused),
 * or with its close button. Announced politely to screen readers.
 *
 * Cagnotte toasts (variant "fund") play a money counting sound. Browsers only allow sound
 * once the visitor has interacted with the page, so the audio is unlocked on the first
 * click / key press; before that, the toast simply shows without sound.
 */
(() => {
    const DURATION = 6000;
    const MAX = 3;
    const SOUND_URL = '/assets/sounds/money-counting.mp3';
    let stack = null;

    const sound = new Audio(SOUND_URL);
    sound.preload = 'auto';
    sound.volume = 0.6;

    const playSound = () => {
        sound.currentTime = 0;
        sound.play().catch(() => { /* blocked until the visitor interacts with the page */ });
    };

    // A muted play during a user gesture unlocks the element for later toasts.
    const unlock = () => {
        if (!sound.paused) return; // already playing: nothing to unlock
        sound.muted = true;
        sound.play().then(() => {
            sound.pause();
            sound.currentTime = 0;
        }).catch(() => {}).finally(() => { sound.muted = false; });
    };
    ['pointerdown', 'keydown'].forEach((type) => document.addEventListener(type, unlock, { once: true, capture: true }));

    const container = () => {
        if (stack) return stack;
        stack = document.createElement('div');
        stack.className = 'dp-toasts';
        stack.setAttribute('role', 'status');
        stack.setAttribute('aria-live', 'polite');
        document.body.append(stack);
        return stack;
    };

    const close = (toast) => {
        if (toast.dataset.closing) return;
        toast.dataset.closing = '1';
        clearTimeout(toast.dpTimer);
        toast.classList.remove('is-shown');
        toast.classList.add('is-leaving');
        const remove = () => toast.remove();
        toast.addEventListener('transitionend', remove, { once: true });
        setTimeout(remove, 600); // no transition (reduced motion, hidden tab)
    };

    const dpToast = ({ title = '', message = '', badge = '', variant = 'fund', duration = DURATION, withSound = variant === 'fund' } = {}) => {
        const toast = document.createElement('div');
        toast.className = `dp-toast dp-toast--${variant}`;
        toast.innerHTML = `
            <span class="dp-toast__badge" aria-hidden="true"></span>
            <div class="dp-toast__body">
                <p class="dp-toast__title"></p>
                <p class="dp-toast__message"></p>
            </div>
            <button type="button" class="dp-toast__close" aria-label="Fermer la notification">×</button>
            <span class="dp-toast__progress" aria-hidden="true"></span>`;
        toast.querySelector('.dp-toast__badge').textContent = badge;
        toast.querySelector('.dp-toast__badge').hidden = !badge;
        toast.querySelector('.dp-toast__title').textContent = title;
        toast.querySelector('.dp-toast__message').textContent = message;
        toast.style.setProperty('--dp-toast-duration', `${duration}ms`);

        const root = container();
        root.prepend(toast);
        [...root.children].slice(MAX).forEach(close);

        // Remaining time survives a pause (hover / focus).
        let remaining = duration;
        let startedAt = 0;
        let paused = true;
        const start = () => {
            if (!paused || toast.matches(':hover, :focus-within')) return;
            paused = false;
            startedAt = Date.now();
            toast.dpTimer = setTimeout(() => close(toast), remaining);
            toast.classList.remove('is-paused');
        };
        const pause = () => {
            if (paused) return;
            paused = true;
            clearTimeout(toast.dpTimer);
            remaining -= Date.now() - startedAt;
            toast.classList.add('is-paused');
        };
        toast.addEventListener('mouseenter', pause);
        toast.addEventListener('mouseleave', start);
        toast.addEventListener('focusin', pause);
        toast.addEventListener('focusout', start);
        toast.querySelector('.dp-toast__close').addEventListener('click', () => close(toast));

        // Next frame: let the off-screen position apply first so the slide-in runs.
        requestAnimationFrame(() => requestAnimationFrame(() => toast.classList.add('is-shown')));
        start();
        if (withSound) playSound();

        return toast;
    };

    window.dpToast = dpToast;

    const showRendered = () => {
        document.querySelectorAll('template[data-toast]').forEach((template, i) => {
            const { title, badge, variant } = template.dataset;
            setTimeout(() => dpToast({ title, badge, variant, message: template.content.textContent.trim() }), 300 + i * 150);
            template.remove();
        });
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', showRendered);
    } else {
        showRendered();
    }
})();
