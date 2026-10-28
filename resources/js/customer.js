for (const menu of document.querySelectorAll('.customer-account-menu')) {
 menu.addEventListener('keydown', event => { if (event.key === 'Escape' && menu.open) { menu.open = false; menu.querySelector('summary').focus(); } });
 document.addEventListener('click', event => { if (menu.open && !menu.contains(event.target)) menu.open = false; });
}
