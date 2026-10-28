const posRoot = document.querySelector('[data-pos-interface]');
if (posRoot) {
    let busy = false;
    const feedback = posRoot.querySelector('[data-pos-feedback]');
    posRoot.addEventListener('click', event => {
        const button = event.target.closest('button[data-pos-step]');
        if (!button || busy || button.disabled) return;
        const form = button.closest('form');
        const quantity = form.querySelector('input[name=quantity]');
        const value = Number(quantity.value) + Number(button.dataset.posStep);
        if (!Number.isInteger(value) || value < Number(quantity.min) || value > Number(quantity.max)) return;
        quantity.value = String(value);
        form.requestSubmit(form.querySelector('[data-pos-set]'));
    });
    const report = (message, error = false) => {
        feedback.textContent = message;
        feedback.classList.toggle('pos-feedback-error', error);
        feedback.setAttribute('role', error ? 'alert' : 'status');
        if (error) feedback.focus();
    };
    posRoot.addEventListener('submit', async event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-pos-cart],[data-pos-sale],[data-pos-operation]')) return;
        event.preventDefault();
        if (busy || !form.reportValidity()) return;
        const data = new FormData(form);
        if (form.matches('[data-pos-sale]')) {
            data.set('key', posRoot.querySelector('[data-pos-key]').value);
            data.set('quote_hash', posRoot.querySelector('[data-pos-quote]').value);
            data.set('mode', event.submitter?.value || 'UNPAID');
            if (data.get('mode') === 'CASH' && !window.confirm(event.submitter.textContent.trim() + ' ?')) return;
        }
        let url = form.action;
        if (form.hasAttribute('data-pos-open')) {
            url = data.get('register');
            data.delete('register');
        }
        const target = new URL(url, window.location.href);
        if (target.origin !== window.location.origin) return;
        busy = true;
        const controls = [...posRoot.querySelectorAll('button')].filter(button => !button.disabled);
        controls.forEach(button => { button.disabled = true; });
        posRoot.setAttribute('aria-busy', 'true');
        report(posRoot.dataset.loading);
        try {
            const response = await fetch(target.href, {
                method: 'POST', body: data, credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
            });
            const result = await response.json();
            if (!response.ok) {
                const errors = result.errors ? Object.values(result.errors).flat().filter(value => typeof value === 'string') : [];
                report(errors.length ? errors.join(' ') : posRoot.dataset.error, true);
                return;
            }
            if (form.matches('[data-pos-cart]')) {
                posRoot.querySelector('[data-pos-basket]').innerHTML = result.html;
                report(posRoot.dataset.updated);
            } else if (result.redirect) {
                const destination = new URL(result.redirect, window.location.href);
                if (destination.origin !== window.location.origin) throw new Error('Invalid POS destination');
                window.location.assign(destination.href);
            } else window.location.reload();
        } catch {
            report(posRoot.dataset.error, true);
        } finally {
            busy = false;
            controls.forEach(button => { if (button.isConnected) button.disabled = false; });
            posRoot.removeAttribute('aria-busy');
        }
    });
}
