/*
 * Drive in Pink — scroll animations (GSAP + ScrollTrigger + SplitText).
 *
 * Opt-in through data attributes:
 *   data-reveal          fades and slides the element up when it enters the viewport
 *   data-reveal="cover"  unveils an image from the bottom (clip only, the image is never scaled)
 *   data-reveal-text     reveals a heading line by line, each line rising from a mask
 *   data-reveal-stagger  reveals the element's children one after another
 *   data-count="1234"    counts a number up from 0 (fr-FR spacing, e.g. "1 234")
 *
 * The head of base.html.twig adds .dp-anim to <html> (unless the visitor prefers reduced motion),
 * which hides those elements until GSAP reveals them. Without GSAP the class is removed
 * (and the head removes it too if this script never runs), so content is never left hidden.
 */
(() => {
    const root = document.documentElement;
    const { gsap, ScrollTrigger, SplitText } = window;

    if (!root.classList.contains('dp-anim') || !gsap || !ScrollTrigger || !SplitText) {
        root.classList.remove('dp-anim');
        return;
    }

    window.dpAnimReady = true;
    gsap.registerPlugin(ScrollTrigger, SplitText);
    gsap.defaults({ ease: 'power3.out', duration: 1 });

    // Starts when the element's top passes `at` of the viewport height. Capped just below the page's
    // max scroll so elements at the very end (the footer) still trigger; elements already on screen
    // keep a negative start and reveal right away.
    const onEnter = (trigger, at = 0.85) => ({
        trigger,
        once: true,
        start: () => Math.min(
            trigger.getBoundingClientRect().top + window.scrollY - window.innerHeight * at,
            ScrollTrigger.maxScroll(window) - 1,
        ),
    });

    gsap.utils.toArray('[data-reveal]:not([data-reveal="cover"])').forEach((el) => {
        // fromTo, not from: the CSS keeps these at visibility: hidden until now
        gsap.fromTo(el, { autoAlpha: 0, y: 40 }, { autoAlpha: 1, y: 0, scrollTrigger: onEnter(el) });
    });

    gsap.utils.toArray('[data-reveal="cover"]').forEach((el) => {
        gsap.fromTo(el,
            { autoAlpha: 0, clipPath: 'inset(18% 6% 0% 6% round 15px)' },
            { autoAlpha: 1, clipPath: 'inset(0% 0% 0% 0% round 15px)', duration: 1.4, ease: 'power2.out', clearProps: 'clipPath', scrollTrigger: onEnter(el, 0.9) },
        );
    });

    gsap.utils.toArray('[data-reveal-stagger]').forEach((el) => {
        gsap.set(el, { autoAlpha: 1 });
        gsap.from(el.children, { autoAlpha: 0, y: 50, stagger: 0.15, scrollTrigger: onEnter(el) });
    });

    gsap.utils.toArray('[data-reveal-text]').forEach((el) => {
        SplitText.create(el, {
            type: 'lines',
            mask: 'lines',
            autoSplit: true, // re-split when fonts load or the layout changes
            onSplit(self) {
                gsap.set(el, { autoAlpha: 1 });
                return gsap.from(self.lines, { yPercent: 105, stagger: 0.12, duration: 1.1, scrollTrigger: onEnter(el) });
            },
        });
    });

    const format = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 });
    gsap.utils.toArray('[data-count]').forEach((el) => {
        const value = Number(el.dataset.count);
        const target = el.firstChild; // the number text node, before the <small> unit
        if (!value || !target || target.nodeType !== Node.TEXT_NODE) return;
        const counter = { n: 0 };
        // Kept on the element so js/fund-live.js can take over when the value changes.
        el.dpCountTween = gsap.to(counter, {
            n: value,
            duration: 2,
            ease: 'power2.out',
            scrollTrigger: onEnter(el),
            onUpdate: () => { target.nodeValue = format.format(Math.round(counter.n)).replace(/ /g, ' '); },
        });
    });
})();
