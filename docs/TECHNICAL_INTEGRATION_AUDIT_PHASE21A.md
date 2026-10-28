# ZEMBOA — Phase 21A : audit général d'intégration, sécurité et cohérence métier

Date du rapport : 26 octobre 2026
Branche observée : `feat/customer-auth`
Nature de l'intervention : audit diagnostique, sans correctif métier ni opération persistante

## 1. Périmètre et garanties d'isolation

L'audit a repris les preuves déjà enregistrées dans `storage/framework/testing/phase21a/` après l'interruption réseau. Les suites réussies n'ont pas été rejouées. Les seules exécutions complémentaires ont clarifié les quatre échecs diagnostiques et terminé le contrôle des verrous de session.

Avant chaque nouvelle exécution PHPUnit :

- `phpunit.xml` imposait `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` et un `DB_URL` vide ;
- aucun cache `bootstrap/cache/config.php` n'était présent ;
- sessions et cache utilisaient les pilotes `array`, le mailer utilisait `array` ;
- PHP 8.3.30 de Laragon a été utilisé explicitement ; le PHP 8.2 trouvé par défaut dans le terminal a été refusé ;
- aucune connexion MySQL, lecture de SQLite DEMO, migration, commande, paiement ou invitation persistante n'a été exécutée.

L'empreinte des 290 fichiers locaux déjà présents au début de l'audit est conservée dans `baseline.json`. Le contrôle enregistré ne relève aucune modification de ces fichiers par l'audit. Seuls le test diagnostique et le présent rapport ont été ajoutés.

## 2. Modules inspectés

Les contrôles ont couvert :

- authentification acheteur, professionnelle et SUPER_ADMIN, guards, destinations et invitations ;
- rôles `SUPER_ADMIN`, `STORE_OWNER`, `STORE_MANAGER`, `CASHIER`, `DELIVERY_AGENT`, acheteur et invité ;
- isolation `store_id`, scopes tenant, policies et accès directs aux routes HTML/JSON ;
- catalogue, prix de variantes, promotions datées, panier et checkout ;
- commandes invitées et acheteurs, réservations, paiements partiels/intégraux et préparation ;
- livraison, retours, inspection, remise en stock et remboursement simulé ;
- POS, vente anonyme autorisée au comptoir, documents et mouvements manuels de caisse ;
- stock, mouvements, journal SQL d'idempotence et états des réservations ;
- personnalisation en brouillon, prévisualisation, publication et médias ;
- migrations préparées depuis la dernière preuve DEMO ;
- routes et navigation HTML FR/EN aux largeurs mobile, tablette et ordinateur ;
- état Git, dépendances, secrets potentiels et artefacts à exclure.

## 3. Parcours réellement vérifiés

### 3.1 Parcours commercial intégré

Le test `IntegrationAuditPhase21ATest` vérifie, pour un invité puis pour un acheteur connecté :

1. panier avec promotion devenue obsolète ;
2. refus `409` et nouvelle confirmation du devis ;
3. création unique de la commande et réservation du stock ;
4. rejeu idempotent sans deuxième commande ni réservation ;
5. acompte espèces confirmé, sans conversion prématurée ;
6. paiement intégral confirmé et conversion unique de la réservation ;
7. préparation commencée par le propriétaire puis marquée prête par le gestionnaire ;
8. affectation au livreur, récupération et livraison ;
9. retour, réception, inspection et remise en stock autorisée ;
10. remboursement administratif simulé ;
11. conservation du total historique de `2 200 FCFA` malgré les changements ultérieurs.

Les deux variantes du parcours réussissent. La seconde confirmation du même paiement et la seconde livraison sont refusées. Le test ne réalise aucun transfert financier externe.

### 3.2 POS

Les scénarios POS n'ont pas été dupliqués dans le nouveau parcours intégré. Ils sont couverts par la sélection de régression enregistrée, notamment `PosInterfaceTest`, `CashPaymentWorkflowTest`, `ConfirmPaymentStockSafetyTest`, `RecordPosCashMovementTest` et les audits RBAC caissier/gestionnaire. Ils confirment les ventes autorisées, l'encaissement espèces, l'idempotence, les snapshots, les documents et la conversion de stock uniquement après paiement intégral confirmé.

