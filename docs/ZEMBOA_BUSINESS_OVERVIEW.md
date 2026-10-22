# ZEMBOA — compréhension métier avant la phase 17

Référence : `feat/marketplace`, commit `e162fd6`, audit statique du 21 octobre 2026.
Seul ce document est ajouté. Aucun test, bootstrap Laravel, accès aux bases, migration,
seeder ou développement fonctionnel pendant cet audit. Les tests cités sont des preuves
de couverture présente dans le dépôt, pas des tests réexécutés ni une certification de production.
Les 49 migrations MySQL exécutées constituent l'état validé lors de l'intervention précédente,
sans nouvelle consultation ici.

## 1. Architecture et responsabilité commerciale

ZEMBOA est une marketplace de découverte et un SaaS de gestion commerciale : le vendeur
est chaque commerçant, pas la plateforme. Une application Laravel 13/PHP 8.3, Blade,
Tailwind v4 et Vite utilise une base partagée, avec isolation des données marchandes par
`store_id`. La cible initiale est le Cameroun, les prix sont présentés en FCFA et l'interface
dispose de traductions FR/EN. L'internationalisation des contenus propres aux commerçants
n'est pas équivalente à celle des libellés d'interface et reste à concevoir.

Le flux à conserver est `HTTP → validation/autorisation → Action ou Service → modèles`.
Les contrôles d'autorisation sont répartis entre Policies et contrôleurs/actions ; il
n'existe pas une Policy dédiée à chacun des modules. Les interfaces ne doivent ni
dupliquer les calculs de prix/stock, ni constituer une nouvelle autorité métier.

- Public : marketplace et vitrines indépendantes `/boutiques/{shopSlug}` ; API JSON
  existantes sous `/shops/{shopSlug}`. Les boutiques doivent être `ACTIVE`.
- Professionnels : authentification, puis `auth` et `tenant`, avec boutique de l'utilisateur.
- Administration : routes `/admin`, contrôle `EnsureSuperAdmin`, gestion de plateforme.
- Acheteurs : identité globale et espace multiboutique à construire, distincts des dossiers
  `Customer` internes à chaque boutique. Les routes professionnelles actuelles ne sont
  pas un espace client à réutiliser tel quel.

Sources : [routes](../routes/web.php), [TenantContext](../app/Support/TenantContext.php),
[middlewares](../app/Http/Middleware/), [Policies](../app/Policies/).

## 2. Cartographie vérifiée

**Implémenté** signifie présent et lisible dans le code ; **partiel** indique des briques
existantes sans parcours complet ; **prévu** désigne une décision non encore réalisée.

