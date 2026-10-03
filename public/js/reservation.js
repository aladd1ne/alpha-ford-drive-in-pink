/*
 * Drive in Pink — reservation form (progressive enhancement; the server re-checks everything).
 *
 * Steps: date → vehicle → slot.
 *  - vehicles stay locked until a date is chosen; a vehicle without any free slot that day is "Complet";
 *  - slots stay locked until date + vehicle are chosen, only the slots offered that day are shown,
 *    full ones are disabled ("Complet");
 *  - the chosen vehicle's photo replaces the visual above the form.
 */
(() => {
    const form = document.querySelector('form.dp-form');
    if (!form) {
        return;
    }

    const map = JSON.parse(form.dataset.availability || '{}');
    const fixedVehicle = form.dataset.vehicle || null;
    const vehicleLabels = [...form.querySelectorAll('.dp-choice--vehicle')];
    const slotLabels = [...form.querySelectorAll('[data-slot]')];
    const hasSlots = slotLabels.length > 0;
    const preview = document.querySelector('[data-vehicle-preview]');
    const previewInitial = preview ? preview.innerHTML : '';
    const previewHidden = preview ? preview.hidden : true;

    const checked = (name) => form.querySelector(`input[name$="[${name}]"]:checked`);
    const dateValue = () => checked('date')?.value
        ?? (form.querySelector('input[type="date"][name$="[date]"]')?.value || null);
    const slotsLeft = (date, vehicle) => map[date]?.[vehicle] ?? {};

    function setStep(name, locked, message) {
        const fieldset = form.querySelector(`[data-step="${name}"]`);
        if (!fieldset) {
            return;
        }
        fieldset.classList.toggle('is-locked', locked);
        const hint = fieldset.querySelector('[data-step-hint]');
        hint.textContent = message;
        hint.hidden = !message;
    }

    function setChoice(label, enabled, full) {
        const input = label.querySelector('input');
        input.disabled = !enabled;
        if (!enabled) {
            input.checked = false;
        }
        label.classList.toggle('is-full', full);
    }

    function updatePreview() {
        if (!preview) {
            return;
        }
        const label = checked('vehicle')?.closest('label');
        const src = label?.dataset.image;
        if (!src) {
            preview.innerHTML = previewInitial;
            preview.hidden = previewHidden;
            return;
        }
        const img = new Image();
        img.className = 'dp-car dp-car--cutout';
        img.dataset.vehicle = label.dataset.vehicle;
        img.src = src;
        img.alt = label.querySelector('.dp-vehicle__name')?.textContent.trim() ?? '';
        preview.replaceChildren(img);
        preview.hidden = false;
    }

    function refresh() {
        const date = dateValue();

        // Step 2 — vehicle: needs a date; on event days, at least one free slot that day.
        let vehiclesOpen = 0;
        vehicleLabels.forEach((label) => {
            const free = Boolean(date) && (!hasSlots || Object.values(slotsLeft(date, label.dataset.vehicle)).some((n) => n > 0));
            setChoice(label, free, Boolean(date) && !free);
            vehiclesOpen += free ? 1 : 0;
        });
        if (vehicleLabels.length) {
            setStep('vehicle', !date, !date
                ? 'Choisissez d’abord une date.'
                : (vehiclesOpen ? '' : 'Plus aucun véhicule disponible ce jour-là : essayez une autre date.'));
        }

        // Step 3 — slot: needs date + vehicle.
        if (hasSlots) {
            const vehicle = fixedVehicle ?? checked('vehicle')?.value ?? null;
            const ready = Boolean(date && vehicle);
            const remaining = ready ? slotsLeft(date, vehicle) : {};
            let slotsOpen = 0;

            slotLabels.forEach((label) => {
                const offered = ready && label.dataset.slot in remaining;
                const left = offered ? remaining[label.dataset.slot] : 0;
                label.hidden = ready && !offered;
                setChoice(label, left > 0, offered && left < 1);
                slotsOpen += left > 0 ? 1 : 0;
            });

            let message = '';
            if (!date) {
                message = fixedVehicle ? 'Choisissez d’abord une date.' : 'Choisissez d’abord une date et un véhicule.';
            } else if (!vehicle) {
                message = 'Choisissez d’abord un véhicule.';
            } else if (!slotsOpen) {
                message = 'Aucun créneau disponible pour ce choix : essayez une autre date ou un autre véhicule.';
            }
            setStep('slot', !ready, message);
        }

        updatePreview();
    }

    form.addEventListener('change', (event) => {
        if (/\[(date|vehicle)\]$/.test(event.target.name)) {
            refresh();
        }
    });
    form.addEventListener('input', (event) => {
        if (event.target.type === 'date') {
            refresh();
        }
    });
    refresh();
})();

/*
 * Wizard: shows one [data-wizard-step] at a time (contact details first) with a progress bar,
 * Back / Continue buttons and a recap on the last step. Choosing a date or a vehicle moves on
 * automatically; picking the slot (last step) updates the recap.
 */
