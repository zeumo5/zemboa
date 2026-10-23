const config = document.querySelector('#cart-translations');
const t = config ? JSON.parse(config.textContent) : {};
const money = value => `${new Intl.NumberFormat(document.documentElement.lang, { maximumFractionDigits: 0 }).format(Number(value))} FCFA`;
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content;
function syncBadge(data) {
    const units = data.counts.units;
    const count = `${data.counts.distinct_products}${data.counts.unidentified_lines ? '+' : ''}`;
    if (!data.store || !data.cart_url) return;
    for (const badge of document.querySelectorAll('[data-cart-badge]')) {
        if (!data.cart_switched && !units && Number(badge.dataset.cartOwner) && Number(badge.dataset.cartOwner) !== Number(data.store.id)) continue;
        badge.dataset.cartOwner = units ? data.store.id : 0;
        badge.querySelector('[data-cart-count]').textContent = count;
        badge.href = units ? data.cart_url : badge.dataset.cartEmptyUrl;
        const label = t.badge_label.replace(':count', count).replace(':store', units ? data.store.name : badge.dataset.cartEmptyName) + (data.counts.unidentified_lines ? `. ${t.unidentified}` : '');
        badge.setAttribute('aria-label', label); badge.title = label;
        badge.querySelector('[data-cart-origin]').hidden = !(units && Number(badge.dataset.cartCurrentStore) && Number(badge.dataset.cartCurrentStore) !== Number(data.store.id));
        if (new URL(badge.href, location.href).pathname !== location.pathname) badge.removeAttribute('aria-current');
    }
}
function animateBadge() {
    if (matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    for (const badge of document.querySelectorAll('[data-cart-badge]')) {
        badge.classList.remove('cart-added'); void badge.offsetWidth; badge.classList.add('cart-added');
        setTimeout(() => badge.classList.remove('cart-added'), 450);
    }
}
let mutationBusy = false;
let requestSerial = 0, appliedSerial = 0;
async function request(url, method = 'GET', data) {
    const serial = ++requestSerial;
    const response = await fetch(url, {
        method, credentials: 'same-origin', cache: 'no-store',
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf(), ...(data ? { 'Content-Type': 'application/json' } : {}) },
        ...(data ? { body: JSON.stringify(data) } : {}),
    }).catch(() => { throw new Error(t.error); });
    const result = await response.json().catch(() => ({}));
    if (!response.ok) {
        const message = result.errors?.cart ? t.other_store : result.errors?.quantity ? t.unavailable : t.error;
        const error = new Error(result.errors?.confirmation_token ? t.switch_stale : message);
        error.foreignCart = !!result.errors?.cart;
        throw error;
    }
    if (!result.data || !Array.isArray(result.data.details)) throw new Error(t.error);
    if (serial >= appliedSerial) {
        appliedSerial = serial; syncBadge(result.data);
        document.dispatchEvent(new CustomEvent('cart:confirmed', { detail: result.data }));
    }
    return result.data;
}
function element(tag, text, className) {
    const node = document.createElement(tag);
    if (text !== undefined) node.textContent = text;
    if (className) node.className = className;
    return node;
}
// A single live notification; errors remain until dismissed or a successful retry.
const toast = element('div', undefined, 'cart-toast'); toast.hidden = true;
const toastText = element('span'); toastText.setAttribute('role', 'status'); toastText.setAttribute('aria-live', 'polite');
const dismiss = element('button', '\u00d7'); dismiss.type = 'button'; dismiss.setAttribute('aria-label', t.dismiss);
toast.append(toastText, dismiss); if (config) document.body.append(toast);
let toastTimer, toastError = false;
function expireToast() {
    clearTimeout(toastTimer);
    if (!toastError && !toast.contains(document.activeElement)) toastTimer = setTimeout(() => { toast.hidden = true; }, 3800);
}
toast.addEventListener('focusin', () => clearTimeout(toastTimer));
toast.addEventListener('focusout', expireToast);
function notify(message, error = false) {
    clearTimeout(toastTimer); toastError = error; toast.hidden = false; toast.classList.toggle('cart-toast-error', error);
    toastText.textContent = message;
    expireToast();
}
dismiss.addEventListener('click', () => {
    toast.hidden = true;
    (document.querySelector('[data-cart-page] h2, [data-cart-back], [data-cart-form] button') || document.body).focus({ preventScroll: true });
});
function stepper(input) {
    const box = element('span', undefined, 'cart-stepper'); box.dataset.quantityStepper = '';
    const minus = element('button', '\u2212'); minus.type = 'button'; minus.dataset.step = '-1'; minus.setAttribute('aria-label', t.decrease);
    const plus = element('button', '+'); plus.type = 'button'; plus.dataset.step = '1'; plus.setAttribute('aria-label', t.increase);
    box.append(minus, input, plus); wireStepper(box); return box;
}
function wireStepper(box) {
    const input = box.querySelector('input');
    const sync = () => { box.querySelector('[data-step="-1"]').disabled = Number(input.value) <= 1; };
    for (const button of box.querySelectorAll('[data-step]')) button.addEventListener('click', () => {
        input.value = Math.max(1, (Number(input.value) || 1) + Number(button.dataset.step));
        input.dispatchEvent(new Event('input', { bubbles: true })); sync();
    });
    input.addEventListener('input', sync); sync();
}
for (const box of document.querySelectorAll('[data-quantity-stepper]')) wireStepper(box);
async function mutate(container, url, method, data, message) {
    if (mutationBusy) return;
    mutationBusy = true; container.setAttribute('aria-busy', 'true');
    const focused = document.activeElement;
    const focusedId = focused?.id;
    const focusedVariant = focused?.closest('[data-cart-line]')?.dataset.cartLine;
    const focusedStep = focused?.dataset.step;
    const buttons = [...container.querySelectorAll('button, input')];
    const disabled = buttons.map(button => button.disabled); buttons.forEach(button => { button.disabled = true; });
    try { const result = await request(url, method, data); notify(message); return result; }
    catch (error) {
        if (error.foreignCart) {
            try { showForeign(await request(url.replace(/\/cart(?:\/.*)?$/, '/cart')), container); } catch { /* Keep the error and the existing cart. */ }
        }
        notify(error.message || t.error, true);
    }
    finally {
        mutationBusy = false; container.removeAttribute('aria-busy');
        buttons.forEach((button, i) => { button.disabled = disabled[i]; });
        if (!focused?.isConnected) {
            const target = (focusedId && document.getElementById(focusedId)) || (focusedVariant && container.querySelector(`[data-cart-line="${focusedVariant}"] [data-step="${focusedStep}"]:not([disabled])`)) || container.querySelector('[data-cart-items] input, button:not([disabled]):not([hidden]), a') || document.querySelector('[data-cart-back]');
            target?.focus({ preventScroll: true });
        }
    }
}
// Confirmation is separate from ordinary clear(), and never runs on navigation.
let foreignState, confirmationState;
const foreignPanel = element('aside', undefined, 'cart-foreign-panel'); foreignPanel.hidden = true; foreignPanel.dataset.cartForeign = '';
const foreignMessage = element('p');
const foreignLink = element('a', t.current_cart, 'zm-btn zm-btn-light'); foreignLink.dataset.cartCurrentLink = '';
const switchButton = element('button', t.switch_store, 'zm-btn zm-btn-dark'); switchButton.type = 'button'; switchButton.dataset.cartSwitch = '';
foreignPanel.append(foreignMessage, foreignLink, switchButton);
const switchDialog = element('dialog', undefined, 'cart-clear-dialog'); switchDialog.dataset.cartSwitchDialog = '';
const switchTitle = element('h2', t.switch_store); switchTitle.id = 'cart-switch-title';
const switchDescription = element('p', t.switch_confirm); switchDescription.id = 'cart-switch-description';
switchDialog.setAttribute('aria-labelledby', switchTitle.id); switchDialog.setAttribute('aria-describedby', switchDescription.id);
const switchActions = element('div', undefined, 'cart-page-actions');
const cancelSwitch = element('button', t.cancel, 'zm-btn zm-btn-light'); cancelSwitch.type = 'button'; cancelSwitch.autofocus = true; cancelSwitch.dataset.cartSwitchCancel = '';
const confirmSwitch = element('button', t.switch_accept, 'zm-btn cart-clear-button'); confirmSwitch.type = 'button'; confirmSwitch.dataset.cartSwitchConfirm = '';
switchActions.append(cancelSwitch, confirmSwitch); switchDialog.append(switchTitle, switchDescription, switchActions); if (config) document.body.append(switchDialog);
function showForeign(data, anchor) {
    foreignState = data.foreign_cart;
    if (!foreignState) { foreignPanel.hidden = true; return; }
    const host = anchor || document.querySelector('[data-cart-product], [data-cart-page]');
    if (!host) return;
    host.after(foreignPanel); foreignPanel.hidden = false;
    foreignMessage.textContent = foreignState.confirmation_token ? t.other_store : t.store_unavailable;
    foreignLink.hidden = !foreignState.cart_url; if (foreignState.cart_url) foreignLink.href = foreignState.cart_url;
    switchButton.hidden = !foreignState.confirmation_token;
}
switchButton.addEventListener('click', () => {
    if (mutationBusy || !foreignState?.confirmation_token) return;
    confirmationState = { ...foreignState }; switchDialog.showModal();
});
cancelSwitch.addEventListener('click', () => switchDialog.close());
confirmSwitch.addEventListener('click', async () => {
    if (mutationBusy || !switchDialog.open) return;
    switchDialog.close();
    const state = confirmationState;
    const result = await mutate(foreignPanel, state.switch_url, 'POST', { confirmation_token: state.confirmation_token }, t.switched);
    if (!result) { try { showForeign(await request(state.switch_url.replace(/\/switch$/, ''))); } catch { /* No abandonment on error. */ } }
    (document.querySelector('[data-cart-product] [type=submit], [data-cart-back]') || switchButton).focus({ preventScroll: true });
});
document.addEventListener('cart:confirmed', event => showForeign(event.detail));
for (const form of document.querySelectorAll('[data-cart-form]')) {
    form.addEventListener('submit', async event => {
        event.preventDefault(); if (!form.reportValidity() || mutationBusy) return;
        const values = new FormData(form);
        const result = await mutate(form, form.action, 'POST', { product_variant_id: Number(values.get('product_variant_id')), quantity: Number(values.get('quantity')) }, t.added);
        if (result) animateBadge();
    });
}
// A product's current quantity is independent from the quantity to add.
const productForm = document.querySelector('[data-cart-product]');
if (productForm) {
    const current = document.querySelector('[data-cart-current]');
    const select = productForm.querySelector('select'); let snapshot;
    const renderCurrent = () => {
        const line = snapshot?.details.find(item => Number(item.product_variant_id) === Number(select.value));
        current.replaceChildren(); current.hidden = !line;
        if (!line) return;
        const label = element('label', t.quantity_current); label.htmlFor = 'cart-current-quantity';
        const input = element('input'); input.id = 'cart-current-quantity'; input.type = 'number'; input.min = '1'; input.step = '1'; input.required = true; input.value = line.quantity;
        const form = element('form', undefined, 'cart-controls');
        const update = element('button', t.update, 'zm-btn zm-btn-dark'); update.type = 'submit';
        const remove = element('button', t.remove, 'zm-btn zm-btn-light'); remove.type = 'button';
        const url = `${productForm.action}/${line.product_variant_id}`;
        form.addEventListener('submit', event => { event.preventDefault(); if (form.reportValidity()) mutate(current, url, 'PATCH', { quantity: Number(input.value) }, t.updated); });
        remove.addEventListener('click', () => mutate(current, url, 'DELETE', undefined, t.removed));
        form.append(label, stepper(input), update, remove); current.append(form);
    };
    document.addEventListener('cart:confirmed', event => { snapshot = event.detail; renderCurrent(); });
    select.addEventListener('change', renderCurrent);
    request(productForm.dataset.cartUrl).catch(error => notify(error.message || t.error, true));
}
const page = document.querySelector('[data-cart-page]');
if (page) {
    const status = page.querySelector('[data-cart-status]');
    const list = page.querySelector('[data-cart-items]');
    const summary = page.querySelector('[data-cart-summary]');
    const clearButton = page.querySelector('[data-cart-clear]');
    const dialog = page.querySelector('[data-cart-clear-dialog]');
    clearButton.addEventListener('click', () => { if (!mutationBusy) dialog.showModal(); });
    page.querySelector('[data-cart-clear-cancel]').addEventListener('click', () => dialog.close());
    page.querySelector('[data-cart-clear-confirm]').addEventListener('click', () => {
        if (mutationBusy || !dialog.open) return;
        dialog.close(); mutate(page, page.dataset.clearUrl, 'DELETE', undefined, t.cleared);
    });
    const render = data => {
        clearButton.hidden = data.details.length === 0;
        list.replaceChildren(); status.textContent = data.details.length ? '' : t.empty;
        for (const item of data.details) {
            const line = element('article', undefined, 'cart-line'); line.dataset.cartLine = item.product_variant_id;
            const photo = element('div', undefined, 'commerce-photo');
            if (item.image_url) {
                const image = element('img'); image.src = item.image_url; image.alt = ''; image.loading = 'lazy'; image.width = 120; image.height = 120;
                image.addEventListener('error', () => photo.replaceChildren(element('span', t.no_photo)), { once: true }); photo.append(image);
            } else photo.append(element('span', t.no_photo));
            const content = element('div', undefined, 'cart-line-content');
            const heading = element('h2');
            if (item.product_url) { const link = element('a', item.product_name); link.href = item.product_url; heading.append(link); }
            else heading.textContent = item.product_name || t.unavailable;
            content.append(heading);
            if (item.variant_label) content.append(element('p', item.variant_label, 'commerce-variant'));
            if (item.sku) { const reference = element('details', undefined, 'commerce-reference'); reference.append(element('summary', t.reference), element('span', item.sku)); content.append(reference); }
            if (!item.available) content.append(element('p', t.unavailable, 'commerce-unavailable'));
            const bottom = element('div', undefined, 'cart-line-bottom');
            const unit = element('div', undefined, 'commerce-metric'); unit.append(element('span', t.unit_price), element('strong', item.final_unit_price === undefined ? '\u2014' : money(item.final_unit_price)));
            const subtotal = element('div', undefined, 'commerce-metric'); subtotal.append(element('span', t.line_total), element('strong', item.line_total === undefined ? '\u2014' : money(item.line_total)));
            const form = element('form', undefined, 'cart-controls');
            const input = element('input'); input.type = 'number'; input.min = '1'; input.step = '1'; input.required = true; input.value = item.quantity; input.id = `cart-qty-${item.product_variant_id}`;
            const label = element('label', t.quantity); label.htmlFor = input.id;
            const update = element('button', t.update, 'cart-update-button'); update.type = 'submit';
            const remove = element('button', t.remove, 'cart-remove-button'); remove.type = 'button';
            const url = `${page.dataset.itemsUrl}/${item.product_variant_id}`;
            let saveTimer;
            const saveQuantity = async () => {
                clearTimeout(saveTimer);
                if (!input.isConnected) return;
                if (mutationBusy) { saveTimer = setTimeout(saveQuantity, 100); return; }
                const quantity = Number(input.value);
                if (!input.checkValidity()) { input.value = item.quantity; input.dispatchEvent(new Event('input')); notify(t.unavailable, true); return; }
                if (quantity === Number(item.quantity)) return;
                const result = await mutate(page, url, 'PATCH', { quantity }, t.updated);
                if (!result && input.isConnected) { input.value = item.quantity; input.dispatchEvent(new Event('input')); }
            };
            input.addEventListener('input', () => { clearTimeout(saveTimer); saveTimer = setTimeout(saveQuantity, 650); });
            input.addEventListener('blur', () => { clearTimeout(saveTimer); saveTimer = setTimeout(saveQuantity, 0); });
            form.addEventListener('click', event => { if (event.target.closest('[data-step]')) { clearTimeout(saveTimer); saveTimer = setTimeout(saveQuantity, 220); } });
            form.addEventListener('submit', event => { event.preventDefault(); saveQuantity(); });
            remove.addEventListener('click', () => mutate(page, url, 'DELETE', undefined, t.removed));
            form.append(label, stepper(input), update, remove);
            bottom.append(unit, form, subtotal); content.append(bottom); line.append(photo, content); list.append(line);
        }
        summary.hidden = data.details.length === 0;
        const checkout = page.querySelector('[data-cart-checkout]');
        if (checkout) checkout.hidden = !data.can_checkout;
        page.querySelector('[data-cart-products]').textContent = `${data.counts.distinct_products}${data.counts.unidentified_lines ? '+' : ''}`;
        page.querySelector('[data-cart-units]').textContent = data.counts.units;
        page.querySelector('[data-cart-total]').textContent = money(data.products_subtotal);
        page.querySelector('[data-cart-unidentified]').hidden = !data.counts.unidentified_lines;
        document.body.classList.add('cart-summary-page');
    };
    new ResizeObserver(() => document.body.style.setProperty('--cart-summary-height', `${summary.getBoundingClientRect().height}px`)).observe(summary);
    document.addEventListener('cart:confirmed', event => render(event.detail));
    request(page.dataset.url).catch(error => { status.textContent = ''; notify(error.message || t.error, true); });
}
// Remember only public same-origin GET navigation; never store auth URLs or tokens.
function publicUrl(value) {
    if (!value) return null;
    try {
        const url = new URL(value, location.origin);
        if (url.origin !== location.origin || url.username || url.password || !/^\/(?:$|produits\/?$|boutiques(?:\/[a-zA-Z0-9_-]+(?:\/(?:produits(?:\/\d+)?|categories|a-propos|livraison-retrait|panier))?)?\/?$)/.test(url.pathname)) return null;
        const safe = new URL(url.pathname, location.origin);
        for (const key of ['q', 'category', 'page', 'sort']) for (const value of url.searchParams.getAll(key)) safe.searchParams.append(key, value);
        return safe.pathname + safe.search;
    } catch { return null; }
}
try {
    const key = 'zemboa.public-navigation';
    const previous = JSON.parse(sessionStorage.getItem(key) || 'null');
    const here = publicUrl(location.href);
    const back = document.querySelector('[data-cart-back]');
    const referrer = publicUrl(document.referrer);
    const remembered = JSON.parse(sessionStorage.getItem('zemboa.public-returns') || '{}');
    const candidate = previous && previous.url !== here ? previous : { url: referrer, y: 0 };
    const candidateUrl = publicUrl(candidate?.url);
    const source = candidateUrl && candidateUrl !== here && !candidateUrl.endsWith('/panier') ? candidate : remembered[here];
    const destination = publicUrl(source?.url);
    if (here && destination && destination !== here && !destination.endsWith('/panier')) {
        remembered[here] = { url: destination, y: source?.y || 0 };
        sessionStorage.setItem('zemboa.public-returns', JSON.stringify(Object.fromEntries(Object.entries(remembered).slice(-20))));
    }
    if (back && destination && destination !== here && !destination.endsWith('/panier')) {
        back.href = destination;
        back.addEventListener('click', () => sessionStorage.setItem('zemboa.restore-scroll', JSON.stringify({ url: destination, y: source?.y || 0 })));
    }
    const restore = JSON.parse(sessionStorage.getItem('zemboa.restore-scroll') || 'null');
    if (restore?.url === here) {
        sessionStorage.removeItem('zemboa.restore-scroll');
        requestAnimationFrame(() => requestAnimationFrame(() => scrollTo(0, Math.max(0, Number(restore.y) || 0))));
    }
    if (here) {
        let timer;
        const save = () => sessionStorage.setItem(key, JSON.stringify({ url: here, y: scrollY }));
        save(); addEventListener('pagehide', save);
        addEventListener('scroll', () => { clearTimeout(timer); timer = setTimeout(save, 150); }, { passive: true });
    }
} catch { /* Storage may be disabled; the safe Blade fallback remains usable. */ }
