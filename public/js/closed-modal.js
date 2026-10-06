/*
 * Drive in Pink — "inscriptions pas encore ouvertes" pop-up.
 * Links with [data-closed-message] open it instead of navigating; the server also redirects
 * those URLs to the hub with a flash, in which case the dialog is rendered with [data-open].
 */
(() => {
    const modal = document.querySelector('[data-closed-modal]');
    if (!modal || typeof modal.showModal !== 'function') {
        return;
    }
    const message = modal.querySelector('[data-closed-modal-message]');

    const open = (text) => {
        if (text) {
            message.textContent = text;
        }
        modal.showModal();
    };

    document.addEventListener('click', (event) => {
        const link = event.target.closest('[data-closed-message]');
        if (link) {
            event.preventDefault();
            open(link.dataset.closedMessage);
        }
    });

    // Click on the backdrop closes it.
    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            modal.close();
        }
    });

    if (modal.hasAttribute('data-open')) {
        open();
    }
})();
