# Démonstration SQLite — phase 16

## État actuel et utilisation quotidienne

La base SQLite DEMO locale a déjà été initialisée et sert à la présentation des interfaces.
Les sections « Phase 16.1 » et « Phase 16.2 » ci-dessous conservent les comptes rendus historiques
établis lors de leur préparation : leurs mentions de base absente ou de validations en attente
ne constituent pas un diagnostic de l’état actuel. Elles ne justifient aucune réinitialisation.

Pour démarrer une DEMO déjà préparée, conserver .env.demo et la base existants, puis utiliser
uniquement le lanceur dédié depuis la racine du projet :

~~~powershell
$demoPhp = 'C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe'
& $demoPhp tools/demo.php check
& $demoPhp tools/demo.php serve
~~~

Ouvrir exclusivement http://127.0.0.1:8016. Ne pas remplacer cette procédure par le serveur
habituel de développement ou artisan --env=demo. Si une garde échoue, arrêter et diagnostiquer
la configuration sans contourner les vérifications. Ne pas écraser .env.demo ni recréer sa clé.

### Restrictions de sécurité permanentes

- MySQL zemboa et .env restent réservés au développement habituel ; aucune donnée fictive
  ni commande de démonstration ne doit les cibler.
- PHPUnit conserve SQLite :memory: ; la DEMO utilise exclusivement database/zemboa_demo.sqlite
  via bootstrap/demo.php et les garde-fous existants.
- Les commandes key/create/migrate/seed documentées plus bas concernent une initialisation
  ou intervention explicitement autorisée, jamais le simple lancement quotidien. Leur présence
  dans ce document ne vaut pas autorisation de les exécuter.
- Aucune migration, aucun seeder, aucune restauration ou réinitialisation de données pendant
  la préparation Git. Ne pas employer migrate:fresh, migrate:reset ou db:wipe pour la DEMO.
- Les offres sont fictives et les paiements Mobile Money ne sont pas réels. Aucun paiement
  ni transaction ne doit être déclenché pour présenter ou auditer les interfaces.
- Garder secrets, bases et sidecars WAL/SHM, médias générés, sauvegardes et résultats d’audit
  hors Git. Les sources des médias, leurs manifestes et les outils sûrs sont versionnables.

### Réinstallation des photographies après une régénération autorisée

Une future réexécution expressément autorisée du seeder peut réécrire les médias SVG génériques
aux chemins déjà enregistrés. Elle ne sert pas à installer les photographies. Après cette
intervention, l’outil suivant permet de rétablir les photographies à partir des sources versionnées :

~~~powershell
& $demoPhp tools/install-demo-media.php --dry-run
& $demoPhp tools/install-demo-media.php --apply
~~~

Cet outil valide les sources et les SVG cibles existants, sauvegarde la première version locale
et remplace uniquement leur contenu fichier. Il ne charge ni Laravel ni .env et n’accède à aucune
base de données. Les détails et la provenance des visuels figurent dans DEMO_MEDIA.md.
Les kits logo/bannière sont distincts : leur préparation ne les affecte pas aux boutiques en base.
Aucune commande de cette procédure n’est exécutée par la présente mise à jour documentaire.

## Archive — phase 16.1 : préparation de la démonstration SQLite

Statut : préparation seulement. Aucune base créée ou ouverte, aucune migration, aucun seeder exécuté.
La compatibilité réelle des migrations et l'idempotence restent à valider après accord.

## Séparation des environnements

| Usage | Point d'entrée | Connexion | Base |
| --- | --- | --- | --- |
| Développement | artisan / serveur habituel | .env habituel | MySQL zemboa |
| PHPUnit | phpunit.xml | sqlite | :memory: |
| Démonstration locale | tools/demo.php | sqlite_demo uniquement | database/zemboa_demo.sqlite |

.env, .env.example, phpunit.xml, bootstrap/app.php, routes et migrations sont inchangés.
La connexion par défaut habituelle ne change pas. DatabaseSeeder n'appelle pas DemoSeeder.

La connexion sqlite_demo possède un chemin absolu fixe, une URL nulle, les clés étrangères actives,
un délai d'attente de 5 secondes, WAL et des transactions IMMEDIATE. DB_URL et DB_DATABASE ne
peuvent pas rediriger cette connexion. Les fichiers SQLite et leurs journaux sont exclus de Git.

bootstrap/demo.php charge exclusivement .env.demo, sans importer .env. Avant les providers,
il ne conserve que la connexion sqlite_demo et isole sessions, cookie, vues compilées et logs.
Le cache est en mémoire, la queue est null, le mail array et la diffusion null.
Les fichiers privés vont dans storage/demo ; les médias publics dans public/marketplace/demo.
Ces répertoires et les configurations locales contenant des secrets sont ignorés par Git.