La permission `pos.cash_movements.manage` reste réservée par défaut au seul `STORE_OWNER`. Le caissier et le gestionnaire conservent leurs opérations POS ordinaires sans pouvoir créer de mouvement manuel de caisse.

### 3.3 Comptes et séparation des espaces

- `SUPER_ADMIN` : compte actif, `is_buyer=false`, sans boutique et avec exactement le seul rôle `SUPER_ADMIN` ; la connexion et le middleware utilisent le même invariant.
- Comptes professionnels : utilisateur et boutique actifs, tenant issu de l'acteur, identité professionnelle non ambiguë et vérification employé lorsque requise.
- Acheteur : guard `buyer`, compte actif et identité acheteur ; aucune permission professionnelle implicite.
- Une session professionnelle active est refusée par `PublicShopping` pour le panier et le checkout publics. Le POS utilise son propre contexte.
- Les invitations restent expirantes, à usage unique, sans cache ni referrer ; les journaux masquent les secrets.

### 3.4 Prix et historique

- Prix normal et promotion sont relus côté serveur.
- Un changement de prix/frais invalide le devis signé et exige une reconfirmation.
- Les lignes et montants de commande restent des snapshots historiques.
- Le paiement partiel conserve la réservation ; seul le paiement intégral confirmé la convertit.
- Le Mobile Money réel n'est pas actif : le vérificateur de production refuse l'opération sans prestataire, les tests emploient uniquement un double.
- Les remboursements sont explicitement simulés ; aucun remboursement externe n'est promis.

## 4. Résultats des tests et preuves

| Lot | Résultat | Portée |
|---|---:|---|
| Régressions ciblées | **224 tests, 2 061 assertions, 0 échec** | 19 fichiers couvrant comptes, panier/checkout, POS, livraison, catalogue, stock, ventes, personnalisation, promotions et audits RBAC |
| Paiements/réservations complémentaires | **15 tests, 59 assertions, 0 échec** | Mobile Money simulé, confirmation atomique, sécurité POS, paramètres de réservation |
| Verrouillage des sessions | **12 tests, 182 assertions, 0 échec** | ordre middleware, verrou partagé, exception, sessions distinctes, valeurs invalides et DEMO |
| Audit intégré Phase 21A | **7 tests, 3 réussis, 4 échoués, 137 assertions** | deux parcours bout en bout, caractérisation de configuration commerciale, quatre défauts diagnostiques |
| Chrome isolé | **36 contrôles, 36 réussis** | FR/EN, largeurs 390/768/1440, checkout invité/connecté, commande, stock, livreur |

Les contrôles Chrome utilisent les vrais rendus Blade produits par les tests en mémoire. Le réseau était intercepté, seules des requêtes locales `GET` ont été autorisées, et aucune mutation n'a été effectuée. Aucun débordement horizontal, erreur JavaScript, label manquant ou défaut de focus clavier n'a été détecté.

Preuves principales :

- `storage/framework/testing/phase21a/regressions.xml`
- `storage/framework/testing/phase21a/payments.xml`
- `storage/framework/testing/phase21a/integration.xml`
- `storage/framework/testing/phase21a/sessions.xml`
- `storage/framework/testing/phase21a/chrome.json`
- `storage/framework/testing/phase21a/git-diagnostic.json`

Ces artefacts sont locaux et exclus de Git.

## 5. Défauts confirmés

### A21-01 — rôle cumulé accepté par les anciens endpoints de stock

**Gravité : IMPORTANTE — autorisation et intégrité du stock**

Un utilisateur `STORE_OWNER + DELIVERY_AGENT` est correctement refusé par l'interface professionnelle `/espace-professionnel/stock`, car `CatalogAccess` exige une identité professionnelle non ambiguë. La route historique `POST /stock/{stockLevel}/receive` retourne pourtant une redirection de succès et fait passer le stock physique de 10 à 11.

