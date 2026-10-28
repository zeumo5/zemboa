for (const button of document.querySelectorAll('[data-password-toggle]')) {
    button.addEventListener('click', () => {
        const input = document.getElementById(button.dataset.passwordToggle);
        if (!input) return;
        const visible = input.type === 'password';
        input.type = visible ? 'text' : 'password';
        button.setAttribute('aria-pressed', String(visible));
        button.textContent = visible ? button.dataset.hide : button.dataset.show;
    });
}
for (const button of document.querySelectorAll('[data-auth-language]')) {
    button.addEventListener('click', async () => {
        button.disabled = true;
        try {
            const response = await fetch(button.dataset.url, {
                method: button.dataset.method, credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify({ locale: button.dataset.authLanguage }),
            });
            if (!response.ok) throw new Error('locale');
            location.reload();
        } catch {
            document.querySelector('[data-auth-language-error]').hidden = false;
            button.disabled = false;
        }
    });
}
