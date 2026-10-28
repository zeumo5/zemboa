for (const form of document.querySelectorAll('[data-inventory-operation]')) {
    let submitted = false;
    form.addEventListener('submit', event => {
        if (submitted) { event.preventDefault(); return; }
        if (!form.checkValidity()) return;
        submitted = true;
        form.setAttribute('aria-busy', 'true');
        form.querySelector('button[type="submit"]').disabled = true;
    });
    window.addEventListener('pageshow', () => {
        submitted = false;
        form.removeAttribute('aria-busy');
        form.querySelector('button[type="submit"]').disabled = false;
    });
}
