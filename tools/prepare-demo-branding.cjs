// File-only preparation. No Laravel bootstrap, environment file or database access.
'use strict';
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const identities = [
    { slug: 'demo-yaounde-maison', name: 'Maison des Collines', color: '#34635C', photo: 'coussin', icon: '<path d="m24 56 40-30 40 30M36 50v45h56V50M55 95V69h18v26"/>' },
    { slug: 'demo-douala-atelier', name: 'Atelier du Wouri', color: '#835447', photo: 'foulard', icon: '<path d="m25 35 18 58 21-42 21 42 18-58M27 68h74M35 49h58"/>' },
    { slug: 'demo-buea-digital', name: 'Mountain Digital', color: '#263D62', photo: 'stand', icon: '<path d="m24 91 40-58 40 58H24ZM49 55l15 19 15-19M45 91v13m38-13v13M39 104h50"/>' },
    { slug: 'demo-bafoussam-terroir', name: 'Terroir des Hauts Plateaux', color: '#64733F', photo: 'cafe', icon: '<path d="M37 91c-7-42 10-60 56-56 0 41-15 62-56 56ZM34 98l46-48M49 79V58m0 21h23"/>' },
];
function write(relative, content) {
    const destination = path.resolve(root, relative);
    if (!destination.startsWith(root + path.sep)) throw new Error('Path outside workspace');
    const parts = path.relative(root, destination).split(path.sep);
    let cursor = root;
    for (const part of parts.slice(0, -1)) {
        cursor = path.join(cursor, part);
        if (fs.existsSync(cursor) && fs.lstatSync(cursor).isSymbolicLink()) throw new Error('Linked destination refused');
        if (!fs.existsSync(cursor)) fs.mkdirSync(cursor);
    }
    if (fs.existsSync(destination) && fs.lstatSync(destination).isSymbolicLink()) throw new Error('Linked output refused');
    fs.writeFileSync(destination, content);
}
const manifest = [];
for (const identity of identities) {
    const logo = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128"><title>${identity.name} — logo fictif DEMO</title><rect width="128" height="128" rx="28" fill="${identity.color}"/><g fill="none" stroke="#FFF9EC" stroke-width="5" stroke-linecap="round" stroke-linejoin="round">${identity.icon}</g><circle cx="104" cy="23" r="5" fill="#F2D95C"/></svg>`;
    const photo = fs.readFileSync(path.join(root, 'public/marketplace/demo-photos-v1', identity.photo + '.jpg')).toString('base64');
    const banner = `<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 1200 460"><title>${identity.name} — bannière fictive DEMO</title><defs><linearGradient id="shade"><stop stop-color="${identity.color}"/><stop offset="1" stop-color="${identity.color}" stop-opacity="0"/></linearGradient></defs><rect width="1200" height="460" fill="${identity.color}"/><image x="420" width="780" height="460" preserveAspectRatio="xMidYMid slice" xlink:href="data:image/jpeg;base64,${photo}"/><rect x="400" width="400" height="460" fill="url(#shade)"/><g fill="none" stroke="#FFFFFF" opacity=".08"><circle cx="140" cy="230" r="190"/><circle cx="140" cy="230" r="150"/></g><text x="54" y="178" font-family="system-ui,sans-serif" font-size="20" letter-spacing="4" fill="#FFF9EC">IDENTITÉ MARCHANDE</text><text x="54" y="240" font-family="system-ui,sans-serif" font-size="38" font-weight="700" fill="#FFF9EC">${identity.name}</text><path d="M54 270h90" stroke="#F2D95C" stroke-width="4"/><rect x="54" y="370" width="256" height="38" rx="9" fill="#FFF9EC"/><text x="70" y="395" font-family="system-ui,sans-serif" font-size="15" fill="#15202B">DEMO · COMMERCE FICTIF</text></svg>`;
    for (const [name, svg] of [['logo', logo], ['banner', banner]]) {
        write(`resources/demo-media/branding-v1/${identity.slug}/${name}.svg`, svg);
        write(`public/marketplace/demo/branding-v1/${identity.slug}/${name}.svg`, svg);
    }
    manifest.push({ slug: identity.slug, demoOnly: true, primary_color: identity.color, logo_path: `branding-v1/${identity.slug}/logo.svg`, banner_path: `branding-v1/${identity.slug}/banner.svg` });
}
write('resources/demo-media/branding-v1/manifest.json', JSON.stringify(manifest, null, 2) + '\n');
console.log('4 kits DEMO préparés (8 SVG sources + copies publiques). Aucune affectation en base.');
