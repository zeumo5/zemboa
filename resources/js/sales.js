for (const form of document.querySelectorAll('[data-sales-confirm]')) {
    let submitted = false;
    form.addEventListener('submit', event => {
        if (submitted) { event.preventDefault(); return; }
        if (!form.checkValidity()) return;
        if (!window.confirm(form.dataset.salesConfirm)) { event.preventDefault(); return; }
        submitted = true;
        form.setAttribute('aria-busy', 'true');
        // Preserve the chosen decision when disabling its submit button.
        if (event.submitter?.name) {
            const field = document.createElement('input');
            field.type = 'hidden'; field.name = event.submitter.name; field.value = event.submitter.value;
            field.dataset.salesDecision = 'true'; form.append(field);
        }
        for (const button of form.querySelectorAll('button[type=submit]')) button.disabled = true;
    });
    window.addEventListener('pageshow', () => {
        submitted = false; form.removeAttribute('aria-busy');
        for (const field of form.querySelectorAll('[data-sales-decision]')) field.remove();
        for (const button of form.querySelectorAll('button[type=submit]')) button.disabled = false;
    });
}