| Mécanisme | État réel et frontière HTTP/interface | Sources réutilisables et tests présents |
|---|---|---|
| Marketplace, vitrines, catalogue | Implémenté en HTML et JSON ; catalogue public filtré par boutique/publication, variantes, images, prix. Phase 16 conservée. | `MarketplaceController`, `PublicCatalogController`, vues `marketplace/merchant/` ; `MarketplacePagesTest`, `PublicCatalogTest` |
| Personnalisation marchande | Partiel : champs logo/bannière/couleur et affichage ; API GET/PATCH `/store/settings`. Pas de formulaire complet ni workflow d'upload logo/bannière dans la requête actuelle. | `Store`, `StoreSetting`, `UpdateStoreSettingRequest`, `UpdateStoreSettings`, `StoreSettingPolicy` ; `StoreSettingHttpTest` ; [audit d'identités](MERCHANT_IDENTITIES_PHASE16.md) |
| Administration et invitation propriétaire | Implémenté : création/consultation/statut des boutiques, historique, invitation propriétaire ; interfaces admin/login et invitations. Inscription libre d'un commerçant soumise à validation : prévue, pas de parcours trouvé. | Contrôleurs `Admin/`, `OwnerInvitationController`, notifications ; `AdminStoreManagementTest`, `AdminStoreStatusTest`, `OwnerInvitationFlowTest` |
| Authentification professionnelle | Implémenté : `/login`, `/logout`, `/account` ; rôles professionnels autorisés, utilisateur/boutique actifs, vérification email des employés selon le rôle. `/account` renvoie du JSON sous middleware tenant. | `Auth/SessionController`, `User`, `config/auth.php` ; `EmployeeAuthenticationTest`, `SuperAdminAuthenticationTest` |
| Authentification acheteur globale | Prévu : ni inscription/connexion acheteur dédiée, ni espace client global trouvés. Le guard `web` utilise déjà `User`, mais le contrôleur de connexion refuse une identité sans boutique/rôle professionnel. | Réutiliser le socle Laravel et `User` après décision explicite sur la séparation des usages ; ne pas élargir silencieusement `/login` |
| Dossiers clients | Implémenté pour les professionnels : `/customers` CRUD partiel autorisé par `CustomerPolicy`. Fiche tenant, téléphone normalisé et unicité par boutique ; pas un compte de connexion. | `Customer`, `FindOrCreateCustomer`, `NormalizeCustomerPhone`, `CustomerController` ; `CustomerTest`, `OrderTest` |
| Panier | Implémenté côté JSON/session : GET `/shops/{shopSlug}/cart`, ajout, quantité, suppression, vidage. Pas de vue panier trouvée. Aucun stock réservé au panier. | `CartService`, `PublicCartController`, `OrderPriceCalculator` ; `PublicCartTest` |
| Checkout | Implémenté côté JSON, accessible aux visiteurs : POST `/shops/{shopSlug}/checkout`, sans paiement inclus. Pas de page HTML checkout ni rattachement à un acheteur global. | `PublicCheckoutController` → `CreateOrder` ; `PublicCartTest`, `OrderTest`, `StoreFulfillmentSettingsTest` |
| Commandes | Domaine implémenté : création, snapshots de prix/coordonnées, annulation, expiration, solde en retard. GET `/orders` et `/orders/{id}` réservés aux professionnels autorisés ; pas de suivi acheteur. Les actions d'annulation/expiration ne sont pas exposées par ces routes. | `Order`, `OrderItem`, `Actions/Orders/` ; `OrderTest`, `OrderManagementHttpTest`, `ListOverdueBalancesTest` |
| Stocks et réservations | Implémenté : quantités physiques/réservées, mouvements, transactions et verrous, conversion/libération/expiration ; HTTP professionnel `/stock`. Pas de scheduler d'expiration trouvé. | `StockLevel`, `StockReservation`, `StockMovement`, `Actions/Stock/`, `StockLevelPolicy` ; `StockHttpTest`, `StockMovementTest`, `ConfirmPaymentStockSafetyTest` |
| Paiement espèces et acomptes | Actions implémentées, distinctes de la création de commande : création CASH puis confirmation autorisée. Pas de route générique de création/confirmation de paiement trouvée dans `web.php`. Les paramètres de paiement partiel sont contrôlés à la création CASH. | `CreateCashPayment`, `ConfirmPayment`, `ValidateStorePaymentRules`, `FinalizeOrderPayment` ; `CashPaymentWorkflowTest`, `ValidateStorePaymentRulesTest`, `FinalizeOrderStockTest` |
| Mobile Money | Partiel et non opérationnel en paiement réel : actions d'initiation, compte marchand, vérification, confirmation atomique, claims anti-réutilisation et rapprochement ; endpoints de rapprochement professionnels. Le vérificateur réel n'est pas lié ; le binding applicatif lève une exception. Le faux vérificateur sert aux simulations/tests, pas à un prestataire intégré. | `StorePaymentAccount`, `Actions/Payments/`, `Payments/FakeMobileMoneyVerifier`, `AppServiceProvider` ; `MobileMoneyVerifierBindingTest`, `MobileMoneyConfirmationAtomicTest`, `MobileMoneyReconciliationHttpTest` |
| Livraison/retrait | Partiel : choix DELIVERY/PICKUP et paramètres contrôlés à la commande ; affectation/suivi des livraisons en JSON ; pas d'interface acheteur, ni endpoint dédié de confirmation de retrait identifié. | `DeliveryZone`, `Delivery`, `DeliveryController`, `Actions/Deliveries/` ; `AssignDeliveryAgentTest`, `CreateReturnRequestTest`, `StoreFulfillmentSettingsTest` |
| Retours et remboursements | Backend/HTTP professionnel implémentés : demande, décision, réception, inspection, restock ; remboursement préparé/approuvé/simulé. Pas de transfert financier ni de portail SAV acheteur. | `Actions/Returns/`, `Actions/Refunds/`, `ReturnRequestController`, `RefundController` ; `CreateReturnRequestTest` |
| POS | Partiel : caisses, sessions, mouvements d'espèces, clôture et rapport JSON routés ; actions de vente et paiement POS présentes, sans route dédiée de vente/paiement POS trouvée. Pas d'interface de caisse, recherche rapide/scan de code-barres trouvés. | `Actions/Pos/`, modèles `Pos*`, contrôleurs POS ; `CreatePosOrderIntegrationTest`, `ConfirmPosPaymentSecurityTest`, `PosSessionHttpTest`, `PosSessionTenantIsolationTest` |
| Factures, reçus et partage | Partiel : formats A4/TICKET_58/TICKET_80 et options numériques stockés/exposés par GET/PATCH `/store/document-settings`. Pas de générateur, route de téléchargement, facture/reçu rendu ou partage sécurisé trouvé. Le rapport de clôture JSON n'est pas un reçu client. | `StoreDocumentSetting`, `StoreDocumentSettingController` ; `StoreDocumentSettingTest`, `StoreDocumentSettingHttpTest` |
| Likes publics / avis | Likes décidés pour le MVP, pas de modèle/action/route identifié ; avis reportés. Aucun bouton inopérant à ajouter. | Décision utilisateur actuelle ; conception d'identité, anti-abus et comptage à valider |