Cause : les routes professionnelles modernes passent par `EnsureCatalogAccess` et `CatalogAccess::context()`. Les anciens endpoints utilisent seulement la policy `StockLevelPolicy::manage()`, qui contrôle `inventory.manage` et `store_id`, sans appliquer l'invariant d'identité exact.

Fichiers concernés :

- `routes/web.php` (routes `stock.receive`, `stock.adjust-in`, `stock.adjust-out`) ;
- `app/Http/Controllers/StockController.php` ;
- `app/Policies/StockLevelPolicy.php` ;
- `app/Support/AccountDestination.php` ;
- `app/Support/CatalogAccess.php` ;
- `app/Http/Middleware/EnsureCatalogAccess.php`.

Risque : un compte professionnel devenu ambigu conserve une permission héritée et peut muter le stock par appel direct, malgré le refus de l'interface HTML. L'isolation interboutique est encore appliquée dans le cas testé ; le défaut porte sur l'identité et l'autorisation intra-boutique.

### A21-02 — gestionnaire non vérifié accepté par les anciens endpoints de stock

**Gravité : IMPORTANTE — autorisation et intégrité du stock**

Un `STORE_MANAGER` dont l'e-mail n'est pas vérifié est refusé par l'interface moderne, mais le même endpoint historique de réception accepte la requête et modifie le stock de 10 à 11.

La cause et les fichiers sont les mêmes que pour A21-01. Les deux échecs démontrent que la divergence n'est pas limitée aux rôles cumulés : les restrictions d'identité professionnelles ne sont pas uniformes entre les frontières HTTP.

### A21-03 — livraison à domicile à 0 FCFA acceptée au checkout

**Gravité : IMPORTANTE — cohérence commerciale et blocage opérationnel**

La règle validée impose des frais strictement positifs pour une livraison à domicile, sauf future politique explicitement autorisée. Le checkout accepte pourtant une zone active à `0.00 FCFA`, crée la commande et réserve le stock. L'affectation du livreur refuse ensuite cette commande avec `422`, car `AssignDeliveryAgent` exige déjà des frais positifs.

Le test diagnostique est désormais formulé à la bonne frontière : le checkout doit refuser la commande avant toute création/réservation. Il attend `422`, zéro commande, zéro réservation et zéro quantité réservée ; le code retourne actuellement `201`.

Cause : `PublicCheckout::quote()` et `CreateOrder` reprennent le montant de `DeliveryZone::fee` sans imposer `fee > 0`, alors que `AssignDeliveryAgent` et `UpdateDeliveryStatus` l'imposent plus tard.

Fichiers concernés :

- `app/Services/PublicCheckout.php` ;
- `app/Actions/Orders/CreateOrder.php` ;
- `app/Actions/Orders/OrderPriceCalculator.php` ;
- `app/Actions/Deliveries/AssignDeliveryAgent.php` ;
- `app/Actions/Deliveries/UpdateDeliveryStatus.php` ;
- `app/Models/DeliveryZone.php` ;
- `database/migrations/2026_10_08_185639_create_delivery_zones_table.php`.

Risque : commande impossible à affecter, stock réservé inutilement et incohérence entre devis, commande et exécution logistique.

### A21-04 — total dépassant `DECIMAL(12,2)` accepté au checkout

**Gravité : IMPORTANTE — intégrité financière et compatibilité MySQL**

Deux unités à `6 000 000 000 FCFA` produisent une commande à `12 000 000 000.00 FCFA`. SQLite accepte la création et deux unités sont réservées. La couche paiement refuse ensuite ce montant, car `FinalizeOrderPayment::amountToCents()` protège la limite `DECIMAL(12,2)`.

Cause : le calculateur et la création de commande ne valident pas la borne maximale des colonnes `orders`, `order_items` et `payments` avant l'écriture/réservation. SQLite ne reproduit pas strictement la contrainte numérique MySQL/MariaDB.

Fichiers concernés :

- `app/Actions/Orders/OrderPriceCalculator.php` ;
- `app/Actions/Orders/CreateOrder.php` ;
- `app/Services/PublicCheckout.php` ;
- `app/Actions/Payments/FinalizeOrderPayment.php` ;
- migrations `create_orders_table`, `create_order_items_table` et `create_payments_table`.

