for (const form of document.querySelectorAll('[data-catalog-confirm]')) {
    form.addEventListener('submit', event => {
        if (!window.confirm(form.dataset.catalogConfirm)) event.preventDefault();
    });
}
for (const input of document.querySelectorAll('[data-catalog-image]')) {
    let previewUrl;
    input.addEventListener('change', () => {
        const preview = input.closest('[data-catalog-upload]').querySelector('[data-catalog-preview]');
        if (previewUrl) URL.revokeObjectURL(previewUrl);
        const file = input.files[0];
        preview.hidden = !file || !['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 5 * 1024 * 1024;
        if (!preview.hidden) { previewUrl = URL.createObjectURL(file); preview.src = previewUrl; }
        else preview.removeAttribute('src');
    });
}
