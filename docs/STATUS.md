# ZEMBOA — État du projet

Ce document indique l'état réel connu du projet ZEMBOA au moment de l'audit documentaire.

Il doit permettre à un développeur ou à une IA de reprendre le projet sans recommencer les fonctionnalités déjà terminées.

---

## 1. Projet

```text
Nom        : ZEMBOA
Type       : SaaS e-commerce multi-boutiques
Framework  : Laravel 13
PHP        : 8.3
Database   : MySQL
Phase      : MVP
```

Environnement de développement actuel :

```text
Windows
Laragon
VS Code
MySQL
Git / GitHub
```

Base locale :

```text
zemboa
```

Projet local :

```text
C:\laragon\www\zemboa
```

---

## 2. Git

Branche de travail connue :

```text
feat/stock
```

Elle suit :

```text
origin/feat/stock
```

Dernier commit poussé connu lors de la création de cette documentation :

```text
2dab68d feat: add stock authorization policy
```

Le développement Stock doit continuer sur cette base sauf changement volontaire de branche.

---

## 3. Tests

Dernière suite complète connue avant la documentation :

```text
171 tests passed
418 assertions
0 failures
```

Cette valeur représente le dernier état vérifié connu.

Elle ne doit pas être modifiée dans la documentation sans réexécuter réellement les tests.

---

# État des fondations

## 4. Laravel project

Statut :

```text
IMPLEMENTED
```

Le projet Laravel est initialisé et fonctionne localement.

---

## 5. Database

Statut :

```text
IMPLEMENTED — current modules
```

MySQL est utilisé pour le développement local.

Les migrations actuellement présentes couvrent notamment :

```text
stores
users

roles
permissions
role_user
permission_role

categories
products
product_variants

attributes
attribute_values
attribute_value_product_variant

product_images

stock_levels
stock_movements
stock_reservations
```

---

# Multi-tenancy

## 6. Tenant architecture

Statut :

```text
IMPLEMENTED
```

Architecture V1 :

```text
shared application
shared database
shared tables
store_id isolation
```

Composants principaux :

```text
App\Support\TenantContext
App\Http\Middleware\SetTenantContext
App\Models\Concerns\BelongsToStore
```

Le système fonctionne en mode fail-closed.

---

## 7. Tenant route binding

Statut :

```text
IMPLEMENTED
```

Le tenant est établi avant le route model binding.

Objectif :

```text
cross-tenant resource
        ->
not resolved
        ->
404
```

Des tests cross-tenant existent dans le projet.

---

# Authorization

## 8. Roles and permissions

Statut :

```text
IMPLEMENTED
```

Rôles V1 :

```text
SUPER_ADMIN
STORE_OWNER
DELIVERY_AGENT
```

Le système utilise :

```text
roles
permissions
role_user
permission_role
```

Commit initial connu :

```text
1598c5e feat: add roles and permissions system
```

---

## 9. Policies

Statut :

```text
IMPLEMENTED — current modules
```

Policies actuellement présentes :

```text
CategoryPolicy
ProductPolicy
ProductVariantPolicy
StockLevelPolicy
```

Le tenant scope et les Policies constituent des protections distinctes et complémentaires.

---

# Catalogue

## 10. Categories

Statut :

```text
IMPLEMENTED
```

Fonctionnalités principales :

```text
create
read/list
update
delete
tenant isolation
authorization
validation
```

La suppression est refusée lorsqu'une catégorie possède encore des produits.

Commit de protection connu :

```text
f628e42 fix: protect category deletion with products
```

---

## 11. Products

Statut :

```text
IMPLEMENTED — core
```

Fonctionnalités principales :

```text
create
show
update
delete
tenant isolation
authorization
validation
default variant creation
```

La suppression est protégée lorsqu'un historique Stock existe.

Commit connu :

```text
039aaa6 fix: protect product deletion with stock history
```

La réponse `show` actuelle reste une implémentation temporaire orientée développement et ne constitue pas encore une interface finale complète.

