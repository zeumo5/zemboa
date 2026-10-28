const storeEditor = document.querySelector('[data-store-editor]');
if (storeEditor) {
    const urls = new Map();
    storeEditor.addEventListener('change', event => {
        const input = event.target;
        if (input.matches('[data-brand-upload]')) {
            const slot = input.dataset.brandUpload;
            if (urls.has(slot)) URL.revokeObjectURL(urls.get(slot));
            const file = input.files?.[0];
            if (!file) return;
            const url = URL.createObjectURL(file);
            urls.set(slot, url);
            const image = storeEditor.querySelector(`[data-brand-image="${slot}"]`);
            image.src = url;
            image.hidden = false;
            storeEditor.querySelector(`[data-brand-empty="${slot}"]`).hidden = true;
        }
        if (input.matches('[data-preview-page]')) storeEditor.querySelector('[data-store-preview]').src = input.value;
    });
    storeEditor.addEventListener('submit', event => {
        const message = event.target.dataset.storeConfirm;
        if (message && !window.confirm(message)) event.preventDefault();
    });
    storeEditor.addEventListener('click', event => {
        const button = event.target.closest('[data-preview-width]');
        if (!button) return;
        storeEditor.querySelector('[data-store-preview]').style.width = button.dataset.previewWidth;
        storeEditor.querySelectorAll('[data-preview-width]').forEach(control => control.setAttribute('aria-pressed', String(control === button)));
    });
    window.addEventListener('pagehide', () => urls.forEach(url => URL.revokeObjectURL(url)), {once: true});
}