Risque : commande non payable et réservation bloquée sous SQLite ; erreur ou troncature DDL possible selon le mode SQL MySQL/MariaDB. La validation doit précéder toute commande et toute réservation.

## 6. Autres anomalies et limites

### A21-05 — configuration commerciale seulement bloquée dans l'interface

**Gravité : MOYENNE à IMPORTANTE — contrôle serveur incomplet**

Lorsque `commercial_configured=false`, la vue HTML désactive visiblement la confirmation, mais l'API JSON de checkout accepte encore une requête directe et crée une commande. Le test de caractérisation réussit parce qu'il décrit le comportement actuel ; ce succès ne constitue pas une validation métier.

Fichiers : `resources/views/marketplace/merchant/checkout.blade.php`, `app/Services/PublicCheckout.php`, `app/Models/StoreSetting.php`.

### A21-06 — navigation propriétaire/gestionnaire vers des réponses JSON de livraison

**Gravité : MOYENNE — intégration frontend incomplète**

La navigation professionnelle affiche encore un lien « JSON » vers les routes historiques de livraison pour propriétaire/gestionnaire. L'espace livreur possède bien ses pages HTML. Il manque une interface de gestion des affectations adaptée aux rôles de boutique, ou une décision explicite de retirer ce raccourci.

Fichiers : `resources/views/professional/navigation.blade.php`, `resources/views/professional/dashboard.blade.php`, `routes/web.php`, `app/Http/Controllers/DeliveryController.php`.

### A21-07 — réservations échues et expiration automatique

**Gravité : MOYENNE — risque opérationnel connu**

L'expiration automatique reste désactivée et les réservations `ACTIVE` échues continuent de retenir le stock jusqu'à traitement explicite. Ce choix est conforme aux décisions actuelles, mais exige une procédure d'exploitation avant production. Aucun traitement d'expiration n'a été activé durant l'audit.

### A21-08 — limites du verrouillage de session

**Gravité : MOYENNE — limite de déploiement**

Les 12 tests de session réussissent : le verrou natif Laravel est configuré avant `StartSession`, couvre toutes les routes web, libère le verrou après exception et sépare les sessions. La valeur par défaut `file` ne coordonne cependant pas plusieurs serveurs. Le bail de 120 secondes n'est pas renouvelé ; une requête plus longue pourrait chevaucher la suivante.

Fichiers : `app/Http/Middleware/UseCartSessionLocks.php`, `bootstrap/app.php`, `config/session.php`.

### A21-09 — atomicité médias/fichiers et SQL

**Gravité : FAIBLE à MOYENNE — limite technique**

La personnalisation protège l'isolation des chemins, le brouillon et la publication, mais une opération fichier ne participe pas à la transaction SQL. Un échec rare entre écriture média et validation SQL peut nécessiter un nettoyage. Aucun défaut d'accès croisé n'a été reproduit.

## 7. Risques de permissions et sécurité

La faille confirmée concerne les anciens endpoints de stock. Le même écart de conception — routes historiques protégées uniquement par permission/policy face à des interfaces modernes exigeant une identité exacte — doit être recherché avant correction dans les autres mutations historiques, notamment catalogue, paiement, livraison et SAV. Aucun contournement supplémentaire n'est déclaré confirmé sans test dédié.

Les protections suivantes sont présentes dans les scénarios exécutés :

- scopes tenant et contrôles explicites de `store_id` ;
- CSRF sur formulaires HTML et authentification sur routes professionnelles ;
- refus des boutiques/comptes inactifs dans les accès modernes ;
- idempotence persistante checkout, POS et formulaires HTML de stock ;
- verrouillage et transactions des opérations sensibles ;
- invitations sans cache/referrer et secrets masqués ;
- prix, promotions et mouvements manuels de caisse réservés au propriétaire selon les décisions validées.

Aucune preuve ne permet de déclarer l'application protégée contre toute attaque. Les tests SQLite ne remplacent pas les contrôles de concurrence et de DDL sur l'environnement SQL de production.