---

## 12. ProductVariants

Statut :

```text
IMPLEMENTED — core
```

Fonctionnalités principales :

```text
create
show
update
delete
SKU
price
promotion
default variant
tenant isolation
authorization
```

Les règles protègent notamment les cas de dernière variante, variante par défaut et historique Stock.

---

## 13. Attributes

Statut :

```text
IMPLEMENTED — base
```

Présents :

```text
Attribute
AttributeValue
attribute_value_product_variant
```

Le système permet d'associer des valeurs d'attribut aux variantes.

L'expérience utilisateur complète de gestion des attributs n'est pas considérée comme finalisée.

---

## 14. ProductImages

Statut :

```text
PARTIAL
```

Présents :

```text
ProductImage model/data structure
CreateProductImage action
```

Le workflow complet d'upload réel, stockage, validation, suppression et sécurité des fichiers reste à terminer.

---

# Inventory / Stock

## 15. StockLevel

Statut :

```text
IMPLEMENTED
```

Une variante possède un état de stock avec :

```text
physical_quantity
reserved_quantity
low_stock_threshold
```

Disponible :

```text
available = physical - reserved
```

Commit initial connu :

```text
924b6c6 feat: add variant stock levels
```

---

## 16. StockMovement

Statut :

```text
IMPLEMENTED — domain
```

Le système conserve l'historique des opérations physiques.

Types métier utilisés actuellement :

```text
RECEIPT
ADJUSTMENT_IN
ADJUSTMENT_OUT
SALE
RETURN
```

Commit principal connu :

```text
3384976 feat: add stock movements and reservations
```

Les protections d'immutabilité sont principalement applicatives et ne doivent pas être considérées comme une garantie absolue contre toutes les opérations de masse/SQL directes.

---

## 17. StockReservation

Statut :

```text
IMPLEMENTED — domain
```

Transitions principales :

```text
ACTIVE
   |
   +-- CONVERTED
   +-- RELEASED
   +-- EXPIRED
```

Les réservations modifient `reserved_quantity` sans retirer immédiatement la quantité physique.

---

## 18. Stock Actions

Statut :

```text
IMPLEMENTED
```

Actions actuellement présentes :

```text
CreateStockMovement
ReceiveStock
AdjustStockIn
AdjustStockOut
ReturnStock

CreateStockReservation
ReleaseStockReservation
ExpireStockReservation
ConvertStockReservation
```

Les opérations sensibles utilisent des transactions et des verrouillages lorsque nécessaire.

---

## 19. Stock authorization

Statut :

```text
IMPLEMENTED
```

Policy :

```text
StockLevelPolicy
```

Permissions :

```text
inventory.view
inventory.manage
```

Commit :

```text
2dab68d feat: add stock authorization policy
```

---

## 20. Stock HTTP

Statut :

```text
NOT IMPLEMENTED
NEXT DEVELOPMENT TASK
```

Lors du dernier audit :

```text
StockController       absent
Stock Form Requests   absent
Stock HTTP routes     absent
Stock UI              absent
```

Il ne faut pas recommencer la logique métier Stock déjà développée.

---

# Modules futurs

## 21. Customers

Statut :

```text
PLANNED
```

---

## 22. Cart

Statut :

```text
PLANNED
```

Règle déjà validée :

```text
Cart does NOT reserve stock.
```

---

## 23. Checkout

Statut :

```text
PLANNED
```

Il devra utiliser les réservations Stock.

L'idempotence du Checkout reste à concevoir.

---

## 24. Orders

Statut :

```text
PLANNED
```

Le futur historique des commandes devra influencer les règles de suppression/archivage des produits et variantes.

---

## 25. Shipping / Delivery

Statut :

```text
PLANNED
```

Règle métier validée :

```text
livreur signale le retour
commerçant vérifie
commerçant confirme le retour en stock
```

Le livreur ne collecte pas directement l'argent pour ZEMBOA dans le modèle métier prévu.

