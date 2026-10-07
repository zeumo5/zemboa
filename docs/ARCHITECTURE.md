# ZEMBOA — Architecture technique

Ce document décrit l'architecture technique actuelle de ZEMBOA et les directions déjà validées pour son évolution.

Document de référence initial :

- phase : MVP ;
- branche : `feat/stock` ;
- commit poussé de référence : `2dab68d`.

Les règles obligatoires du projet restent définies dans `PROJECT_RULES.md`.

---

## 1. Vue générale

ZEMBOA est conçu comme un SaaS e-commerce multi-boutiques.

Architecture V1 :

```text
                    ZEMBOA
                       |
                Laravel Application
                       |
        +--------------+--------------+
        |              |              |
     Boutique A     Boutique B     Boutique C
        |              |              |
        +--------------+--------------+
                       |
               Shared MySQL DB
                       |
             Isolation by store_id

             HTTP Request
 
 Une seule application Laravel dessert plusieurs boutiques.
Les données sont stockées dans une base partagée et les données tenant-aware sont isolées principalement avec store_id.
2. Architecture applicative
Le flux privilégié pour une opération HTTP est :
HTTP Request
     |
     v
Middleware
     |
     v
Form Request
     |
     v
Controller
     |
     v
Action / Service
     |
     v
Eloquent Models
     |
     v
MySQL

Responsabilités
Middleware
Responsable notamment :
- de l'authentification lorsque requise ;
- de l'établissement du contexte tenant ;
- des préoccupations HTTP transversales.
Form Request
Responsable de la validation des données HTTP.
Une validation HTTP ne remplace pas les invariants métier dans les Actions.
Controller
Le Controller doit rester mince.
Il :
1. reçoit une requête déjà validée ;
2. autorise l'opération ;
3. appelle l'Action métier appropriée ;
4. construit la réponse HTTP.
Il ne doit pas devenir le lieu principal des règles métier.
Action
Une Action représente une opération métier.
Exemples existants :
CreateCategory
DeleteCategory
CreateProduct
DeleteProduct
CreateProductVariant
ReceiveStock
AdjustStockIn
AdjustStockOut
CreateStockReservation
ConvertStockReservation
ReturnStock

Les Actions permettent de réutiliser les règles métier depuis différentes frontières futures :
- interface web ;
- API ;
- Jobs ;
- commandes internes ;
- workflows système.
Model
Les Models représentent les entités et relations Eloquent.
Ils peuvent également porter certains comportements locaux, scopes et protections.
Ils ne doivent pas devenir des contrôleurs ou services géants.
3. Organisation actuelle du domaine applicatif
Les principaux répertoires actuellement présents sont :
app/
├── Actions/
│   ├── AttributeValue/
│   ├── Category/
│   ├── Product/
│   ├── ProductImage/
│   ├── ProductVariant/
│   └── Stock/
│
├── Http/
│   ├── Controllers/
│   ├── Middleware/
│   └── Requests/
│
├── Models/
│   └── Concerns/
│
├── Policies/
│
├── Providers/
│
└── Support/

Cette structure est volontairement simple.
Une architecture plus complexe ne doit être introduite que lorsqu'un besoin réel la justifie.
4. Multi-tenancy
Le multi-tenancy est une partie critique de ZEMBOA.
Les principaux composants actuels sont :
App\Support\TenantContext
App\Http\Middleware\SetTenantContext
App\Models\Concerns\BelongsToStore

Flux tenant
Pour une requête métier tenant-aware :
Authenticated User
       |
       v
SetTenantContext
       |
       v
TenantContext
       |
       v
Route Model Binding
       |
       v
BelongsToStore global scope
       |
       v
Tenant-only records

Le contexte tenant doit être établi avant la résolution des modèles de route.
5. TenantContext
TenantContext représente la boutique active pendant l'exécution d'une requête tenant-aware.
Il fournit notamment l'identifiant de la boutique active aux composants qui en ont besoin.
Le comportement attendu est fail-closed :
Tenant présent
    -> opération tenant possible

Tenant absent/invalide
    -> échec

Tenant absent
    -> JAMAIS "retourner toutes les boutiques"

Ce principe évite qu'une erreur de configuration supprime accidentellement l'isolation.
6. BelongsToStore
Les modèles tenant-aware peuvent utiliser le trait :
BelongsToStore

Ce mécanisme :
- ajoute un global scope basé sur store_id ;
- affecte automatiquement le store_id actif lors de la création ;
- fournit la relation vers Store.
Conséquence :
une requête Eloquent normale sur un modèle tenant-aware ne doit voir que les données de la boutique active.
7. Route model binding et isolation
L'ordre des middlewares est important.
Le tenant doit être établi avant SubstituteBindings.
Cela permet au global scope tenant de participer au route model binding.
Exemple conceptuel :
/products/123

Si le produit 123 appartient à une autre boutique :
TenantContext
      |
      v
Product tenant scope
      |
      v
Product 123 invisible
      |
      v
404

Cela réduit également la divulgation de l'existence des ressources d'autres boutiques.
8. Autorisation
L'autorisation utilise les Policies et le système rôles/permissions.
Architecture de défense :
Authentication
      |
      v
Tenant isolation
      |
      v
Permission
      |
      v
Policy / ownership check
      |
      v
Business Action

Les permissions et le tenant ont des responsabilités différentes.
Exemple :
products.update

signifie que l'utilisateur possède la capacité fonctionnelle de modifier des produits.
Cela ne signifie pas qu'il peut modifier les produits d'une autre boutique.
9. RBAC
Le système actuel repose sur :
User
  |
role_user
  |
Role
  |
permission_role
  |
Permission

Rôles V1 :
SUPER_ADMIN
STORE_OWNER
DELIVERY_AGENT

Les permissions métier comprennent actuellement des capacités liées notamment à :
- Store ;
- Categories ;
- Products ;
- Inventory ;
- Orders ;
- Customers ;
- Deliveries ;
- Payments.
Certaines permissions concernent des modules qui ne sont pas encore complètement implémentés.
Une permission existante ne signifie donc pas automatiquement que le module correspondant est terminé.
10. Catalogue
Architecture actuelle :
Store
  |
  +-- Category
  |      |
  |      +-- Product
  |              |
  |              +-- ProductVariant
  |              |
  |              +-- ProductImage
  |
  +-- Attribute
         |
         +-- AttributeValue
                 |
                 +-- ProductVariant
                     via pivot

Product
Représente le produit commercial général.
ProductVariant
Porte les informations qui peuvent varier :
- SKU ;
- prix ;
- promotion ;
- statut ;
- variante par défaut.
Même un produit simple utilise une variante par défaut.
Cette décision évite deux systèmes de stock/prix différents.
11. Stock
Architecture actuelle :
ProductVariant
      |
      +------ StockLevel
      |
      +------ StockMovement
      |
      +------ StockReservation

StockLevel
État courant.
physical_quantity
reserved_quantity
low_stock_threshold

StockMovement
Historique des changements physiques.
StockReservation
État des quantités temporairement réservées.
Les règles détaillées sont documentées dans :
docs/INVENTORY.md

lorsque ce document est présent.
12. Transactions
Les opérations métier impliquant plusieurs changements cohérents doivent utiliser une transaction.
Exemple de conversion d'une réservation :
BEGIN TRANSACTION

lock StockReservation
lock StockLevel

validate

physical -= quantity
reserved -= quantity

create SALE StockMovement

reservation -> CONVERTED

COMMIT

Une erreur doit provoquer un rollback de l'opération complète.
13. Concurrence
Les opérations Stock sensibles utilisent lockForUpdate() lorsque nécessaire.
Objectif :
éviter que deux opérations concurrentes prennent une décision à partir du même état de stock sans synchronisation.
Cette protection applicative devra être complétée ultérieurement par des tests de concurrence réalistes sous MySQL.
14. Événements et traitements asynchrones
Pour les réactions secondaires futures, l'architecture privilégiée est :
Business Action
      |
      v
Event
      |
      v
Listener
      |
      v
Job
      |
      v
External service

Exemples futurs possibles :
- notifications ;
- e-mails ;
- synchronisation ;
- analytics ;
- intégrations externes.
Un service externe ne doit pas devenir la source de vérité du stock, des commandes ou des paiements.
15. Frontières prévues
ZEMBOA pourra être utilisé par plusieurs frontières :
               +----------------+
               |   Web / Blade  |
               +-------+--------+
                       |
               +-------v--------+
               | Business Core  |
               +-------+--------+
                       |
       +---------------+----------------+
       |               |                |
   Future API       Future Jobs    Future Integrations

Toutes doivent réutiliser les mêmes règles métier centrales.
16. API
Une API versionnée est prévue :
/api/v1/...

Statut :
PLANNED
Elle n'est pas encore considérée comme implémentée.
17. Interface utilisateur
Tailwind CSS est le choix prévu pour l'interface.
Objectif UX :
- mobile-first ;
- interfaces simples ;
- opérations métier explicites ;
- éviter d'exposer des contrôles techniques dangereux aux commerçants.
L'interface complète du SaaS reste à construire progressivement.
18. Multi-thème
Le support de plusieurs thèmes par boutique fait partie de l'évolution prévue.
Statut :
PLANNED
Il n'existe actuellement aucune preuve dans les migrations auditées d'un système complet de thèmes/settings.
La personnalisation devra être séparée autant que possible du cœur de Store.
19. Modules
Implémentés ou déjà présents
Stores              IMPLEMENTED — base
Users               IMPLEMENTED — base
Roles/Permissions   IMPLEMENTED
Multi-tenancy       IMPLEMENTED
Categories          IMPLEMENTED
Products            IMPLEMENTED
ProductVariants     IMPLEMENTED
Attributes          IMPLEMENTED — base
ProductImages       PARTIAL
Inventory/Stock     IMPLEMENTED — business layer

En cours
Stock HTTP boundary IN PROGRESS / NEXT

Planifiés
Customers
Cart
Checkout
Orders
Shipping
Payments
Notifications
Analytics
API
Store settings
Multi-theme
Subscriptions (future)

Ces statuts doivent évoluer avec le code réel.
20. Routes HTTP actuellement vérifiées
Les routes métier présentes dans routes/web.php couvrent actuellement :
Categories
Products
ProductVariants

Elles sont regroupées derrière :
auth
tenant

Aucune route HTTP Stock n'était présente lors de l'audit initial de cette documentation.
21. Architecture HTTP Stock prévue
Prochaine frontière :
HTTP
 |
 +-- GET stock list
 |
 +-- GET stock detail
 |
 +-- POST receive
 |
 +-- POST adjust-in
 |
 +-- POST adjust-out

Lecture :
inventory.view

Mutation :
inventory.manage

Il ne doit pas exister de modification CRUD générique permettant :
physical_quantity = arbitrary value
reserved_quantity = arbitrary value

Les modifications doivent passer par les Actions métier existantes.
ReturnStock sera intégré au workflow de retour/livraison plutôt qu'exposé immédiatement comme simple bouton d'ajustement.
Les réservations/conversions seront intégrées au workflow Orders/Checkout.
22. Sécurité architecturale
La sécurité repose sur plusieurs couches :
HTTP protections
      +
Authentication
      +
Tenant isolation
      +
RBAC
      +
Policies
      +
Validation
      +
Business invariants
      +
Database constraints
      +
Tests

Aucune couche seule ne doit être considérée comme suffisante.
23. Tests
Les tests font partie de l'architecture du projet.
Ils doivent notamment empêcher les régressions concernant :
- tenant isolation ;
- autorisation ;
- validation ;
- règles métier ;
- historique ;
- stock ;
- suppressions protégées.
Dernière suite complète connue lors de la création de cette documentation :
171 tests passed
418 assertions
0 failures

24. Déploiement
Le projet n'est pas documenté comme étant actuellement déployé en production.
Avant une production réelle, il faudra notamment finaliser :
- configuration environnement ;
- HTTPS ;
- gestion des secrets ;
- logs ;
- sauvegardes ;
- queue workers si utilisés ;
- scheduler si utilisé ;
- cache ;
- stratégie de migration ;
- supervision ;
- sécurité des paiements/webhooks ;
- stockage des fichiers ;
- procédure de rollback.
25. Principe d'évolution
Lorsqu'un nouveau module est ajouté :
1. Rules
2. Data model
3. Tenant boundaries
4. Authorization
5. Actions
6. HTTP boundary
7. Tests
8. Documentation

Une nouvelle fonctionnalité ne doit pas contourner les protections existantes uniquement pour accélérer son développement.
26. Point exact de reprise
Après finalisation de la documentation, le développement doit reprendre ici :
Module: Stock
Layer: HTTP boundary
Status: NOT IMPLEMENTED

Avant de coder :
1. inspecter les conventions des Controllers existants ;
2. inspecter les Form Requests existantes ;
3. inspecter routes/web.php ;
4. valider les routes Stock ;
5. définir les Form Requests ;
6. définir le Controller ;
7. écrire les tests HTTP ;
8. implémenter progressivement.
Ne pas recommencer la logique métier Stock déjà implémentée.