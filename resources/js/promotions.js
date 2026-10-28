for (const form of document.querySelectorAll('[data-promotion-clear]')) {
    form.addEventListener('submit', (event) => {
        if (!window.confirm(form.dataset.confirm)) event.preventDefault();
    });
}
