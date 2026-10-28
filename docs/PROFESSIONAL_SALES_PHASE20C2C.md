# Phase 20C-2C — Commandes, clients et SAV professionnels

## Périmètre livré

Sur `feat/customer-auth`, interfaces HTML rattachées au layout professionnel existant. Aucun accès MySQL ou SQLite DEMO, aucune migration persistante, aucun envoi externe, aucun paiement réel, aucun changement de branche ni opération Git d'indexation/commit/push. `PROJECT_RULES.md` n'est pas modifié ; tous les travaux locaux antérieurs sont conservés.

- `/espace-professionnel/commandes` : pagination serveur, recherche numéro/nom/téléphone, filtres état réel, paiement, remise et période ; préparation filtrable lorsque son schéma est disponible. Comptages de toute la boutique issus du SQL.
- Détail : coordonnées figées avec la commande, articles/prix/quantités, montants, remise, livraison et préparation. Détail des paiements limité à `payments.view` ; aucun identifiant de prestataire sensible ou jeton checkout affiché.
- `/espace-professionnel/clients` : fiches locales liées à des commandes, commandes explicitement liées à un compte acheteur, et commandes sans compte ni fiche. Recherche/pagination et historique strictement limité à la boutique.
- `/espace-professionnel/retours` : recherche/période/statut, détail et historique réel, ouverture depuis une commande, décision, réception, inspection et remise en stock contrôlée.
- Remboursement préparé/approuvé/simulé depuis le dossier, avec confirmation explicite et mention d'absence de transfert de fonds.
- Navigation Stock/Catalogue existante conservée ; liens Commandes/Clients/SAV, liens récents et raccourcis du dashboard raccordés au HTML. Les endpoints JSON historiques restent présents.

## Préparation : contrat et migration

Migration préparée uniquement : `2026_10_25_052900_add_preparation_tracking_to_orders_table.php`.

Elle ajoute `preparation_started_at`, `preparation_started_by`, `ready_at`, `ready_by`, deux FK utilisateurs RESTRICT et l'index `orders_preparation_index`. Les champs sont nullables pour les commandes historiques, exclus du mass assignment et de la sérialisation JSON existante. Les dates/acteurs ne sont jamais acceptés depuis le formulaire.

La préparation est indépendante de `orders.status` : ce statut reste PENDING/CANCELLED selon le moteur actuel. Le modifier en PREPARING/READY aurait cassé les préconditions existantes de livraison et d'annulation. Les deux jalons constituent un historique réel nouveau ; aucune date historique n'est inventée.

`AdvanceOrderPreparation` autorise uniquement un professionnel web actif, propriétaire ou gestionnaire vérifié selon le contrat existant, avec `orders.view` et `orders.update`, tenant et policy valides. Sous transaction et verrou de commande : version courante du formulaire, PENDING/PAID, hors POS, remise PICKUP/DELIVERY, pas de livraison déjà prise en charge/échouée/terminée, somme exacte des paiements CONFIRMED datés, et réservations CONVERTED correspondant aux articles sont vérifiés. Les incohérences historiques sont refusées, pas réparées automatiquement.

Le début ne peut être enregistré qu'une fois ; le passage à prête exige ce début et ne peut être répété. Une version obsolète ou une transition répétée produit un refus 409 sans effet supplémentaire. Acteurs et dates ne sont pas écrasés. Le stock, les réservations, les paiements et leurs historiques restent inchangés. Le verrou de commande est compatible avec les Actions de paiement/livraison qui verrouillent aussi la commande en premier.

Sans schéma complet (colonnes, index et FK), la lecture reste disponible et la mutation est refusée en 503. `down()` refuse de supprimer les champs dès qu'un jalon ou acteur est enregistré : aucune disparition silencieuse de l'historique de préparation. Le cycle sans jalon conserve commandes, paiements et autres tables.

## Clients et confidentialité

`SalesAccess` et `EnsureSalesAccess` réutilisent l'identité professionnelle existante : guard web, compte et boutique actifs, rôle unique autorisé, permissions et TenantContext. Les acheteurs seuls, caissiers, livreurs, SUPER_ADMIN, comptes désactivés, gestionnaires non vérifiés et combinaisons de rôles ne peuvent pas utiliser ces pages. Les contrôles existent aussi sur les POST et dans l'Action de préparation, pas seulement dans les menus. Les réponses HTML portent `private, no-store` et `no-referrer`.

