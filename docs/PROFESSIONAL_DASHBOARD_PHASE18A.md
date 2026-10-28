# Phase 18A — Tableau de bord professionnel

## Accès et architecture

`GET /espace-professionnel` (`professional.dashboard`) affiche un layout professionnel distinct des vitrines et de l'espace acheteur. La connexion professionnelle HTML conduit désormais ici pour les propriétaires/gestionnaires autorisés ; la réponse JSON de connexion reste inchangée.

Le guard `web`, le middleware tenant et les scopes `BelongsToStore` existants sont conservés. La boutique provient exclusivement du professionnel authentifié, jamais d'un paramètre HTTP. Une session `buyer` seule n'ouvre pas cet espace.

La nouvelle `OrderPolicy::viewAny` centralise le contrôle de lecture auparavant présent dans `OrderController`. Elle exige un compte actif, le tenant correspondant, `orders.view`, un rôle propriétaire ou gestionnaire et refuse SUPER_ADMIN. Un gestionnaire doit avoir vérifié son e-mail, conformément à la connexion professionnelle existante. Ce contrôle s'applique également aux routes JSON de consultation des commandes.

Les liens vers catégories, stock, clients et paramètres sont filtrés par leurs policies existantes. Aucun droit n'est ajouté. Le contrôle de session natif et son verrouillage, CSRF et les mécanismes buyer ne sont pas modifiés.

## Données affichées

- Commandes reçues : toutes les commandes enregistrées pour la boutique, depuis sa création.
- Commandes en attente : statut de commande `PENDING`, indépendamment du statut de paiement.
- Réservations actives : nombre de **lignes** de réservation au statut `ACTIVE`, pas quantité d'unités. Une échéance passée ne change pas ce statut tant que le traitement métier d'expiration n'a pas été exécuté.
- Alertes de stock : variantes dont `physical_quantity - reserved_quantity <= low_stock_threshold`, selon le seuil propre à chaque ligne, comme `StockLevel::isLowStock()`.
- Quantités physiques, réservées et disponibles présentées séparément.
- Huit dernières commandes, avec numéro, contact enregistré dans la commande, unités, total FCFA, deux statuts, réception et date. Accès à la liste complète JSON existante ; aucune pagination fictive.
- Répartition par statut de commande, calculée en SQL pour la boutique.

Les indicateurs de stock/réservations sont absents sans `inventory.view`. Les agrégats sont exécutés côté Laravel/SQL ; les unités des commandes sont chargées par `withSum`, sans requête par ligne. Les réponses HTML sont privées et non mises en cache.

Aucun chiffre d'affaires n'est déduit des commandes impayées. Aucun stock, paiement, commande ou compte n'est modifié par ce dashboard.

## Interface et limites assumées

La maquette guide le header sticky, la sidebar de 240 px, le bandeau vert, les cartes, le tableau et la navigation mobile. Les données, les libellés et la disposition s'adaptent aux permissions et au contenu réel. Nom, statut et logo de boutique sont dynamiques ; initiale de remplacement si aucun logo n'est enregistré.

Les accès marqués **JSON** ouvrent les routes fonctionnelles existantes. Leurs écrans de gestion HTML restent à développer dans les sous-phases suivantes. Aucun bouton de changement de statut n'est ajouté. La rubrique Produits est non interactive lorsque sa permission existe, car aucune liste professionnelle HTML/GET de produits n'est actuellement disponible ; le catalogue public reste accessible.

Les graphiques de ventes, notifications, promotions, meilleures ventes et actions d'édition de la maquette sont reportés. Aucun chiffre ou bouton fictif ne les remplace. Le menu natif `details` fonctionne au clavier, se ferme avec Échap ou un clic extérieur ; ses autres modules restent accessibles sur mobile.

Les traductions et deux composants de statut/icônes de la phase 17.4A sont réutilisés. Ces travaux locaux antérieurs sont à préserver et constituent des dépendances du livrable.

## Vérifications du 23 octobre 2026

- PHP Laragon 8.3.30 : lint réussi sur les huit fichiers PHP concernés, dont routes, traductions et tests.
- Configuration PHPUnit vérifiée : SQLite `:memory:`, URL vide et absence de `bootstrap/cache/config.php`. La nouvelle classe retire aussi les autres connexions de sa configuration de test.
- `ProfessionalDashboardTest`, `OrderManagementHttpTest`, `EmployeeAuthenticationTest` : 25 tests, 95 assertions réussis. Test supplémentaire connexion HTML/JSON : 1 test, 4 assertions réussis. Total : **26 tests, 99 assertions** ; toutes les fixtures restent en mémoire.
- Couverture : isolation malgré `store_id` fourni, permissions, rôles interdits, gestionnaire non vérifié, boutique suspendue, session buyer seule, seuils de stock et réservations, état vide, limite des dernières commandes, échappement des longues valeurs, anglais, connexion et API professionnelle existante.
- `node --check resources/js/professional.js`, `git diff --check` : réussis.
- Build Vite réussi. Avertissement préexistant du plugin fonts : `fontaine` optionnel absent ; aucune dépendance installée. Vite signale également les temps des plugins.
- Chrome installé, profil temporaire : contrôles FR/EN à 1440, 1280, 1024, 768, 390 et 360 px. Commandes existantes en anglais (Mountain Digital), état vide en français (Atelier du Wouri), selon les préférences déjà enregistrées des comptes DEMO. Aucun changement de langue écrit en base.
- Aucune erreur JavaScript, image cassée ou largeur horizontale excédentaire détectée. Liens existants autorisés répondant HTTP 200 ; ouverture et fermeture du menu vérifiées.
- Session professionnelle temporaire initialisée dans le stockage de sessions fichier, sans lire de mot de passe ni tester à nouveau des identifiants réels. Captures avec contacts masqués ; sessions d'audit supprimées ensuite. Requêtes limitées au serveur DEMO local et à GET/HEAD.
- Empreintes logiques de **toutes les tables SQLite DEMO** identiques avant/après chaque contrôle Chrome. Aucun accès MySQL, aucune migration persistante, aucun seeder persistant, aucune écriture métier ou paiement.

Captures et rapports locaux exclus de Git : `storage/demo/audits/professional-18a/`.

## Fichiers de cette phase

Modifiés : `app/Http/Controllers/Auth/SessionController.php`, `app/Http/Controllers/OrderController.php`, `routes/web.php`, `resources/css/app.css`, `resources/js/app.js`.

Créés : `app/Http/Controllers/Professional/DashboardController.php`, `app/Policies/OrderPolicy.php`, `lang/fr/professional.php`, `lang/en/professional.php`, `resources/views/professional/layout.blade.php`, `resources/views/professional/navigation.blade.php`, `resources/views/professional/dashboard.blade.php`, `resources/css/professional.css`, `resources/js/professional.js`, `tests/Feature/ProfessionalDashboardTest.php`, ce document.

Aucune migration, indexation Git, branche, commit ou publication n'est effectuée dans cette intervention.
