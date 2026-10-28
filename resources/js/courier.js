for (const form of document.querySelectorAll('[data-courier-transition]')) {
    form.addEventListener('submit', event => {
        if (form.dataset.submitting === 'true') {
            event.preventDefault();
            return;
        }
        form.dataset.submitting = 'true';
        form.setAttribute('aria-busy', 'true');
        for (const button of form.querySelectorAll('button[type="submit"]')) {
            button.disabled = true;
            button.textContent = form.dataset.working;
        }
    });
}
window.addEventListener('pageshow', event => {
    if (event.persisted && document.querySelector('[data-courier-transition][data-submitting="true"]')) {
        window.location.reload();
    }
});