## 8. Inventaire des migrations

Le dépôt contient 57 migrations. La dernière preuve persistante autorisée, datée du 23 octobre 2026 dans `CHECKOUT_DEMO_MIGRATION_2026_10_23.md`, indique 52 migrations appliquées et aucune en attente dans SQLite DEMO à cette date. La base DEMO n'a pas été relue durant la Phase 21A ; son état actuel n'est donc pas affirmé.

Si cet état historique est resté inchangé, les cinq migrations candidates sont, dans l'ordre :

1. `2026_10_24_083724_add_pos_submission_snapshot_to_orders_table.php`
2. `2026_10_24_142445_restrict_manual_pos_cash_movements_to_store_owner.php`
3. `2026_10_25_023117_create_stock_operation_submissions_table.php`
4. `2026_10_25_052900_add_preparation_tracking_to_orders_table.php`
5. `2026_10_25_075628_create_store_customizations_table.php`

État MySQL/MariaDB : inconnu, car aucune connexion n'a été autorisée. Les documents antérieurs indiquent que `is_buyer` peut devoir précéder la migration d'identité téléphonique. Il est donc interdit de déduire la liste exacte des migrations MySQL en attente depuis les seuls tests SQLite.

### Risques de rollback et de déploiement

- POS : le `down()` refuse de retirer les colonnes si des ventes POS, clés ou identités anonymes les utilisent. Tester les modifications de nullabilité, JSON, FK et index unique sur la même version MySQL/MariaDB.
- Permission mouvements manuels : rollback volontairement non destructif ; il ne réaccorde pas silencieusement l'ancienne permission.
- Journal stock : sa suppression invalide les anciens jetons mais ne supprime pas les mouvements métier. Une mise à niveau ne doit pas remplacer une table déjà utilisée sans migration corrective.
- Préparation : le rollback refuse de perdre un historique déjà renseigné.
- Personnalisation : le rollback refuse de supprimer des brouillons ou une configuration commerciale non finalisée ; les médias demandent aussi une procédure de conservation.
- Identité acheteur : les expressions calculées, collations, index et opérations DDL restent à valider sur MySQL/MariaDB jetable de même version.
- Les DDL MySQL peuvent ne pas être transactionnels ; sauvegarde, absence d'écrivains et restauration testée sont obligatoires.

## 9. Frontend et navigation

Les 36 vérifications Chrome isolées couvrent FR/EN et 390, 768 et 1440 px. Checkout, commande, stock et espace livreur sont lisibles, sans débordement horizontal ni erreur JavaScript. Les champs contrôlés disposent de labels et la navigation clavier expose un focus.

Différence restante confirmée : les propriétaires/gestionnaires sont encore dirigés vers du JSON pour les livraisons. Aucun audit visuel général ni nouvelle refonte n'a été entrepris.

## 10. État Git et recommandations

Au dernier contrôle avant rédaction :

- branche : `feat/customer-auth`, suivi `origin/feat/customer-auth` ;
- 96 fichiers suivis modifiés ;
- 197 fichiers non suivis réels, dont le test diagnostique et le présent rapport ;
- index vide ;
- aucun chemin de base SQLite, WAL/SHM, sauvegarde, `.env`, ZIP, capture ou cache destiné à Git ;
- aucun secret réel détecté dans la sélection source ;
- `git diff --check` et `git diff --cached --check` réussissent ; seul un avertissement de conversion CRLF/LF concerne `cart-card-action.blade.php`.

Ne pas utiliser `git add .` ou `git add -A`. Préparer les commits après correction des défauts, dans un ordre de dépendance :

1. sécurité d'identité, invitations et sessions ;
2. espaces administrateur, acheteur, équipe et dashboard ;
3. POS et livraison, avec dépendances Composer Dompdf ;
4. correction RBAC des mouvements manuels ;
5. navigation/authentification ;
6. catalogue et stock/idempotence ;
7. commandes, préparation et SAV ;
8. personnalisation ;
9. promotions et checkout ;
10. tests de régression et documentation d'audit.

