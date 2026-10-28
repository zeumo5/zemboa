const detail = document.querySelector('[data-product-detail]');
if (detail) {
 const select = detail.querySelector('#cart-variant');
 const sync = () => { for (const row of detail.querySelectorAll('[data-product-variant]')) { const selected = row.dataset.productVariant === select?.value; row.dataset.selected = String(selected); if (selected) { detail.querySelector('[data-product-net]').textContent = row.dataset.price;
const base = Number(row.dataset.basePrice), net = Number(row.dataset.netPrice), reduced = base > net;
const original = detail.querySelector('[data-product-original]'), reduction = detail.querySelector('[data-product-reduction]');
original.hidden = !reduced; original.textContent = new Intl.NumberFormat(document.documentElement.lang).format(base) + ' FCFA';
reduction.hidden = !reduced; reduction.textContent = reduced ? '−' + (Math.floor((base - net) * 100 / base) || '<1') + '%' : ''; detail.querySelector('[data-product-stock]').textContent = row.dataset.stock; } } };
 select?.addEventListener('change', sync); sync();
 for (const button of detail.querySelectorAll('[data-product-image]')) button.addEventListener('click', () => { const hero = detail.querySelector('.zm-detail-visual>img'); if (!hero) return; hero.src = button.dataset.productImage; for (const other of detail.querySelectorAll('[data-product-image]')) other.setAttribute('aria-pressed', String(other === button)); });
}