Les trois sources clients ne sont pas fusionnées par leurs coordonnées. Une identité acheteur utilise seulement le `buyer_user_id` explicitement enregistré sur les commandes de cette boutique ; ses coordonnées sont celles des snapshots de ces commandes. Aucun profil User global n'est lu ou modifié pour alimenter ces pages. Une commande invitée sans fiche n'est pas regroupée avec une autre commande sur la base d'un téléphone. Les fiches Customer et relations déjà enregistrées par le backend sont conservées, sans nouvelle association.

Les montants clients représentent les commandes sélectionnées, y compris leurs états réels, et sont libellés comme tels ; ils ne sont pas présentés comme de l'argent encaissé. Les statistiques d'un groupe acheteur recherché portent sur les commandes correspondantes. Le détail expose l'historique complet autorisé dans cette boutique. Aucun formulaire d'édition du compte acheteur global n'est ajouté.

## SAV : moteur existant réutilisé

`CreateReturnRequest`, `ReviewReturnRequest`, `ReceiveReturnRequest`, `InspectReturnRequest`, `RestockReturnRequest`, puis `PrepareRefund`, `ApproveRefund`, `SimulateRefund` sont appelés directement. Aucune deuxième logique de remise en stock ou de remboursement n'est créée.

L'interface SAV reste propriétaire uniquement, avec `orders.view`, `returns.view` et les permissions de mutation requises. Les remboursements nécessitent en outre leurs permissions de consultation/gestion. Le gestionnaire ne reçoit pas de permission SAV implicite.

La création HTML verrouille la commande et compare la dernière demande connue avant d'appeler l'Action existante : un double envoi du même ancien formulaire est refusé sans créer deux dossiers. Les décisions, réceptions, inspections et remises en stock restent protégées par les états et verrous de leurs Actions. Les articles d'un dossier ne peuvent pas exposer un OrderItem d'une autre commande.

La remise en stock exige l'inspection de toutes les quantités et une confirmation physique explicite. Seul `ReturnStock` modifie la quantité physique et crée RETURN, à partir des quantités revendables enregistrées. Le livreur ne possède aucune nouvelle route de remise en stock. Le remboursement reste administratif/simulé, avec plafonds et approbation existants ; le statut SIMULATED ne signifie jamais qu'un transfert réel a eu lieu.

## Vérifications et preuves

- 16 tests ProfessionalSalesTest, un test de cycle OrderPreparationMigrationTest et cinq StockOperationMigrationTest : **22 tests, 264 assertions réussies**.
- Régressions PublicCheckoutTest, PosInterfaceTest, DeliveryAgentInterfaceTest, ProfessionalDashboardTest, OrderManagementHttpTest et CustomerTest : **93 tests, 577 assertions réussies**.
- Total distinct : **115 tests, 841 assertions**. Toutes les exécutions de cette phase utilisent SQLite `:memory:` ; aucun fichier DEMO ou base existante n'est ouvert.
- Le test antérieur de cycle du journal stock cible désormais son batch et son chemin exact, sans supposer qu'il est la dernière migration du projet. Il conserve son mode jetable historique mais est exécuté ici avec `ZEMBOA_MIGRATION_AUDIT_IN_MEMORY=1` ; aucune base fichier n'est créée pendant cette phase.
- Couverture : rôles, permissions directes, CSRF natif, tenant, données globales privées absentes, filtres/pagination, versions concurrentes obsolètes, répétitions, cohérence du paiement et des conversions, refus POS, jalons atomiques, SAV complet, double création, inspection excessive, confirmation physique obligatoire, remboursement simulé et préservation du stock/paiement.
- Vite 8.3.4 : build réussi. L'avertissement optionnel fontaine préexistant ne bloque pas la compilation ; aucune dépendance ajoutée. Syntaxe PHP/JS, Pint ciblé, contrôles Git et nouveaux fichiers vérifiés.

