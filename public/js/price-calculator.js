/**
 * Price calculator — works on both the experience detail page
 * and the booking form page.
 *
 * Detail page:  updates #total-price and rewrites the href of #book-now-btn
 *               so selected extras are carried to the booking form as ?extras[]=ID
 *
 * Booking page: updates #total-price and injects a summary into #summary-extras
 */

(function () {
    'use strict';

    const basePriceEl = document.getElementById('base-price');
    const totalEl     = document.getElementById('total-price');
    const checkboxes  = document.querySelectorAll('.extra-checkbox');
    const bookBtn     = document.getElementById('book-now-btn');
    const summaryExtras = document.getElementById('summary-extras');

    if (!basePriceEl || !totalEl) return;

    const basePrice = parseFloat(basePriceEl.dataset.price) || 0;

    function getSelectedExtras() {
        return Array.from(checkboxes).filter(cb => cb.checked);
    }

    function calcTotal() {
        const selected = getSelectedExtras();
        const extrasSum = selected.reduce(function (acc, cb) {
            return acc + (parseFloat(cb.dataset.price) || 0);
        }, 0);
        return basePrice + extrasSum;
    }

    function formatTND(amount) {
        return amount.toFixed(0) + ' TND';
    }

    function updateTotal() {
        const total = calcTotal();
        totalEl.textContent = formatTND(total);

        // Animate the value briefly
        totalEl.style.transform = 'scale(1.06)';
        totalEl.style.transition = 'transform .15s ease';
        setTimeout(function () {
            totalEl.style.transform = 'scale(1)';
        }, 150);
    }

    // Detail page: update "Book Now" link URL with selected extra IDs
    function updateBookNowLink() {
        if (!bookBtn) return;
        const base = bookBtn.href.split('?')[0];
        const selected = getSelectedExtras();
        if (selected.length === 0) {
            bookBtn.href = base;
            return;
        }
        const params = selected.map(function (cb) {
            return 'extras%5B%5D=' + encodeURIComponent(cb.dataset.id);
        }).join('&');
        bookBtn.href = base + '?' + params;
    }

    // Booking form page: update the order summary panel
    function updateSummaryExtras() {
        if (!summaryExtras) return;
        summaryExtras.innerHTML = '';
        getSelectedExtras().forEach(function (cb) {
            const row = document.createElement('div');
            row.className = 'summary-line';
            const name = document.createElement('span');
            name.textContent = cb.closest('.extra-item')
                ? cb.closest('.extra-item').querySelector('.extra-name').textContent
                : 'Option';
            const price = document.createElement('span');
            price.textContent = '+' + parseFloat(cb.dataset.price).toFixed(0) + ' TND';
            row.appendChild(name);
            row.appendChild(price);
            summaryExtras.appendChild(row);
        });
    }

    function onChanged() {
        updateTotal();
        updateBookNowLink();
        updateSummaryExtras();
    }

    checkboxes.forEach(function (cb) {
        cb.addEventListener('change', onChanged);
    });

    // Run once on load to reflect pre-checked state (booking form page)
    onChanged();
}());
