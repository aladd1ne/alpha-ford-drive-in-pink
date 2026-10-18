/*
 * Drive in Pink — back-office confirmation modals (templates/admin/action/_modal.html.twig),
 * used instead of the browser's confirm() by the row actions.
 *
 *   <button type="button" data-modal-open="dialog-id">   opens the <dialog> of that id
 *   <button type="button" data-modal-close>              closes its dialog
 *   <input data-default="...">                           reset to that value on each opening
 *   <input type="checkbox" data-default-checked>         ticked again on each opening
 *   <input name="client_amount|alpha_ford_amount">       the two parts of a credit being corrected,
 *   <p data-modal-split-total>                           their sum shown as they are typed
 */
(() => {
    const describe = (dialog) => {
        const total = dialog.querySelector('[data-modal-split-total]');
        if (!total) {
            return;
        }
        const client = parseInt(dialog.querySelector('input[name="client_amount"]')?.value, 10) || 0;
        const alphaFord = parseInt(dialog.querySelector('input[name="alpha_ford_amount"]')?.value, 10) || 0;
        total.textContent = `Nouveau montant : ${client + alphaFord} DT (${client} DT client + ${alphaFord} DT Alpha Ford).`;
    };

    document.addEventListener('click', (event) => {
        const opener = event.target.closest('[data-modal-open]');
        if (opener) {
            const dialog = document.getElementById(opener.dataset.modalOpen);
            if (!dialog) {
                return;
            }
            dialog.querySelectorAll('input[data-default]').forEach((field) => {
                field.value = field.dataset.default;
            });
            dialog.querySelectorAll('input[data-default-checked]').forEach((field) => {
                field.checked = true;
            });
            describe(dialog);
            const input = dialog.querySelector('input[data-default]');
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