(() => {
    const form = document.querySelector('form[data-wizard]');
    const steps = form ? [...form.querySelectorAll('[data-wizard-step]')] : [];
    if (steps.length < 2) {
        return;
    }

    const progress = form.querySelector('[data-wizard-progress]');
    const prevButton = form.querySelector('[data-wizard-prev]');
    const nextButton = form.querySelector('[data-wizard-next]');
    const submitButton = form.querySelector('button[type="submit"]');
    const recap = form.querySelector('[data-wizard-recap]');
    let current = 0;
    let reached = 0;

    form.classList.add('is-wizard');
    progress.hidden = false;

    const items = steps.map((step, index) => {
        const item = document.createElement('li');
        item.className = 'dp-wizard__item';
        item.innerHTML = `<button type="button"><span class="dp-wizard__num">${index + 1}</span><span class="dp-wizard__title"></span></button>`;
        item.querySelector('.dp-wizard__title').textContent = step.dataset.title;
        item.querySelector('button').addEventListener('click', () => {
            if (index <= reached) {
                go(index);
            }
        });
        progress.append(item);

        const error = document.createElement('p');
        error.className = 'dp-wizard__error';
        error.hidden = true;
        step.prepend(error);

        return item;
    });

    function isComplete(step) {
        const radios = step.querySelectorAll('input[type="radio"]');
        if (radios.length) {
            return [...radios].some((radio) => radio.checked);
        }

        return [...step.querySelectorAll('input')].every((input) => input.checkValidity());
    }

    function explain(step) {
        const radios = step.querySelectorAll('input[type="radio"]');
        if (radios.length) {
            const error = step.querySelector('.dp-wizard__error');
            error.textContent = 'Veuillez faire un choix pour continuer.';
            error.hidden = false;

            return;
        }
        [...step.querySelectorAll('input')].find((input) => !input.checkValidity())?.reportValidity();
    }

    function text(selector) {
        return form.querySelector(selector)?.closest('label')?.querySelector('span:not(.dp-vehicle__img)')?.textContent.trim() ?? '';
    }

    function renderRecap() {
        const dateInput = form.querySelector('input[type="date"][name$="[date]"]');
        let date = text('input[name$="[date]"]:checked');
        if (!date && dateInput?.value) {
            date = new Date(`${dateInput.value}T12:00:00`).toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' });
            date = date.charAt(0).toUpperCase() + date.slice(1);
        }
        const vehicle = form.dataset.vehicleLabel
            || form.querySelector('input[name$="[vehicle]"]:checked')?.closest('label')?.querySelector('.dp-vehicle__name')?.textContent.trim()
            || '';
        const name = form.querySelector('input[name$="[fullName]"]')?.value.trim() ?? '';
        const rows = [['Nom', name], ['Date', date], ['Véhicule', vehicle], ['Créneau', text('input[name$="[slot]"]:checked')]]
            .filter(([, value]) => value);

        recap.replaceChildren(...rows.flatMap(([term, value]) => {
            const dt = document.createElement('dt');
            const dd = document.createElement('dd');
            dt.textContent = term;
            dd.textContent = value;

            return [dt, dd];
        }));
        recap.hidden = rows.length === 0;
    }

    function go(index) {
        current = index;
        reached = Math.max(reached, index);
        const last = index === steps.length - 1;

        steps.forEach((step, i) => {
            step.hidden = i !== index;
        });
        items.forEach((item, i) => {
            item.classList.toggle('is-current', i === index);
            item.classList.toggle('is-done', i < index || (i <= reached && i !== index && isComplete(steps[i])));
            item.querySelector('button').disabled = i > reached;
        });
        prevButton.hidden = index === 0;
        nextButton.hidden = last;
        submitButton.hidden = !last;
        recap.hidden = true;
        if (last) {
            renderRecap();
        }
        if (form.getBoundingClientRect().top < 0) {
            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    function advance() {
        const step = steps[current];
        if (!isComplete(step)) {
            explain(step);

            return;
        }
        step.querySelector('.dp-wizard__error').hidden = true;
        go(current + 1);
    }

    nextButton.addEventListener('click', advance);
    prevButton.addEventListener('click', () => go(current - 1));

    form.addEventListener('change', (event) => {
        const step = steps[current];
        if (event.target.type !== 'radio' || !step.contains(event.target)) {
            return;
        }
        // A new choice may invalidate later steps (e.g. another day): they must be done again.
        reached = current;
        step.querySelector('.dp-wizard__error').hidden = true;
        if (current < steps.length - 1) {
            setTimeout(() => go(current + 1), 250);
        } else {
            renderRecap();
        }
    });

    form.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && event.target.tagName === 'INPUT' && current < steps.length - 1) {
            event.preventDefault();
            advance();
        }
    });

    form.addEventListener('submit', (event) => {
        const incomplete = steps.findIndex((step) => !isComplete(step));
        if (incomplete !== -1 && incomplete < steps.length - 1) {
            event.preventDefault();
            go(incomplete);
            explain(steps[incomplete]);
        }
    });

    // After a server-side error, open the first step that shows one.
    const withError = steps.findIndex((step) => step.querySelector('ul li'));
    reached = withError === -1 ? 0 : withError;
    go(reached);
})();