Le lanceur expose seulement help/check/key/create/migrate/seed/serve, sans arguments supplémentaires.
Il refuse les caches demo préexistants. Le garde-fou du seeder vérifie l'environnement demo,
la connexion par défaut et résolue, le pilote SQLite, l'URL nulle, les FK actives et le chemin réel.
Il refuse MySQL, :memory: et les liens symboliques vers une autre base. Ne pas créer de lien physique
vers une base existante. Ne pas remplacer ce lanceur par artisan --env=demo : cette option seule
n'assure pas la même isolation. Ne pas lancer les commandes via l'Artisan habituel.

Aucune commande, livraison, transaction Mobile Money ou opération POS n'est créée.
Le vérificateur Mobile Money existant reste désactivé, sans simulateur ajouté.

## Audit des contraintes réelles

- Boutique : slug global unique, status chaîne, défaut ACTIVE ; l'administration accepte ACTIVE/INACTIVE
  et possède des validations supplémentaires d'activation.
- Utilisateur : email global unique, store_id nullable avec FK restrictive, status ACTIVE par défaut.
  La connexion exige boutique et utilisateur ACTIVE, rôle professionnel sans SUPER_ADMIN ; employés vérifiés.
- Catégories/produits : unicité (store_id, slug). Le catalogue public exige boutique, catégorie,
  produit et au moins une variante ACTIVE. La catégorie appartient à la même boutique que le produit.
- Variantes : unicité (store_id, sku), prix decimal(12,2), promotion nullable. Une seule variante
  par défaut est préparée par produit ; deux éditions Standard/Plus utilisent les vrais attributs et pivots.
- Attributs : unicité (store_id, slug), valeurs uniques par (attribute_id, value), pivot unique.
- Stock : une ligne unique par variante ; disponible = physique - réservé ; quantités initiales
  non négatives, réservées à zéro. La réception initiale utilise l'action ReceiveStock existante.
- StockMovement est immuable. La réception n'est exécutée qu'à la création du stock, dans la même transaction.
- BelongsToStore impose le scope tenant et store_id à la création. Le seeder ne désactive ni ce scope
  ni les événements. Il utilise un contexte par boutique et restaure le contexte précédent en finally.
- Les FK simples du catalogue ne prouvent pas seules l'isolation tenant : le seeder vérifie aussi
  produit/catégorie, variante/produit et propriétaire/boutique.

## Compatibilité SQLite : analyse statique

Aucun SQL brut spécifique MySQL, enum SQL ou connexion MySQL imposée n'a été repéré dans les migrations.
Le havingRaw COUNT(*) > 1 de la migration d'unicité Mobile Money est portable.
Certaines migrations créent aussi des permissions via les modèles : migrate n'écrit pas seulement le schéma.
Ne pas employer migrate --pretend comme garantie d'absence d'effets applicatifs.

Points restant à valider après autorisation :

- Reconstruction des tables lors de l'ajout des FK composites aux commandes/paiements
  (migration 2026_10_18_103743), conservation des index et contraintes.
- La clé unique cible (store_id, id) est bien déclarée sur pos_sessions.
- SQLite n'impose pas les mêmes bornes unsigned ni la précision stricte de DECIMAL que MySQL.
  Les données proposées utilisent des prix FCFA entiers positifs et des quantités non négatives.
- JSON, dates, booléens et index avec valeurs nulles ont des représentations différentes.
- lockForUpdate ne reproduit pas les verrous de lignes MySQL : la démonstration ne valide pas
  la concurrence des stocks, paiements ou du POS.
- Après migration autorisée, contrôler PRAGMA foreign_key_check et integrity_check avec un outil
  SQLite explicitement ouvert sur database/zemboa_demo.sqlite, jamais sur la connexion habituelle.

Aucune migration n'a été modifiée. Aucune compatibilité complète n'est encore certifiée.

## Données préparées

Le catalogue dédié définit quatre boutiques fictives : Atelier du Wouri (Douala), Maison des Collines
(Yaoundé), Terroir des Hauts Plateaux (Bafoussam), Mountain Digital (Buea). Noms préfixés DEMO,
slugs demo-*, contacts example.test, aucun numéro joignable ni compte de paiement.

Le seeder prévoit 8 catégories, 16 produits, 32 variantes, 4 propriétaires STORE_OWNER ACTIVE,
des paramètres FR/EN, attributs et valeurs. Il réutilise les seeders rôles/permissions existants
uniquement après le garde-fou. Aucun super administrateur n'est affecté aux propriétaires.

Prix de base : 2 500 à 15 000 FCFA, édition Plus +2 000 FCFA. Stocks par boutique : 20, 3, 0, 12
unités par variante, pour présenter stock normal, faible et rupture. Aucune promotion temporelle.
Seize illustrations SVG seront générées avec DEMO / ILLUSTRATION - NOT FOR SALE ; aucune photo téléchargée.
Les éléments initiaux sont ACTIVE. Les scénarios INACTIVE seront validés séparément.

