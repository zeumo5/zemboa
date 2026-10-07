# Changelog — ZEMBOA

Ce fichier documente les changements importants apportés au projet ZEMBOA.

Le projet est actuellement en développement actif du MVP et ne possède pas encore de version de production officielle.

---

## Unreleased

### Documentation

- Ajout de `PROJECT_RULES.md` pour centraliser les règles permanentes du projet.
- Remplacement du README Laravel générique par la documentation ZEMBOA.
- Ajout de `DECISIONS.md` pour conserver les décisions architecturales et métier.
- Ajout de `CHANGELOG.md`.
- Création du dossier `docs/` pour la documentation technique détaillée.

### Next

- Documenter l'architecture technique détaillée.
- Documenter la base de données et le multi-tenancy.
- Documenter la sécurité et les tests.
- Documenter précisément le domaine Stock.
- Concevoir puis implémenter la frontière HTTP Stock.

---

## 2026-10-06 — Stock authorization

### Added

- Ajout de `StockLevelPolicy`.
- Autorisation de consultation basée sur `inventory.view`.
- Autorisation métier `manage` basée sur `inventory.manage`.
- Vérification de l'appartenance du `StockLevel` à la boutique de l'utilisateur.
- Tests directs de consultation et gestion du stock.
- Tests d'accès cross-tenant.
- Tests pour les utilisateurs sans permissions Inventory.

### Verification

Suite complète vérifiée :

`171 tests passed — 418 assertions`

### Commit

`2dab68d feat: add stock authorization policy`

---

## 2026-10-06 — Category deletion protection

### Changed

La suppression d'une catégorie est maintenant refusée lorsqu'elle contient encore des produits.

Les produits ne doivent jamais être supprimés automatiquement lors de la suppression d'une catégorie.

### Commit

`f628e42 fix: protect category deletion with products`

---

## 2026-10-06 — Product deletion protection

### Changed

La suppression physique d'un produit est autorisée uniquement lorsqu'elle ne détruit pas l'historique de stock.

La suppression est notamment refusée lorsque les variantes possèdent :

- des `StockMovement` ;
- des `StockReservation`.

Les variantes du produit peuvent être supprimées avec le produit uniquement lorsque ces protections historiques sont satisfaites.

### Commit

`039aaa6 fix: protect product deletion with stock history`

---

## 2026-10-06 — Stock history hardening
### Changed

Renforcement des protections liées à l'historique Stock.

Les mouvements de stock sont considérés comme historiques et immuables dans le workflow Eloquent normal.

Les réservations bénéficient également de protections contre les suppressions directes.

### Commit

`7423274 refactor: strengthen stock history integrity`

---

## 2026-10-06 — Stock reservations hardening

### Changed

Renforcement des règles de validation des mouvements et réservations de stock, notamment autour :

- des quantités ;
- du tenant ;
- de l'utilisateur créateur d'un mouvement ;
- des dates d'expiration ;
- des transitions de réservation.

### Commit

`19ef498 fix: harden stock reservations and movements`

---

## 2026-10-06 — Stock movements and reservations
### Added

Ajout du domaine historique et réservation du Stock :

- `StockMovement`
- `StockReservation`
- création de mouvements ;
- réception de stock ;
- ajustement entrant ;
- ajustement sortant ;
- retour en stock ;
- création de réservation ;
- libération ;
- expiration ;
- conversion en vente.

Les opérations sensibles utilisent des transactions et des verrouillages de lignes lorsque nécessaire.

### Commit

`3384976 feat: add stock movements and reservations`

---

## 2026-10-05 — Variant stock levels

### Added

Ajout de `StockLevel` par variante.

Quantités principales :

- `physical_quantity`
- `reserved_quantity`
- `low_stock_threshold`

La quantité disponible est dérivée :

`available = physical - reserved`

Une nouvelle variante reçoit automatiquement son état initial de stock.

### Commit

`924b6c6 feat: add variant stock levels`

---

## 2026-10-05 — Product creation refactoring

### Changed

`CreateProduct` réutilise désormais la logique de création des variantes afin d'éviter de maintenir deux implémentations différentes des mêmes règles métier.

### Commit

`c2fa7c7 refactor: reuse product variant creation rules`

---

## Catalogue — Completed before Stock phase

### Added

Mise en place progressive du catalogue :

- Categories ;
- Products ;
- ProductVariants ;
- Attributes ;
- AttributeValues ;
- association des valeurs aux variantes ;
- métadonnées ProductImage ;
- prix promotionnels ;
- calcul du prix effectif ;
- Policies et autorisations ;
- validation par Form Requests ;
- Actions métier ;
- routes HTTP Category/Product/ProductVariant ;
- protections cross-tenant.

### Product HTTP milestones

Commits connus :

- `9511db7` — Product create/show
- `7727cf7` — Product update
- `fa01045` — Product delete initial implementation

### ProductVariant milestones

Commits connus :

- `a4ea959` — ProductVariant create
- `db5380b` — ProductVariant show/update/delete
- `9947efb` — ProductVariant edge cases
- `a4f92a7` — effective pricing

---

## Multi-tenancy — Completed before Catalogue/Stock

### Added

Mise en place de l'isolation multi-boutiques avec :

- `TenantContext`;
- `SetTenantContext`;
- `BelongsToStore`;
- global scope Eloquent ;
- assignation automatique du `store_id` à la création ;
- ordre middleware permettant d'établir le tenant avant le route model binding ;
- tests cross-tenant ;
- comportement fail-closed en absence de tenant.

---

## Roles and permissions — Completed before Catalogue/Stock

### Added

Mise en place du système RBAC :

- `roles`;
- `permissions`;
- `role_user`;
- `permission_role`.

Rôles V1 :

- `SUPER_ADMIN`
- `STORE_OWNER`
- `DELIVERY_AGENT`

Permissions métier ajoutées pour :

- boutique ;
- catégories ;
- produits ;
- inventaire ;
- commandes ;
- clients ;
- livraisons ;
- paiements.

Commit connu du système initial :

`1598c5e feat: add roles and permissions system`

---

# Known pending work

Les éléments suivants restent notamment à réaliser ou renforcer :

- frontière HTTP Stock ;
- interface utilisateur Stock ;
- Orders et OrderItems ;
- Checkout ;
- Customers ;
- Shipping ;
- Payments ;
- Notifications ;
- Analytics ;
- API versionnée ;
- multi-thème ;
- settings avancés des boutiques ;
- upload réel des images ;
- expiration automatique planifiée des réservations ;
- idempotence Checkout/Orders ;
- tests MySQL de concurrence ;
- stratégie d'archivage lorsque l'historique Orders existera ;
- déploiement production.

---

# Known issue discovered during documentation audit

La migration `create_permission_role_table` contient actuellement une erreur dans sa méthode `down()` :

elle tente de supprimer `role_user` au lieu de `permission_role`.

Cette anomalie doit être corrigée dans une tâche dédiée et testée, et non silencieusement au milieu d'une fonctionnalité sans rapport.