## 3. Contrats métier à préserver

### Identité et preuve de propriété

`User` est une identité authentifiable, avec email unique, `store_id` nullable et rôles globaux.
Les professionnels du MVP sont attachés à une boutique ; la nullabilité sert notamment
au super administrateur et ne suffit pas à identifier un acheteur.
`Customer` est une fiche commerciale tenant, sans relation `User` ni `user_id` définis dans
les modèles examinés. `Order.customer_id` pointe vers cette fiche et conserve des snapshots.

`FindOrCreateCustomer` réutilise un téléphone normalisé dans le tenant courant sans écraser
le profil existant. **Un téléphone/email déclaré au checkout ne prouve pas la propriété
d'une fiche ou de ses anciennes commandes.** Le futur rattachement d'un compte ne doit pas
donner accès à cet historique par simple correspondance de coordonnées.

Décisions à valider : identité acheteur réutilisant `User`, séparation des sessions/usages
professionnels et acheteurs, éventuel rôle/discriminant, connexion/inscription et récupération
de compte, lien vérifié vers les fiches boutique, traitement des achats invités et du cumul
acheteur/professionnel. Aucun schéma ou nouveau rôle n'est créé par cet audit.

### Panier → commande → paiement → stock

1. `CartService` conserve une seule clé de session `zemboa.cart`, avec `store_id` et quantités
   par variante. Une autre boutique voit un panier vide ; les mutations passent par le contrôle
   de boutique et refusent le mélange. `clear()` efface la clé globale : l'UX de changement
   de boutique doit être explicite. Prix/promotions/disponibilité sont recalculés côté serveur.
2. Le checkout accepte les coordonnées et identifiants/quantités, pas les prix du navigateur.
   `CreateOrder` fusionne les variantes répétées, contrôle les modes de retrait/livraison et
   la zone active du tenant, ajoute ses frais, crée la commande `PENDING/UNPAID` et réserve
   chaque variante dans la même transaction. Le panier est vidé seulement après réussite.
3. La durée vient des paramètres boutique, sinon 30 minutes. `ExpireOrder` protège les
   commandes payées/partiellement payées ou déjà récupérées par un livreur. Aucune tâche
   périodique ne l'appelle actuellement dans `routes/console.php`.
4. **Paiement partiel : réservations conservées. Paiement intégral : réservations converties,
   stock physique et réservé décrémentés, mouvement `SALE`, état `PAID`.** Ce n'est ni au panier,
   ni au premier acompte, ni automatiquement lors du passage en `DELIVERED`.