firstOrCreate, firstOrNew et syncWithoutDetaching assurent les identités stables sans suppression.
Les réexécutions ne réinitialisent ni stocks existants, ni mots de passe, ni contenu modifié pendant
une visite. Les SVG sont réécrits aux mêmes chemins. Les fichiers ne sont pas transactionnels :
en cas d'échec quelques SVG peuvent rester ; une nouvelle exécution réutilise leurs chemins.
Exécuter le seeder serveur arrêté, jamais simultanément dans deux processus.
L'absence de duplication est prévue par le code, mais reste à vérifier en exécution.

## Commandes ultérieures — après validation uniquement

Le PHP du PATH est 8.2.12. Laragon dispose de PHP 8.3.30 avec pdo_sqlite et SQLite 3.40.0.
Utiliser explicitement ce binaire, sans modifier le PATH. Les dépendances vendor sont déjà présentes.
Boost n'a pas été installé : l'ajout de dépendances et les scripts Composer sont hors de cette préparation.

Depuis la racine, en PowerShell :

~~~powershell
$demoPhp = 'C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe'
& $demoPhp -v
& $demoPhp --ri pdo_sqlite
if (Test-Path -LiteralPath .env.demo) { throw '.env.demo existe deja : conserver ce fichier.' }
Copy-Item -LiteralPath .env.demo.example -Destination .env.demo
~~~

Définir ensuite DEMO_OWNER_PASSWORD dans .env.demo : mot de passe local de 12 caractères minimum,
sans réutiliser un secret réel. La clé sera générée par le lanceur :

~~~powershell
& $demoPhp tools/demo.php key
& $demoPhp tools/demo.php check
& $demoPhp tools/demo.php create
& $demoPhp tools/demo.php migrate
& $demoPhp tools/demo.php seed
npm run build
& $demoPhp tools/demo.php serve
~~~

key écrit seulement APP_KEY dans .env.demo. check n'ouvre aucune base. create crée le fichier vide
sans écrasement. migrate appelle uniquement migrate --database=sqlite_demo ; seed appelle uniquement
db:seed --database=sqlite_demo --class=Database\Seeders\Demo\DemoSeeder dans l'application isolée.
Aucune commande fresh/reset/wipe ni option --force n'est exposée.

Ouvrir http://127.0.0.1:8016. Le serveur écoute exclusivement la boucle locale ; le routeur dédié
utilise les routes existantes, sans passer par public/index.php pour les requêtes applicatives.
L'hôte Laragon habituel continue d'utiliser MySQL : ne pas le confondre avec ce serveur demo.
Ctrl+C arrête la démonstration sans modifier la configuration normale.

Après accord, vérifier l'idempotence serveur arrêté en relançant l'action seed, puis contrôler
les comptes attendus, FK et stocks. Ne pas réinitialiser le fichier SQLite.

## Vérifications historiques — phase 16.1

Lint des sept fichiers PHP avec PHP 8.3.30 : réussi. git diff --check : réussi.
Exclusions SQLite, journaux, médias, secrets et exceptions des exemples : vérifiées.
.env.example, phpunit.xml, routes, bootstrap normal, migrations et DatabaseSeeder : inchangés.
Le lanceur refuse PHP 8.2 ; avec PHP 8.3 il refuse .env.demo absent avant de charger Laravel.
.env.demo et database/zemboa_demo.sqlite n'existent pas à l'issue de cette étape.
Aucun test Laravel, migration, seeding ou connexion PDO exécutés. Aucun commit ni push.

## Archive — Phase 16.2 - static security audit

Only static inspection, PHP lint and git diff --check were performed in this audit.
No launcher action, database creation, migration, seeding or PDO connection was executed.

The demo now disables Laravel dotenv searching entirely after explicitly parsing .env.demo.
Configuration is validated before provider registration, including for check/create.
DemoConnectionFactory filters normal and dynamic connection requests and checks again before
lazy connector creation. Alternative names, drivers, URLs, read/write/direct configurations
and unsupported options are refused. Laravel removes the null url field during parsing;
the guard accounts for this without accepting a non-null URL.

Database directories, file identity, SQLite header, hard links, symbolic links and sidecars
are checked without SQL. Configured runtime directories and generated media paths are also
checked for redirection. create uses exclusive creation and never overwrites a file.
key now refuses to replace an existing key or accept duplicate APP_KEY entries.

Only array cache, null queue/broadcasting and array mail backends remain configured.
The HTTP router never delegates requests back to the PHP server: it serves validated static
files itself and returns 503 on isolation failures. A non-demo store slug collision aborts seeding.

All six actions are SAFE under static review, with their prerequisites enforced. This is not
runtime certification: local configuration, migration compatibility and repeat-seeding checks
remain pending approval. Static checks cannot eliminate races caused by another local process
replacing files during execution; use only trusted local directories and stop the demo server
before seeding. The demo loads bootstrap/demo-database.php instead of evaluating shared database.php.
This also avoids its platform-specific MySQL constants under PHP 8.3. The shared file remains unchanged.