---

## 26. Payments

Statut :

```text
PLANNED
```

Règles déjà décidées :

- frais de livraison obligatoires dans le calcul concerné ;
- Mobile Money prévu ;
- possibilité future de paiement à la livraison via le moyen de paiement de la boutique ;
- acompte possible ;
- le stock physique ne doit pas être décrémenté simplement parce qu'une commande non payée existe.

L'intégration technique avec un fournisseur de paiement n'est pas encore implémentée.

---

## 27. Notifications

Statut :

```text
PLANNED
```

Architecture future privilégiée :

```text
Event
  ->
Listener
  ->
Job
  ->
External service
```

---

## 28. Analytics

Statut :

```text
PLANNED
```

---

## 29. API

Statut :

```text
PLANNED
```

Version prévue :

```text
/api/v1
```

Aucune API métier complète ne doit être déclarée implémentée actuellement.

---

## 30. Multi-theme

Statut :

```text
PLANNED
```

Le projet doit pouvoir évoluer vers plusieurs thèmes de boutique.

Cependant, aucun système complet de thèmes/settings n'a été vérifié dans les migrations auditées.

Ne pas documenter le multi-thème comme déjà fonctionnel.

---

## 31. Subscriptions

Statut :

```text
FUTURE
```

La gestion commerciale des abonnements SaaS ne fait pas partie du module actuellement développé.

---

# Automatisation externe

## 32. n8n / Botpress / services externes

Statut :

```text
OPTIONAL / FUTURE
```

Principe architectural :

```text
Laravel = source of truth
```

Un service externe peut automatiser ou réagir à des événements.

Il ne doit pas devenir l'autorité principale pour :

```text
stock
orders
payments
tenant authorization
```

---

# Interface

## 33. UI

Statut :

```text
PARTIAL / FUTURE DEVELOPMENT
```

Objectif :

```text
mobile-first
simple
clear
merchant-friendly
```

L'interface complète du SaaS n'est pas terminée.

Les écrans devront être développés progressivement après les frontières métier correspondantes.

---

# Tests et qualité

## 34. Stratégie

Chaque fonctionnalité importante doit être accompagnée de tests couvrant selon le cas :

```text
happy path
validation
authorization
cross-tenant
business invariants
database effects
historical integrity
```

Une fonctionnalité multi-tenant ne doit pas être considérée comme terminée sans protection cross-tenant pertinente.

---

## 35. Concurrence

Statut :

```text
PARTIAL
```

Les Actions Stock utilisent les mécanismes de transaction/verrouillage nécessaires.

Mais les comportements réels de concurrence MySQL doivent encore être testés avec une stratégie d'intégration adaptée.

---

# Déploiement

## 36. Production deployment

Statut :

```text
NOT DONE / NOT VERIFIED
```

Ne pas considérer ZEMBOA comme prêt pour la production uniquement parce qu'il fonctionne sous Laragon.

Avant production, il faudra notamment traiter :

```text
server
domain
HTTPS
environment variables
APP_DEBUG
database security
backups
storage
queues
scheduler
logs
cache
monitoring
deployment procedure
rollback procedure
payment/webhook security
```

---

# Maintenance

## 37. Documentation

Documents permanents actuels :

```text
README.md
PROJECT_RULES.md
DECISIONS.md
CHANGELOG.md

docs/ARCHITECTURE.md
docs/DATABASE.md
docs/INVENTORY.md
docs/SECURITY.md
docs/STATUS.md
```

Lorsqu'une décision importante change, mettre à jour la documentation concernée dans le même travail ou immédiatement après validation.

---

## 38. Git workflow

Branches prévues :

```text
main
develop
feat/*
fix/*
test/*
```

Principe :

```text
une modification logique
    ->
tests
    ->
commit clair
```

Éviter de mélanger dans un même commit :

```text
nouvelle fonctionnalité
+
refactor non lié
+
correction différente
+
documentation sans rapport
```