Chrome installé + Playwright Core existant : **108 contrôles**, neuf vues en FR/EN, largeurs **360, 390, 768, 1024, 1280 et 1440 px**. Rendus Blade issus des requêtes HTTP des tests en mémoire ; navigation réseau du navigateur bloquée. Aucun serveur DEMO ni achat/paiement appelé. Navigation active, menu/Escape, clavier, absence de débordement, confirmation/annulation et blocage du double submit vérifiés ; la décision APPROVED est conservée lorsque le bouton est désactivé. Les transitions SAV d'inspection/remboursement sont vérifiées par HTTP, pas par une session navigateur persistante.

Preuves locales ignorées de Git : `storage/demo/phase20c2c-preview/results.json` et captures `*-fr-390.png`, `*-fr-1440.png`, `*-en-390.png`, `*-en-1440.png`. Les données sont synthétiques ; CSRF et versions cachées sont neutralisés dans les exports. La vue de préparation est capturée avant livraison ; le dossier SAV est capturé après création, conformément à ses préconditions.

## Écarts assumés et limites restantes

- Identité/layout professionnel existant conservés ; organisation cartes/tableaux/récapitulatif inspirée de la maquette, sans refonte globale de sidebar.
- Aucun statut CONFIRMED/PREPARING/READY/LIVRÉ/RETIRÉ artificiellement écrit dans `orders.status`. La livraison a ses propres états ; le retrait ne possède pas encore de confirmation de remise nouvelle.
- Les règles de livraison existantes ne sont pas modifiées pour exiger READY avant prise en charge. Le nouveau suivi de préparation n'est pas propagé implicitement au frontend acheteur ou à l'API JSON existante.
- Le SAV actuel vise les commandes payées et livrées, dans le délai configuré : aucune extension aux retours POS/retrait, aucune délégation gestionnaire, aucun moteur d'échange ou remboursement réel.
- Pas d'impression, messagerie client ou workflow de signalement fictifs pour imiter des boutons illustratifs.
- La migration n'est appliquée à aucune base persistante. Avant déploiement, autoriser séparément son application et vérifier MySQL/MariaDB sur une base jetable de même version : DDL/FK/index, schéma partiel, préservation de données, refus de rollback avec jalons, transactions et sessions réellement simultanées. SQLite teste les refus de versions obsolètes et les transactions, pas les verrous concurrents réels MySQL.
- Les configurations et permissions historiques restent la source d'autorité. Aucune donnée incohérente n'est corrigée automatiquement.

## Fichiers de cette phase

Créations :

```text
app/Actions/Orders/AdvanceOrderPreparation.php
app/Support/SalesAccess.php
app/Http/Middleware/EnsureSalesAccess.php
app/Http/Controllers/Professional/OrderManagementController.php
app/Http/Controllers/Professional/CustomerHistoryController.php
app/Http/Controllers/Professional/AfterSalesController.php
database/migrations/2026_10_25_052900_add_preparation_tracking_to_orders_table.php
lang/fr/sales.php
lang/en/sales.php
resources/css/professional-sales.css
resources/js/sales.js
resources/views/professional/sales/nav.blade.php
resources/views/professional/sales/orders.blade.php
resources/views/professional/sales/order.blade.php
resources/views/professional/sales/order-row.blade.php
resources/views/professional/sales/order-table.blade.php
resources/views/professional/sales/money.blade.php
resources/views/professional/sales/customers.blade.php
resources/views/professional/sales/customer.blade.php
resources/views/professional/sales/returns.blade.php
resources/views/professional/sales/return.blade.php
resources/views/professional/sales/return-create.blade.php
tests/Feature/ProfessionalSalesTest.php
tests/Feature/OrderPreparationMigrationTest.php
docs/PROFESSIONAL_SALES_PHASE20C2C.md
```

Intégrations aux fichiers existants localement :

```text
app/Models/Order.php
app/Policies/OrderPolicy.php
app/Http/Controllers/Professional/DashboardController.php
routes/web.php
resources/css/app.css
resources/js/app.js
resources/views/professional/navigation.blade.php
resources/views/professional/layout.blade.php
resources/views/professional/dashboard.blade.php
tests/Feature/StockOperationMigrationTest.php
```

35 fichiers concernés par la phase ; ce périmètre ne doit pas être confondu avec tous les travaux locaux antérieurs non committés. Aucun index préparé. Les phases 20C-2D/20C-2E ne sont pas commencées.
