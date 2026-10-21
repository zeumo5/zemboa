/** Merchant navigation and locale enhancement. All catalogue links/forms work natively. */
const merchant = document.querySelector('.merchant-site');
if (merchant) {
    const header = merchant.querySelector('.merchant-header');
    if ('ResizeObserver' in window) {
        new ResizeObserver(() => merchant.style.setProperty('--merchant-header-height', header.getBoundingClientRect().height + 'px')).observe(header);
    }
    const collapse = merchant.querySelector('[data-merchant-collapse]');
    if (collapse) {
        merchant.classList.add('merchant-sidebar-ready');
        collapse.addEventListener('click', () => {
            const collapsed = merchant.classList.toggle('merchant-sidebar-collapsed');
            collapse.setAttribute('aria-expanded', String(!collapsed));
            collapse.setAttribute('aria-label', collapsed ? collapse.dataset.expandLabel : collapse.dataset.collapseLabel);
        });
    }
    const drawer = merchant.querySelector('#merchant-drawer');
    const trigger = merchant.querySelector('[data-merchant-open]');
    const narrow = window.matchMedia('(max-width: 960px)');
    if (drawer && trigger && typeof drawer.showModal === 'function') {
        merchant.classList.add('merchant-enhanced');
        const close = () => drawer.close();
        trigger.addEventListener('click', () => { drawer.showModal(); trigger.setAttribute('aria-expanded', 'true'); });
        drawer.querySelector('[data-merchant-close]').addEventListener('click', close);
        drawer.addEventListener('close', () => { trigger.setAttribute('aria-expanded', 'false'); if (narrow.matches) trigger.focus(); });
        drawer.addEventListener('click', event => {
            const rect = drawer.getBoundingClientRect();
            if (event.target === drawer && (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom)) close();
        });
        narrow.addEventListener('change', () => { if (!narrow.matches && drawer.open) close(); });
    }
    merchant.querySelectorAll('[data-merchant-locale]').forEach(form => {
        form.addEventListener('submit', async event => {
            event.preventDefault();
            const button = form.querySelector('button'); button.disabled = true;
            try {
                const response = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' } });
                if (!response.ok) throw new Error('Locale unavailable');
                window.location.reload();
            } catch { button.disabled = false; button.focus(); }
        });
    });
}