---

# Known issues / Technical debt

## 39. permission_role migration

Problème vérifié :

la méthode `down()` de la migration `permission_role` tente de supprimer :

```text
role_user
```

au lieu de :

```text
permission_role
```

Statut :

```text
KNOWN ISSUE
```

À corriger séparément et à tester.

---

## 40. Tenant relational integrity

Les foreign keys classiques ne garantissent pas systématiquement que deux ressources liées possèdent le même `store_id`.

Cette cohérence est actuellement renforcée par la couche applicative.

---

## 41. Stock history protection

Les protections d'immutabilité de `StockMovement` et certaines protections de `StockReservation` peuvent être contournées par des opérations de masse ou SQL directes.

Ne pas utiliser ces chemins pour les opérations métier normales.

---

## 42. Stock invariant

Les invariants Stock sont principalement garantis par les Actions métier.

Il ne faut pas introduire de modification directe des quantités qui contourne ces Actions.

---

## 43. Reservation expiration

`ExpireStockReservation` existe.

L'automatisation périodique de l'expiration n'est pas encore mise en place.

---

## 44. Idempotency

L'idempotence des futurs workflows :

```text
Checkout
Orders
Payments
webhooks
```

reste à concevoir.

---

## 45. Product history

Les futures références provenant de `OrderItem` devront être prises en compte avant de modifier les règles de suppression des produits/variantes.

---

## 46. Product images

Le workflow réel d'upload et stockage reste incomplet.

---

## 47. Multi-theme

Le multi-thème est une décision d'évolution, pas une fonctionnalité actuelle.

---

# POINT EXACT DE REPRISE

## 48. Travail suivant

Ne pas recommencer :

```text
StockLevel
StockMovement
StockReservation
Stock Actions
StockLevelPolicy
```

Ils existent déjà.

La prochaine tâche est :

```text
STOCK HTTP BOUNDARY
```

---

## 49. Architecture prévue pour Stock HTTP

Lecture :

```text
GET stock
GET stock/{stockLevel}
```

Permission :

```text
inventory.view
```

Mutations initiales :

```text
POST stock/{stockLevel}/receive
POST stock/{stockLevel}/adjust-in
POST stock/{stockLevel}/adjust-out
```

Permission :

```text
inventory.manage
```

Ne pas créer :

```text
PUT/PATCH StockLevel quantities
```

Les quantités doivent rester pilotées par les Actions métier.

---

## 50. Ne pas exposer immédiatement

Ne pas exposer comme opérations manuelles génériques :

```text
CreateStockReservation
ReleaseStockReservation
ExpireStockReservation
ConvertStockReservation
ReturnStock
```

Elles appartiendront principalement aux futurs workflows :

```text
Checkout
Orders
Scheduler
Shipping
Returns
```

---

## 51. Ordre de reprise

Après vérification finale de la documentation :

```text
1. Inspect existing Controllers
2. Inspect existing Form Requests
3. Re-read routes/web.php
4. Validate Stock HTTP route design
5. Design Stock Form Requests
6. Design thin Stock Controller
7. Write HTTP tests
8. Implement one Stock endpoint at a time
9. Run focused tests
10. Run complete test suite
11. Review tenant/security behavior
12. Commit
13. Push
14. Update documentation if status changed
```

---

# Resume instruction

## 52. Pour un futur développeur ou une future IA

Avant toute modification importante :

1. lire `PROJECT_RULES.md` ;
2. lire `docs/STATUS.md` ;
3. lire le document du module concerné ;
4. vérifier le code réel ;
5. vérifier `git status` et la branche ;
6. ne pas supposer que la documentation remplace le code ;
7. ne pas recommencer une fonctionnalité marquée comme implémentée sans preuve d'un problème ;
8. ne pas changer une décision architecturale sans justification ;
9. tester les modifications ;
10. mettre à jour la documentation lorsqu'un statut change.

Le prochain développement attendu est **la frontière HTTP du module Stock**.