5. `FinalizeOrderPayment::execute()` est un point d'entrée désactivé, mais ses méthodes
   spécialisées sont utilisées : ne pas remplacer les chemins CASH/atomiques existants.

Le verrou de session `->block(30, 10)` du checkout limite les accès concurrents d'une session ;
il ne constitue pas une clé d'idempotence durable ni un mécanisme de récupération de réponse
perdue. Les prix/transactions/verrous doivent continuer à être validés par les Actions.

### Services et fonds

- `AssignDeliveryAgent` et `UpdateDeliveryStatus` contrôlent acteur, tenant, affectation et
  transitions. `DELIVERED` exige `PAID`. Le livreur ne reçoit aucun droit de confirmation CASH.
- `ConfirmDeliveryReturn` et le SAV contrôlent la décision du propriétaire ; le restock n'est
  pas déclenché par le seul signalement du livreur. Le SAV actuel exige notamment commande
  payée, livraison terminée et fenêtre de retour configurée : le cas du retrait boutique
  ne doit pas être annoncé comme couvert par un parcours équivalent sans décision.
- Les comptes bénéficiaires sont ceux des boutiques (`StorePaymentAccount`,
  `AssignMerchantAccountToPayment`). La collecte centrale puis redistribution est exclue de
  la vision validée. Aucun mouvement MTN/Orange réel n'est disponible.
- `ProcessMobileMoneyPayment` refuse encore SUCCESS ; le chemin atomique existe séparément.
  La présence de simulations et tests n'active pas ces paiements dans l'application normale.
- WhatsApp : contact/renseignements uniquement ; commande et paiement restent dans ZEMBOA.

## 4. Sécurité et écarts à arbitrer avant exposition acheteur

1. **Accès client** : les scopes marchands protègent un tenant, pas la propriété acheteur.
   Ne pas réutiliser `/orders` comme historique global, désactiver les scopes pour contourner
   le problème ou accepter un `customer_id`/`user_id` fourni par le navigateur comme preuve.
   Le contexte professionnel vient de l'utilisateur ; le contexte public vient du slug actif.
   Les requêtes SQL publiques inter-boutiques ont des filtres explicites, pas la protection
   automatique du trait Eloquent. Les FK simples n'imposent pas toute l'intégrité inter-tenant.
2. **Réservations** : `allow_reservations` est paramétrable/affiché, mais aucun contrôle de ce
   booléen n'a été trouvé dans `CreateOrder`, qui réserve toujours. Préciser si cela désigne
   la réservation technique obligatoire du checkout ou une offre commerciale distincte.
   Ne pas retirer arbitrairement la réservation stock lorsque le paramètre vaut false.
3. **Acomptes** : `ValidateStorePaymentRules` est utilisé dans `CreateCashPayment` ; l'initiation
   Mobile Money calcule actuellement le solde entier. Un acompte uniforme pour tous les moyens
   n'est pas implémenté. Automatisation de l'expiration/solde en retard et UX restent à définir.
4. **RBAC** : après l'intervention MySQL précédente, gestionnaire = 8 permissions POS/documentaires,
   caissier = 4 POS. Les 13 droits opérationnels supplémentaires du gestionnaire ne sont pas
   attribués par ces migrations. `ConfirmPayment` exige aussi `payments.confirm`, absent de
   ces profils minimaux : une vente POS entièrement encaissée n'est donc pas garantie pour eux.
   Le seeder prévoit `payments.view/confirm` pour le caissier : leur portée sensible reste
   une décision explicite, pas une attribution à déduire du rôle.
5. **Tests RBAC à actualiser séparément** : `StoreManagerRbacAuditTest` attend exactement 13
   droits et `CashierRbacAuditTest` exactement 3, tandis que le seeder actuel en ajoute d'autres
   POS/documentaires. Ces assertions paraissent obsolètes par inspection ; aucun résultat
   d'exécution n'est affirmé. Les tests seedés ne décrivent pas automatiquement les droits MySQL.
