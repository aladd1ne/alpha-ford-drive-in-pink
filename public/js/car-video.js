/*
 * Drive in Pink — background experience videos (templates/_car_video.html.twig).
 * Plays each video only while it is on screen and pauses it off screen, to spare mobile
 * data and battery. Left on its poster photo with reduced motion or data saver on, and
 * when the browser refuses to autoplay (e.g. iOS Low Power Mode).
 * Marks the frame .is-cueing while waiting and .is-rolling once the video starts,
 * for the cinematic fade-in.
 */
(() => {
    const videos = [...document.querySelectorAll('[data-car-video]')];
    const reducedMotion = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
    const saveData = navigator.connection && navigator.connection.saveData;
    if (!videos.length || reducedMotion || saveData) {
        return;
    }

    // First frame on screen: lift the black veil and start the slow push-in (.is-rolling in front.css).
    videos.forEach((video) => {
        video.parentElement.classList.add('is-cueing');
        video.addEventListener('playing', () => {
            video.parentElement.classList.add('is-rolling');
        }, {once: true});
    });

    const play = (video) => {
        // Set again in JS: some browsers only honour the property, not the attribute, for autoplay.
        video.muted = true;
        const playing = video.play();
        if (playing && playing.catch) {
            playing.catch(() => {
                // autoplay refused: the poster stays, unveiled, until the first touch
                // (a user gesture lets play() through, e.g. iOS Low Power Mode)
                video.parentElement.classList.remove('is-cueing');
                // (touchstart is not a gesture on iOS: touchend/click are; keep trying until it plays)
                const gestures = ['touchend', 'click'];
                const retry = () => {
                    video.play().then(() => {
                        gestures.forEach((type) => document.removeEventListener(type, retry));
                    }, () => {});
                };
                gestures.forEach((type) => document.addEventListener(type, retry, {passive: true}));
            });
        }
    };

    if (!('IntersectionObserver' in window)) {
        videos.forEach(play);
        return;
    }

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(({target, isIntersecting}) => {
            if (isIntersecting) {
                play(target);
            } else {
                target.pause();
            }
        });
    }, {threshold: 0.25});
    videos.forEach((video) => observer.observe(video));
})();
