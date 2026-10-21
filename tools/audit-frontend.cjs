/* Local, anonymous, read-only UI audit. No application bootstrap or database driver. */
'use strict';
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { execFileSync } = require('node:child_process');
const ROOT = path.resolve(__dirname, '..');
const BASE = 'http://127.0.0.1:8016';
const OUT = path.join(ROOT, 'storage/demo/audits');
const WIDTHS = [{ width: 1440, height: 1000 }, { width: 768, height: 1024 }, { width: 390, height: 844 }];
const publicPath = p => ['/', '/produits', '/boutiques'].includes(p) || /^\/boutiques\/[a-z0-9-]+(?:\/(?:produits(?:\/\d+)?|categories|a-propos|livraison-retrait))?$/.test(p);
function allowed(raw, document = false) {
    try {
        const u = new URL(raw);
        if (u.origin !== BASE || u.username || u.password || /%2f|%5c|\.\./i.test(u.pathname)) return false;
        if (publicPath(u.pathname)) return [...u.searchParams.keys()].every(k => ['q', 'category', 'page'].includes(k));
        return !document && /^(?:\/build\/|\/marketplace\/|\/images\/|\/fonts\/|\/favicon\.(?:svg|ico)$)/.test(u.pathname)
            && /\.(?:css|js|woff2?|svg|png|jpe?g|webp|ico)$/.test(u.pathname);
    } catch { return false; }
}
const safePath = raw => { try { const u = new URL(raw); return u.origin === BASE && publicPath(u.pathname) ? u.pathname : '[ressource masquée]'; } catch { return '[masqué]'; } };
function requestAllowed(method, url, navigation = false) { return ['GET', 'HEAD'].includes(method) && allowed(url, navigation); }
function fingerprint() {
    const file = path.join(ROOT, 'database/zemboa_demo.sqlite');
    if (!fs.existsSync(file)) throw new Error('SQLite DEMO absente : audit arrêté.');
    return Object.fromEntries(['', '-wal'].map(s => [s || 'main', fs.existsSync(file + s) ? crypto.createHash('sha256').update(fs.readFileSync(file + s)).digest('hex') : null]));
}
function serverGuard() {
    // Only booleans are returned; never expose command lines or environment values.
    const ps = "$ErrorActionPreference='Stop'; $listeners=@(Get-NetTCPConnection -LocalPort 8016 -State Listen); if(!$listeners.Count){throw 'no listener'}; foreach($l in $listeners){ if($l.LocalAddress -ne '127.0.0.1'){throw 'nonlocal listener'}; $p=Get-CimInstance Win32_Process -Filter ('ProcessId = '+$l.OwningProcess); if($p.Name -ne 'php.exe' -or $p.CommandLine -notmatch 'tools[/\\\\]demo-router.php' -or $p.CommandLine -notmatch '127.0.0.1:8016'){throw 'not demo router'} }; Write-Output 'DEMO_CONFIRMED'";
    const confirmation = execFileSync('powershell.exe', ['-NoProfile', '-NonInteractive', '-Command', ps], { encoding: 'utf8', timeout: 30000, stdio: ['ignore', 'pipe', 'pipe'] });
    if (confirmation.trim() !== 'DEMO_CONFIRMED') throw new Error('Serveur DEMO non confirmé.');
}
function sourceFor(url, type) {
    if (type === 'console') return 'resources/js/storefront.js ; resources/js/marketplace-polish.js ; resources/js/app.js';
    return url.startsWith('/boutiques/') ? 'resources/css/storefront.css ; resources/views/marketplace/merchant/ ; resources/views/marketplace/product.blade.php' : 'resources/css/app.css ; resources/views/marketplace/layout.blade.php ; resources/views/marketplace/';
}
async function main() {
    serverGuard();
    const before = fingerprint();
    const { chromium } = require(path.join(ROOT, 'storage/demo/audit-tooling/node_modules/playwright-core'));
    const candidates = [
        ['Google Chrome', path.join(process.env.LOCALAPPDATA || '', 'Google/Chrome/Application/chrome.exe')],
        ['Google Chrome', 'C:/Program Files/Google/Chrome/Application/chrome.exe'],
        ['Google Chrome', 'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe'],
        ['Microsoft Edge', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'],
        ['Microsoft Edge', 'C:/Program Files/Microsoft/Edge/Application/msedge.exe'],
    ];
    let browser, browserName;
    for (const [name, executablePath] of candidates) {
        if (!fs.existsSync(executablePath)) continue;
        try {
            browser = await chromium.launch({ executablePath, headless: true, timeout: 20000, args: ['--disable-background-networking', '--disable-component-update', '--no-first-run', '--disable-sync', '--disable-default-apps'] });
            browserName = name; break;
        } catch { console.log(name + ' indisponible pour cette session ; essai suivant.'); }
    }
    if (!browser) throw new Error('Aucun navigateur local ne démarre ; aucun navigateur téléchargé.');
    const stamp = new Date().toISOString().replace(/[:.]/g, '-');
    const run = path.join(OUT, stamp);
    const shots = path.join(run, 'captures'); fs.mkdirSync(shots, { recursive: true });
    const findings = [], records = [], blocked = { methods: 0, destinations: 0, websockets: 0 };
    const coverage = [], footerChecks = [], linkChecks = new Map(), assetCache = new Map(); let context;
    const add = (priority, url, width, detail, type = 'layout') => findings.push({ priority, url, width, detail, source: sourceFor(url, type) });
    async function shot(page, filename, fullPage = false) {
        await page.screenshot({ path: path.join(shots, filename + '.png'), fullPage, animations: 'disabled', timeout: 25000 });
        return 'captures/' + filename + '.png';
    }
    async function navigate(page, url) {
        const response = await page.goto(BASE + url, { waitUntil: 'domcontentloaded', timeout: 60000 });
        try { await page.waitForLoadState('networkidle', { timeout: 20000 }); }
        catch { console.log('Chargement réseau lent, poursuite des contrôles DOM : ' + url); }
        await page.locator('body').waitFor();
        await page.evaluate(async () => { await document.fonts.ready; });
        return response?.status() || 0;
    }
    async function loadLazyImages(page) {
        // Exercise real scrolling before full-page screenshots; otherwise native lazy images stay blank.
        await page.evaluate(async () => {
            for (let y = 0; y < document.documentElement.scrollHeight; y += Math.max(300, innerHeight - 100)) {
                window.scrollTo({ top: y, behavior: 'instant' }); await new Promise(r => setTimeout(r, 100));
            }
        });
        await page.waitForLoadState('networkidle', { timeout: 60000 });
        await page.evaluate(async () => {
            await Promise.all([...document.images].map(i => i.decode().catch(() => {})));
            window.scrollTo({ top: 0, behavior: 'instant' });
        });
        await page.waitForTimeout(100);
    }
    async function footerMetrics(link) {
        return link.evaluate(element => {
            const style = getComputedStyle(element), rect = element.getBoundingClientRect();
            const luminance = rgb => {
                const channels = rgb.match(/[\d.]+/g).slice(0, 3).map(Number).map(v => { v /= 255; return v <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4; });
                return .2126 * channels[0] + .7152 * channels[1] + .0722 * channels[2];
            };
            const a = luminance(style.color), b = luminance(style.backgroundColor);
            return { height: rect.height, width: rect.width, color: style.color, background: style.backgroundColor, contrast: (Math.max(a, b) + .05) / (Math.min(a, b) + .05), focus: element.matches(':focus-visible'), outline: parseFloat(style.outlineWidth), transition: style.transitionDuration };
        });
    }
    async function checkMerchantFooter(page, shop, width) {
        const name = width + '-' + shop.slice(1).replaceAll('/', '-') + '-footer';
        const footer = page.locator('.merchant-footer'), links = footer.locator('.merchant-footer-link');
        try {
            await page.evaluate(() => window.scrollTo({ top: document.documentElement.scrollHeight, behavior: 'instant' }));
            await footer.scrollIntoViewIfNeeded(); await page.mouse.move(0, 0);
            const normal = await Promise.all([0, 1, 2].map(n => footerMetrics(links.nth(n))));
            if (normal.some(s => s.height < 44 || s.width < 44 || s.contrast < 4.5)) throw new Error();
            if (await footer.locator('small a').count() || await links.count() !== 3) throw new Error();
            await shot(page, name + '-normal');
            await links.first().hover(); await page.waitForTimeout(220);
            const hover = await footerMetrics(links.first());
            if (hover.background === normal[0].background || hover.contrast < 4.5) throw new Error();
            await shot(page, name + '-hover'); await page.mouse.move(0, 0);
            // Keyboard modality before programmatic targeting ensures :focus-visible is exercised.
            await page.keyboard.press('Tab'); await links.first().focus(); await page.waitForTimeout(220);
            const focus = [];
            for (let n = 0; n < 3; n++) {
                if (n) { await page.keyboard.press('Tab'); await page.waitForTimeout(220); }
                const state = await footerMetrics(links.nth(n));
                if (!state.focus || state.outline < 3 || state.contrast < 4.5) throw new Error();
                focus.push(state);
                if (!n) await shot(page, name + '-focus');
            }
            await page.emulateMedia({ reducedMotion: 'reduce' });
            const reduced = await footerMetrics(links.first());
            if (reduced.transition.split(',').some(t => parseFloat(t) !== 0)) throw new Error();
            await page.emulateMedia({ reducedMotion: 'no-preference' });
            for (const suffix of ['/a-propos', '/livraison-retrait']) {
                const target = shop + suffix;
                await Promise.all([page.waitForURL(BASE + target), page.locator('.merchant-footer a[href$="' + target + '"]').click()]);
                if (!await page.locator('.merchant-footer a[aria-current="page"]').count()) throw new Error();
            }
            await Promise.all([page.waitForURL(BASE + '/'), page.locator('.merchant-footer-back').click()]);
            footerChecks.push({ shop, width, normal, hover, focus, reducedMotion: true, navigation: true });
            coverage.push(width + ' ' + shop + ' : footer normal/hover/focus, cibles 44 px, contraste AA, mouvement réduit et trois liens réels OK.');
        } catch {
            await page.emulateMedia({ reducedMotion: 'no-preference' });
            add('importante', shop, width, 'Un contrôle du footer marchand (contraste, cible, hover, clavier, mouvement réduit ou navigation) échoue.');
        }
        await navigate(page, shop);
    }
    try {
        for (const viewport of WIDTHS) {
            context = await browser.newContext({ viewport, locale: 'fr-FR', serviceWorkers: 'block', acceptDownloads: false });
            await context.route('**/*', async route => {
                const req = route.request();
                if (!['GET', 'HEAD'].includes(req.method())) { blocked.methods++; return route.abort('blockedbyclient'); }
                if (!requestAllowed(req.method(), req.url(), req.isNavigationRequest())) { blocked.destinations++; return route.abort('blockedbyclient'); }
                if (!req.isNavigationRequest() && assetCache.has(req.url())) return route.fulfill(assetCache.get(req.url()));
                return route.continue();
            });
            await context.routeWebSocket(/.*/, socket => { blocked.websockets++; socket.close(); });
            context.on('page', p => p.on('download', d => d.cancel()));
            const page = await context.newPage();
            page.setDefaultTimeout(8000);
            page.setDefaultNavigationTimeout(60000);
            page.on('response', async response => {
                if (response.status() === 200 && !response.request().isNavigationRequest() && allowed(response.url()) && !publicPath(new URL(response.url()).pathname)) {
                    try { assetCache.set(response.url(), { status: 200, contentType: await response.headerValue('content-type') || 'application/octet-stream', body: await response.body() }); } catch { /* Detached response: not cached. */ }
                }
            });
            let current = '/', jsErrors = 0, consoleErrors = 0, badResources = [], blockedOnPage = 0;
            page.on('requestfailed', request => { if (!requestAllowed(request.method(), request.url(), request.isNavigationRequest())) blockedOnPage++; });
            page.on('pageerror', () => jsErrors++);
            page.on('console', m => { if (m.type() === 'error') consoleErrors++; });
            page.on('response', r => { if (r.status() >= 400 && allowed(r.url())) badResources.push({ status: r.status(), resource: safePath(r.url()) }); });
            await navigate(page, '/boutiques');
            const discovered = await page.locator('a[href]').evaluateAll(anchors => anchors.map(a => ({ path: new URL(a.href).pathname, name: a.textContent.trim() })));
            const shops = [...new Set(discovered.filter(a => /^\/boutiques\/[^/]+$/.test(a.path) && /collines|wouri/i.test(a.name + a.path)).map(a => a.path))];
            if (shops.length !== 2) add('importante', '/boutiques', viewport.width, 'Les deux boutiques attendues ne sont pas toutes découvertes dans l’annuaire.');
            const urls = ['/', '/produits', '/boutiques', ...shops.flatMap(s => [s, s + '/produits', s + '/categories', s + '/a-propos', s + '/livraison-retrait'])];
            const products = new Set();
            // Discover real product URLs from the actual store catalogues, without database access.
            for (const shop of shops) {
                await navigate(page, shop + '/produits');
                const links = await page.locator('a[href]').evaluateAll(aa => aa.map(a => new URL(a.href).pathname));
                for (const u of links) if (u.startsWith(shop + '/produits/') && /\/\d+$/.test(u)) products.add(u);
            }
            urls.push(...products);
            for (const url of [...new Set(urls)]) {
                current = url; jsErrors = 0; consoleErrors = 0; badResources = []; blockedOnPage = 0;
                console.log(viewport.width + 'px ' + current);
                const name = viewport.width + '-' + (url === '/' ? 'accueil' : url.slice(1).replaceAll('/', '-'));
                try {
                    const status = await navigate(page, current);
                    await loadLazyImages(page);
                    if (status !== 200) add(status >= 500 ? 'critique' : 'importante', current, viewport.width, 'HTTP ' + status + ' à l’ouverture.');
                    const data = await page.evaluate(() => {
                        const visible = e => !!e.getClientRects().length && getComputedStyle(e).visibility !== 'hidden';
                        const images = [...document.images].filter(visible).filter(i => !i.complete || !i.naturalWidth).length;
                        const over = [...document.querySelectorAll('body *')].filter(visible).filter(e => !e.closest('dialog:not([open])') && !e.classList.contains('zm-sr-only')).filter(e => { const r = e.getBoundingClientRect(); return r.right > innerWidth + 2 && r.width > 2; }).slice(0, 8).map(e => e.tagName.toLowerCase() + (e.classList.length ? '.' + [...e.classList].slice(0, 2).join('.') : ''));
                        const active = [...document.querySelectorAll('.merchant-sidebar a[aria-current], .merchant-discovery-tabs a[aria-current], .zm-nav a[aria-current]')].map(a => new URL(a.href).pathname);
                        const search = [...document.querySelectorAll('input[type=search], input[name=q]')].filter(visible).map(i => ({ labeled: !!(i.labels?.length || i.getAttribute('aria-label') || i.getAttribute('aria-labelledby')), method: i.form?.method, action: i.form ? new URL(i.form.action).pathname : null }));
                        const paginationEnglish = document.documentElement.lang.startsWith('fr') && /\bShowing\b.*\bresults\b/.test([...document.querySelectorAll('.zm-pagination')].map(e => e.innerText.replace(/\s+/g, ' ')).join(' '));
                        const emptyMerchantPagination = !!document.querySelector('.merchant-site') && [...document.querySelectorAll('.zm-pagination')].some(e => !e.textContent.trim());
                        return { overflow: document.documentElement.scrollWidth > innerWidth + 2, over, images, active, search, paginationEnglish, emptyMerchantPagination, footer: !!document.querySelector('footer'), h1: document.querySelectorAll('h1').length, links: [...document.querySelectorAll('a[href]')].map(a => a.href) };
                    });
                    if (data.overflow) add('importante', current, viewport.width, 'Débordement horizontal confirmé. Éléments candidats : ' + data.over.join(', '));
                    if (data.images) add('importante', current, viewport.width, data.images + ' image(s) visible(s) non chargée(s).');
                    if (!data.footer) add('cosmétique', current, viewport.width, 'Aucun footer détecté.');
                    if (data.paginationEnglish) add('cosmétique', current, viewport.width, 'Résumé de pagination en anglais dans une page française : vérifier les traductions JSON et la vue de pagination.');
                    if (data.emptyMerchantPagination) add('cosmétique', current, viewport.width, 'Un conteneur de pagination vide conserve une marge inutile : resources/views/marketplace/merchant/catalog-list.blade.php.');
                    if (data.h1 !== 1) add('cosmétique', current, viewport.width, data.h1 + ' titres H1 détectés (attendu : un).');
                    for (const s of data.search) if (!s.labeled || s.method !== 'get' || (current.startsWith('/boutiques/') && s.action !== current.split('/').slice(0, 3).join('/') + '/produits')) add('importante', current, viewport.width, 'Recherche sans nom accessible, sans GET ou hors du catalogue marchand.');
                    if (current.startsWith('/boutiques/')) {
                        const base = current.split('/').slice(0, 3).join('/');
                        const expected = /\/(categories|produits)(\/\d+)?$/.test(current) ? base + '/produits' : current;
                        if (!data.active.includes(expected)) add('importante', current, viewport.width, 'Lien principal actif incohérent avec la page visitée.');
                        if (/\/(categories|produits)$/.test(current) && !await page.locator('.merchant-discovery-tabs a[aria-current="page"]').count()) add('importante', current, viewport.width, 'Sélection secondaire absente.');
                    }
                    for (const link of data.links) if (allowed(link, true)) {
                        const u = new URL(link); u.hash = ''; linkChecks.set(u.href, null);
                    }
                    const capture = await shot(page, name, viewport.width === 1440 && !/\/produits\/\d+$/.test(current) || viewport.width === 390 && (current === '/' || shops.includes(current)));
                    await page.evaluate(() => window.scrollTo({ top: Math.min(800, document.documentElement.scrollHeight - innerHeight), behavior: 'instant' }));
                    await page.waitForTimeout(150);
                    const sticky = await page.evaluate(() => { const e = document.querySelector('.merchant-header, .zm-header'); if (!e) return null; const r = e.getBoundingClientRect(); return { scrolled: scrollY > 100, visible: r.top >= -1 && r.bottom > 0 && r.top < 10, height: r.height, viewport: innerHeight }; });
                    if (sticky?.scrolled && !sticky.visible) add('importante', current, viewport.width, 'Le header ne reste pas visible après défilement.');
                    if (sticky && sticky.height > sticky.viewport * .35) add('cosmétique', current, viewport.width, 'Le header occupe plus de 35 % de la hauteur du viewport.');
                    if (shops.includes(current)) await shot(page, name + '-defilement');
                    await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'instant' }));
                    records.push({ url: current, width: viewport.width, status, capture, active: data.active, searchFields: data.search.length });
                    if (jsErrors || consoleErrors) add(jsErrors || consoleErrors > blockedOnPage ? 'importante' : 'cosmétique', current, viewport.width, jsErrors + ' erreur(s) JavaScript et ' + consoleErrors + ' message(s) console error ; ' + blockedOnPage + ' requête(s) refusée(s) par la garde. Textes volontairement non exportés. Les erreurs dues au blocage sont une limite du périmètre, pas une panne JavaScript.', 'console');
                    for (const failure of badResources) add('importante', current, viewport.width, 'Ressource en erreur HTTP ' + failure.status + ' (' + failure.resource + ').');
                } catch {
                    add('importante', current, viewport.width, 'Échec ou délai dépassé lors de la visite/capture ; aucun contenu brut exporté.');
                }
            }
            // Exercise navigation controls using real clicks and keyboard input, never transaction controls.
            for (const shop of shops) {
                console.log(viewport.width + 'px interactions ' + shop);
                await navigate(page, shop);
                await checkMerchantFooter(page, shop, viewport.width);
                if (viewport.width < 961) {
                    const trigger = page.locator('[data-merchant-open]');
                    try {
                        await trigger.focus(); await page.keyboard.press('Enter');
                        const open = await page.locator('#merchant-drawer').evaluate(d => d.open && d.contains(document.activeElement));
                        if (!open) throw new Error();
                        // Chromium can focus browser chrome between cycles (activeElement BODY).
                        // Reject focus on any interactive page element outside the modal.
                        for (let i = 0; i < 12; i++) { await page.keyboard.press('Tab'); if (!await page.locator('#merchant-drawer').evaluate(d => d.contains(document.activeElement) || document.activeElement === document.body)) throw new Error(); }
                        await shot(page, viewport.width + '-' + shop.slice(1).replaceAll('/', '-') + '-menu');
                        await page.keyboard.press('Escape');
                        if (!await trigger.evaluate(e => e === document.activeElement && e.getAttribute('aria-expanded') === 'false')) throw new Error();
                        await trigger.click();
                        await page.locator('#merchant-drawer a[href$="' + shop + '/produits"]').click();
                        await page.waitForURL(BASE + shop + '/produits');
                        coverage.push(viewport.width + ' ' + shop + ' : tiroir clavier, focus contenu, Échap, retour focus et lien Découvrir OK.');
                    } catch { add('importante', shop, viewport.width, 'Un contrôle du tiroir (ouverture clavier, confinement du focus, Échap, retour du focus ou lien) échoue.'); }
                } else {
                    const toggle = page.locator('[data-merchant-collapse]');
                    try {
                        await toggle.click();
                        if (await toggle.getAttribute('aria-expanded') !== 'false') throw new Error();
                        await shot(page, viewport.width + '-' + shop.slice(1).replaceAll('/', '-') + '-menu-reduit');
                        await toggle.click();
                        if (await toggle.getAttribute('aria-expanded') !== 'true') throw new Error();
                        coverage.push('1440 ' + shop + ' : rétraction/restauration sidebar OK.');
                    } catch { add('importante', shop, viewport.width, 'Rétraction/restauration desktop non opérationnelle.'); }
                }
                await navigate(page, shop + '/produits');
                const categories = page.locator('.merchant-discovery-tabs a[href$="' + shop + '/categories"]');
                try {
                    await categories.click(); await page.waitForURL(BASE + shop + '/categories');
                    await page.locator('.merchant-discovery-tabs a[href$="' + shop + '/produits"]').click(); await page.waitForURL(BASE + shop + '/produits');
                    const filter = page.locator('.merchant-filter-links a').nth(1);
                    if (await filter.count()) { await filter.click(); await page.waitForLoadState('networkidle'); if (!new URL(page.url()).searchParams.has('category')) throw new Error(); }
                    const input = page.locator('.merchant-search input[type=search]');
                    await input.fill('mug');
                    await Promise.all([page.waitForURL(u => u.pathname === shop + '/produits' && u.searchParams.get('q') === 'mug'), input.press('Enter')]);
                    await page.waitForLoadState('networkidle');
                    if (new URL(page.url()).pathname !== shop + '/produits' || new URL(page.url()).searchParams.get('q') !== 'mug') throw new Error();
                    const visibleProducts = await page.locator('.merchant-product-image').evaluateAll(aa => aa.map(a => new URL(a.href).pathname));
                    if (visibleProducts.some(p => !p.startsWith(shop + '/produits/'))) throw new Error();
                    coverage.push(viewport.width + ' ' + shop + ' : onglets, filtre catégorie, recherche GET et liens marchands isolés OK (preuve UI, pas audit SQL).');
                    await page.locator('.merchant-header .merchant-explore').click(); await page.waitForURL(BASE + '/boutiques');
                } catch { add('importante', shop + '/produits', viewport.width, 'Un parcours onglets/filtre/recherche/retour boutiques échoue.'); }
            }
            // Marketplace menu and global search.
            await navigate(page, '/');
            const menu = page.locator('.zm-menu-toggle');
            if (await menu.isVisible()) {
                try {
                    await menu.focus(); await page.keyboard.press('Enter');
                    if (await menu.getAttribute('aria-expanded') !== 'true') throw new Error();
                    await shot(page, viewport.width + '-marketplace-menu');
                    await page.keyboard.press('Escape');
                    if (await menu.getAttribute('aria-expanded') !== 'false') throw new Error();
                    coverage.push(viewport.width + ' : menu marketplace clavier/Échap OK.');
                } catch { add('importante', '/', viewport.width, 'Menu marketplace : ouverture clavier ou fermeture Échap défaillante.'); }
            }
            const globalSearch = page.locator('input[type=search]:visible').first();
            if (await globalSearch.count()) {
                try { await globalSearch.fill('mug'); await Promise.all([page.waitForURL(u => u.searchParams.get('q') === 'mug'), globalSearch.press('Enter')]); await page.waitForLoadState('networkidle'); if (new URL(page.url()).searchParams.get('q') !== 'mug') throw new Error(); coverage.push(viewport.width + ' : recherche marketplace GET OK.'); }
                catch { add('importante', '/', viewport.width, 'Recherche marketplace non opérationnelle.'); }
            }
            for (const publicPage of ['/', '/produits', '/boutiques']) {
                await navigate(page, publicPage);
                await page.evaluate(() => window.scrollTo({ top: 800, behavior: 'instant' }));
                try {
                    const summary = page.locator('.zm-header-search summary');
                    await summary.click();
                    const input = page.locator('#header-search-q');
                    if (!await input.isVisible() || !await input.evaluate(e => e === document.activeElement)) throw new Error();
                    await shot(page, viewport.width + '-recherche-sticky-' + (publicPage === '/' ? 'accueil' : publicPage.slice(1)));
                    await page.keyboard.press('Escape');
                    if (!await summary.evaluate(e => e === document.activeElement)) throw new Error();
                    await summary.click(); await input.fill('mug');
                    await Promise.all([page.waitForURL(u => u.pathname === '/produits' && u.searchParams.get('q') === 'mug'), input.press('Enter')]);
                    coverage.push(viewport.width + ' ' + publicPage + ' : recherche du header après défilement, focus, Échap et soumission catalogue GET OK.');
                } catch { add('importante', publicPage, viewport.width, 'Recherche du header après défilement : ouverture, focus, Échap ou soumission GET échoue.'); }
            }
            await context.close(); context = null;
        }
        // GET-only link validation through a browser page: the same routing guards apply to redirects.
        context = await browser.newContext({ serviceWorkers: 'block' });
        // Link status checks need HTML only: avoid reloading every image for every link.
        await context.route('**/*', route => route.request().isNavigationRequest() && requestAllowed(route.request().method(), route.request().url(), true) ? route.continue() : route.abort());
        await context.routeWebSocket(/.*/, socket => socket.close());
        const linksPage = await context.newPage();
        let checked = 0;
        for (const link of linkChecks.keys()) {
            if (checked++ % 10 === 0) console.log('Liens publics : ' + checked + '/' + linkChecks.size);
            try { const r = await linksPage.goto(link, { waitUntil: 'domcontentloaded', timeout: 20000 }); const status = r?.status() || 0; linkChecks.set(link, status); if (status >= 400 || !status) add(status >= 500 ? 'critique' : 'importante', safePath(link), 'liens', 'Lien public en erreur HTTP ' + status + '.'); }
            catch { linkChecks.set(link, 'échec'); add('importante', safePath(link), 'liens', 'Lien public inaccessible ou redirection hors périmètre bloquée.'); }
        }
    } finally { if (context) await context.close(); await browser.close(); }
    const after = fingerprint();
    const unchanged = JSON.stringify(before) === JSON.stringify(after);
    if (!unchanged) add('critique', '/', 'sécurité', 'Empreintes SQLite DEMO différentes avant/après. Arrêter les audits et analyser une éventuelle écriture serveur ou activité concurrente.');
    const priorities = ['critique', 'importante', 'cosmétique']; findings.sort((a, b) => priorities.indexOf(a.priority) - priorities.indexOf(b.priority));
    const report = [
        '# ZEMBOA — Audit visuel et fonctionnel public', '',
        '- Date UTC : ' + new Date().toISOString(),
        '- Navigateur utilisé : ' + browserName + ' ' + browser.version() + ' (headless, profil temporaire Playwright, aucun profil personnel).',
        '- Serveur : ' + BASE + ' ; processus PHP DEMO confirmé avant lancement.',
        '- Viewports CSS : 1440 × 1000, 768 × 1024, 390 × 844 ; échelle 1.',
        '- Pages capturées : ' + records.length + ' ; liens publics GET vérifiés : ' + linkChecks.size + '.',
        '- Requêtes bloquées : méthodes interdites ' + blocked.methods + ', destinations hors périmètre ' + blocked.destinations + ', WebSockets ' + blocked.websockets + '.',
        '- SQLite DEMO : contenu du fichier et WAL ' + (unchanged ? 'identique avant/après (SHA-256 comparés, sans ouverture SQL).' : 'MODIFIÉ : voir anomalie critique.'),
        '- Aucun fichier .env, cookie, secret, corps HTML, trace réseau ou message brut de console exporté.', '',
        '## Synthèse', '', ...priorities.map(p => '- ' + p + ' : ' + findings.filter(f => f.priority === p).length + ' constat(s), occurrences par page et largeur.'), '',
        '## Anomalies', '',
        '| Priorité | Page | Largeur | Constat | Sources probables (à confirmer) |', '|---|---|---|---|---|',
        ...findings.map(f => '| ' + [f.priority, f.url, f.width, f.detail, f.source].map(v => String(v).replaceAll('|', '/').replaceAll('\n', ' ')).join(' | ') + ' |'),
        ...(findings.length ? [] : ['Aucune anomalie détectée par les contrôles automatisés.']), '',
        '## Parcours réellement exercés', '', ...coverage.map(c => '- ' + c), '',
        '## Captures et pages', '', '| Page | Largeur | HTTP | Capture |', '|---|---|---|---|',
        ...records.map(r => '| ' + r.url + ' | ' + r.width + ' | ' + r.status + ' | [capture](' + r.capture + ') |'), '',
        '## Limites', '',
        'Les contrôles couvrent rendu, ressources, débordements, états actifs, navigation, recherche GET et focus des menus. Les captures supplémentaires montrent menus et défilement. Les erreurs console sont comptées sans exporter leur texte pour éviter les secrets. Les ressources externes sont volontairement bloquées ; cela peut expliquer certaines erreurs de console.',
        'Les fichiers sources indiqués sont des hypothèses guidées par les sélecteurs ; aucun frontend n’est corrigé. La conformité WCAG complète, les contrastes de chaque couleur, la pertinence des photos, Safari/Firefox et les parcours authentifiés ne sont pas certifiés. Les contrôles multiboutiques sont limités aux URLs et résultats visibles, sans inspection SQL. Le changement FR/EN utilise POST : il est volontairement exclu.',
        'Le navigateur ne peut envoyer que GET/HEAD sur une liste explicite de pages publiques et de ressources locales. Le serveur peut écrire ses sessions fichier, journaux et vues compilées ; les empreintes ne prouvent pas l’absence de toute écriture temporaire ou d’un effet de bord GET, mais détectent un changement persistant SQLite pendant la fenêtre de l’audit.', '',
    ].join('\n');
    fs.writeFileSync(path.join(run, 'rapport.md'), report);
    fs.writeFileSync(path.join(run, 'resultats.json'), JSON.stringify({ browser: browserName, widths: WIDTHS, databaseUnchanged: unchanged, blocked, findings, records, coverage, footerChecks, links: [...linkChecks].map(([u, status]) => ({ path: safePath(u), status })) }, null, 2));
    const zip = run + '.zip';
    // Explicit allowlist of generated outputs only; no recursive archive of project/tooling.
    const q = s => "'" + s.replaceAll("'", "''") + "'";
    execFileSync('powershell.exe', ['-NoProfile', '-NonInteractive', '-Command', 'Compress-Archive -LiteralPath ' + [path.join(run, 'rapport.md'), path.join(run, 'resultats.json'), shots].map(q).join(',') + ' -DestinationPath ' + q(zip) + ' -CompressionLevel Optimal'], { timeout: 120000, stdio: ['ignore', 'pipe', 'pipe'] });
    console.log('BROWSER=' + browserName + '\nREPORT=' + path.join(run, 'rapport.md') + '\nZIP=' + zip);
    if (!unchanged) process.exitCode = 2;
}
module.exports = { serverGuard, fingerprint, requestAllowed, BASE, ROOT };
if (require.main !== module) { /* Helpers for local read-only diagnostics; importing performs no audit. */ }
else if (process.argv.includes('--self-test')) {
    const assert = require('node:assert/strict');
    for (const method of ['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'CONNECT']) assert.equal(requestAllowed(method, BASE + '/'), false);
    for (const url of ['https://example.com/', 'http://localhost:8016/', BASE + '/checkout', BASE + '/shops/test/cart', BASE + '/login', BASE + '/.env', BASE + '/boutiques/test/produits?token=private', BASE + '/boutiques/test/produits?redirect=/checkout']) assert.equal(requestAllowed('GET', url, true), false);
    for (const url of ['/', '/produits', '/boutiques/test', '/boutiques/test/produits?q=mug', '/boutiques/test/categories', '/boutiques/test/produits/12']) assert.equal(requestAllowed('GET', BASE + url, true), true);
    assert.equal(requestAllowed('GET', BASE + '/build/assets/app.js'), true);
    assert.equal(requestAllowed('GET', BASE + '/build/assets/app.js', true), false);
    console.log('Garde réseau : 22 assertions OK ; aucun navigateur ni accès HTTP/SQL.');
} else main().catch(error => {
    const ownFrame = String(error.stack || '').split('\n').find(line => line.includes('audit-frontend.cjs:'));
    const location = ownFrame?.match(/audit-frontend\.cjs:\d+:\d+/)?.[0] || 'prérequis';
    console.error('Audit arrêté (' + error.name + ', ' + location + ') : garde ou étape technique non confirmée. Aucun message brut ni détail sensible exporté.');
    process.exitCode = 1;
});
