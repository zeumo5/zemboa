# Audit local des interfaces publiques ZEMBOA

## Exécution

Depuis la racine du projet, avec le serveur SQLite DEMO déjà lancé sur
`http://127.0.0.1:8016` via `tools/demo-router.php` :

```powershell
node tools/audit-frontend.cjs --self-test
node tools/audit-frontend.cjs
```

La première commande vérifie la garde réseau sans HTTP ni navigateur. La seconde
visite les pages, exerce les interactions de navigation et crée un dossier daté
ainsi qu'un ZIP sous `storage/demo/audits/`. Le chemin du ZIP et le navigateur
réellement utilisé sont affichés à la fin. Ne pas lancer de migration ou de seeder
pour cet audit. Le script ne démarre pas lui-même le serveur.

## Outillage isolé

`playwright-core` est installé exclusivement sous `storage/demo/audit-tooling/`,
avec son propre manifeste et verrou de dépendances. Le projet principal et ses
dépendances restent inchangés. Aucun navigateur n'est téléchargé. Chrome local
est prioritaire ; Edge local sert de secours. Aucun navigateur par défaut n'est
modifié. Playwright utilise un profil temporaire et des contextes anonymes sans
charger les profils personnels ni exporter leur état.

Sur un nouveau clone, le dossier d'outillage et son verrou sont absents car
`storage/demo/` est ignoré. Installer explicitement la version utilisée, après
accord pour le téléchargement du paquet (aucun navigateur téléchargé) :

```powershell
npm install --prefix storage/demo/audit-tooling --ignore-scripts --no-audit --no-fund playwright-core@1.64.1
```

Pour réinstaller ensuite avec le verrou local créé par cette commande :

```powershell
npm ci --prefix storage/demo/audit-tooling --ignore-scripts --no-audit --no-fund
```

L'audit contrôle aussi les footers de deux vitrines aux trois largeurs : états
normal/hover/focus, zones de 44 px, contraste du texte, mouvement réduit et liens
À propos, Livraison/retrait et Retour à ZEMBOA. Les métriques détaillées figurent
dans `resultats.json` ; les captures correspondantes portent le suffixe `footer`.

## Périmètre et protections

- Windows : le processus à l'écoute du port 8016 doit être PHP, lié uniquement
  à `127.0.0.1`, avec le routeur DEMO. Sinon l'audit s'arrête avant toute visite.
- GET/HEAD uniquement, origine exacte `http://127.0.0.1:8016`, liste explicite des
  pages publiques marketplace et marchandes ; les redirections sont également
  filtrées. Ressources locales CSS/JS/images/polices uniquement.
- Les endpoints JSON, authentification, panier, paiement, commandes et les
  paramètres non prévus ne sont pas autorisés. Service workers et WebSockets
  bloqués. Aucun changement de langue POST ni connexion à un compte.
- Aucun bootstrap Laravel, pilote SQL, lecture de `.env` ou export de secret.
  Les empreintes binaires SHA-256 de SQLite DEMO et de son WAL sont comparées
  avant/après. Le serveur peut écrire ses sessions fichier, vues et journaux.
  Une activité concurrente peut aussi changer les empreintes : éviter d'utiliser
  la DEMO pendant l'audit. Une différence est signalée critique, sans correction.
- Les contextes sont fermés en fin de passage. Pas de HAR, de trace, de HTML,
  de cookies ou de messages bruts console dans les livrables. Les erreurs sont
  comptées et les sources probables indiquées.
- Seuls `rapport.md`, `resultats.json` et les PNG générés sont archivés ; jamais
  le dépôt, ses bases ou ses fichiers d'environnement.
- `storage/demo/` est déjà ignoré par Git. Le script et cette documentation
  peuvent être versionnés après validation ; aucune installation ne le nécessite.

## Couverture et limites

1440 × 1000, 768 × 1024 et 390 × 844 pixels CSS. Découverte des deux boutiques
Maison des Collines et Atelier du Wouri dans l'annuaire réel ; visites de leurs
cinq pages et des fiches produits trouvées dans les catalogues. Captures de
viewports, pages entières et états des menus. Contrôles HTTP, images non chargées,
console/JavaScript, débordement horizontal, H1/footer, header après défilement,
liens actifs, recherche GET et navigation de catégories ; tests clavier et focus
des tiroirs et rétraction desktop.

Les anomalies sont classées critique, importante ou cosmétique. Les fichiers
responsables sont des hypothèses, non des diagnostics de code définitifs.
Les captures permettent une revue humaine : aucune mesure automatique ne peut
certifier à elle seule la qualité esthétique, toutes les règles WCAG, les
contrastes, la pertinence des images ou les parcours hors périmètre. Les
ressources externes bloquées peuvent générer des erreurs de console attendues.
