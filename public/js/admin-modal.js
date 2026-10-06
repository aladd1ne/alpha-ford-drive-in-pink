/*
 * Drive in Pink — back-office confirmation modals (templates/admin/action/_modal.html.twig),
 * used instead of the browser's confirm() by the row actions.
 *
 *   <button type="button" data-modal-open="dialog-id">   opens the <dialog> of that id
 *   <button type="button" data-modal-close>              closes its dialog
 *   <input name="amount" data-default="30">              total received, reset to its default on
 *   <p data-modal-total>                                 each opening; its client / Alpha Ford split
 *                                                        is shown as it is typed
 */
(() => {
    const describe = (dialog) => {
        const input = dialog.querySelector('input[name="amount"]');
        const total = dialog.querySelector('[data-modal-total]');
        if (!input || !total) {
            return;
        }
        const share = parseInt(dialog.dataset.alphaFordShare, 10) || 0;
        const amount = input.value.trim();
        const value = parseInt(amount, 10) || 0;
        if ('' === amount) {
            total.textContent = 'Aucun encaissement : la réservation sera seulement validée.';
        } else if (value < share) {
            total.textContent = `Le montant total inclut les ${share} DT d’Alpha Ford : ${share} DT minimum.`;
        } else {
            total.textContent = `Cagnotte créditée de ${value} DT (${value - share} DT client + ${share} DT Alpha Ford).`;
        }
    };

    document.addEventListener('click', (event) => {
        const opener = event.target.closest('[data-modal-open]');
        if (opener) {
            const dialog = document.getElementById(opener.dataset.modalOpen);
            if (!dialog) {
                return;
            }
            const input = dialog.querySelector('input[name="amount"]');
            if (input) {
                input.value = input.dataset.default ?? '';
                describe(dialog);
            }
            dialog.showModal();
            (input ?? dialog.querySelector('[type="submit"]'))?.focus();
            input?.select();
            return;
        }

        const closer = event.target.closest('[data-modal-close]');
        if (closer) {
            closer.closest('dialog')?.close();
            return;
        }

        // Click on the backdrop.
        if (event.target instanceof HTMLDialogElement && event.target.matches('.dp-modal-dialog')) {
            event.target.close();
        }
    });

    document.addEventListener('input', (event) => {
        const dialog = event.target.closest('.dp-modal-dialog');
        if (dialog) {
            describe(dialog);
        }
    });

    // One submission per modal: the button is disabled once sent.
    document.addEventListener('submit', (event) => {
        const dialog = event.target.querySelector('.dp-modal-dialog[open]');
        dialog?.querySelector('[type="submit"]')?.setAttribute('disabled', 'disabled');
    });
})();