Le test `IntegrationAuditPhase21ATest.php` doit rester diagnostique tant que ses quatre tests échouent. Il pourra devenir un test de non-régression normal après les corrections validées.

Exclure systématiquement : `.env`, bases/sidecars SQLite, sauvegardes, `storage/demo`, `storage/framework/testing/phase21a`, logs, caches, `public/build`, captures, PDF de preuve et archives.

## 11. Ordre recommandé des corrections

1. **A21-01/A21-02** : harmoniser les routes historiques de stock avec l'invariant d'identité professionnelle exact ; vérifier réception, ajustements entrant/sortant, accès interboutique, rôles cumulés, comptes non vérifiés/inactifs et appels directs.
2. **A21-04** : introduire une borne monétaire commune et exacte avant création de commande/réservation, réutilisée par checkout et POS ; tester les limites juste en dessous, égale et au-dessus de `DECIMAL(12,2)`.
3. **A21-03** : imposer les frais de livraison strictement positifs lors de la configuration et au checkout ; prévoir un précontrôle des anciennes zones à zéro avant migration/activation.
4. **A21-05** : appliquer `commercial_configured` côté service/action, sans dépendre du bouton HTML.
5. Auditer les autres endpoints historiques pour la même divergence d'identité et supprimer les raccourcis JSON essentiels ou créer les pages autorisées.
6. Rejouer le test intégré : les sept tests doivent réussir sans modifier les assertions pour masquer un défaut.
7. Seulement ensuite préparer la Phase 21B sur une copie SQLite jetable puis un MySQL/MariaDB jetable de même version/configuration.

## 12. Scénarios à réserver aux phases 21B/21D

### Phase 21B — migrations jetables

- appliquer les cinq migrations candidates dans l'ordre sur une copie SQLite neuve et vérifier `up/down/up`, contraintes, index et conservation ;
- exécuter sur MySQL/MariaDB jetable de même version : DDL interrompu, types décimaux, expressions calculées, JSON, index/FK, InnoDB, collisions et rollbacks ;
- tester la contention réelle multi-connexion pour checkout, conversion de réservation, POS et journal de stock ;
- vérifier la procédure de sauvegarde/restauration avant toute autorisation persistante.

### Phase 21D — validation contrôlée

- après correction et migrations validées, tester sur un environnement DEMO isolé explicitement autorisé ;
- vérifier comptes/rôles, checkout invité/connecté, paiement simulé, préparation, livraison, retour, POS et historique ;
- confirmer les frais de livraison obligatoires et l'absence de zone à zéro ;
- ne réaliser aucun paiement externe et ne jamais utiliser MySQL `zemboa` comme base de test.

## 13. Vérifications non réalisées ou interrompues

Les éléments suivants ne sont pas présentés comme réussis :

- aucune lecture de l'état actuel de SQLite DEMO ; seul le rapport historique des 52 migrations est cité ;
- aucune lecture ou écriture de MySQL `zemboa` ;
- aucun test DDL ou de concurrence réelle MySQL/MariaDB ;
- aucun test multi-serveur du verrou de session avec Redis partagé ;
- aucun traitement automatique des réservations expirées ;
- aucune validation matérielle d'imprimante thermique ;
- aucune validation d'un prestataire Mobile Money réel ;
- aucune migration persistante, aucun seeder, commit, push, merge, reset ou changement de branche.

## Conclusion diagnostique

Les modules principaux sont intégrés et les parcours nominaux critiques réussissent en mémoire, y compris le cycle commande–paiement–stock–livraison–SAV. La Phase 21A ne recommande cependant pas encore la préparation persistante : quatre défauts importants sont confirmés. Les deux contournements de stock affectent l'autorisation, la livraison à zéro crée une commande inexploitable malgré la règle de frais obligatoires, et l'absence de borne monétaire peut créer une commande non payable ou incompatible avec MySQL.

La prochaine intervention doit être une correction ciblée de ces défauts, suivie de leurs tests de non-régression. La Phase 21B ne doit commencer qu'après validation de ces corrections.