6. **FR/EN** : des exceptions métier/authentification contiennent encore des chaînes françaises
   littérales. Les libellés traduits existants ne certifient pas un parcours entièrement bilingue.
7. **Documentation ancienne** : `README`, `PROJECT_RULES`, `DECISIONS`, `docs/ARCHITECTURE` et
   `docs/STATUS` conservent des références à `feat/stock` et à des modules désormais présents.
   Leur ancien énoncé du décrément de stock au départ physique ne décrit pas le paiement actuel.
   Ce document explicite l'écart ; aucune règle ni documentation historique n'est réécrite ici.
8. **Dette connue** : le `down()` historique de `create_permission_role_table` vise `role_user`.
   Ne pas utiliser un rollback automatique comme stratégie de reprise. Aucun nouvel audit
   de migration ni correction historique n'est effectué.

Tests de sécurité à réutiliser : `BelongsToStoreTest`, `TenantContextTest`,
`TenantMiddlewareAuditTest`, `EmployeeAuthenticationTest`, `SuperAdminAuthenticationTest`,
`PublicCartTest`, `OrderTest`, `ConfirmPosPaymentSecurityTest`, tests de paiements et SAV cités.
Leur présence est vérifiée statiquement ; aucun taux de réussite global actuel n'est déduit.

## 5. Première sous-étape proposée : 17.1, identité acheteur uniquement

Avant code, valider un court contrat d'identité couvrant les décisions de la section 3.
Proposition à discuter : réutiliser l'identité `User` plutôt que créer une seconde table de
comptes, avec une frontière acheteur explicite indépendante de `SetTenantContext`. Conserver
`Customer` comme fiche boutique et différer son rattachement au checkout. Ne pas identifier
un acheteur par le seul `store_id = null`, ni l'inscrire dans un rôle professionnel.

Après validation, limiter l'implémentation à inscription/connexion/déconnexion et page de
compte acheteur vide mais réelle, FR/EN. Ni panier HTML, checkout, paiement, historique,
documents, likes ou nouvelles interfaces professionnelles dans cette sous-étape.

Fichiers pressentis, à confirmer selon le contrat approuvé :

- Réutiliser/adapter `app/Models/User.php` ; examiner `config/auth.php` si la séparation
  retenue nécessite un guard distinct, sans changer implicitement les sessions existantes.
- Ajouter un contrôleur de session acheteur, ses Form Requests et un middleware de frontière
  acheteur ; compléter explicitement `routes/web.php` et les aliases de `bootstrap/app.php`.
- Ajouter vues Blade et traductions dédiées, réutilisant les composants graphiques existants.
- Ajouter `tests/Feature/BuyerAuthenticationTest.php` et compléter les non-régressions des
  sessions professionnelles/admin. Une migration/discrimination d'identité éventuelle devra
  être proposée séparément ; aucun nouveau champ n'est présupposé comme déjà disponible.
- Conserver `SessionController` professionnel, `CartService`, `CreateOrder`, les paiements
  et les règles tenant inchangés à cette première étape.

Vérifications ciblées futures : sessions/fixation, CSRF, limitation des tentatives, validation,
unicité email, comptes désactivés, absence de privilèges professionnels, refus des routes pro,
coexistence des usages selon la décision prise, textes FR/EN et non-régression login admin/pro.
Avant tout test Laravel : confirmer PHP >= 8.3, absence de cache de configuration, connexion
effective exclusivement SQLite `:memory:` et absence de surcharge DB_URL/environnement.
`phpunit.xml` déclare actuellement cette isolation, et `bootstrap/cache/config.php` est absent
à cet audit. Aucun test MySQL, même de concurrence, n'est autorisé ; SQLite ne certifie pas
les verrous MySQL. Lint, `git diff --check`, build Vite et revue navigateur au moment du code.

La DEMO persistante reste indépendante via `bootstrap/demo.php` et `tools/demo.php`.
Ne pas modifier `.env`, PHPUnit, données MySQL/SQLite, ni lancer de migration/seeder sans
autorisation. Aucun commit/push/changement de branche sans accord. Cette proposition
ne constitue pas le démarrage du développement de la phase 17.
