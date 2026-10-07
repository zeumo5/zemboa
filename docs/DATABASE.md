# ZEMBOA — Base de données

Ce document décrit la structure de données actuellement vérifiée de ZEMBOA, ainsi que les principales règles d'intégrité associées.

Base actuelle :

- SGBD : MySQL
- base locale : `zemboa`
- architecture : base partagée multi-boutiques
- isolation principale : `store_id`

Les règles métier générales restent dans `PROJECT_RULES.md`.

---

## 1. Principe multi-boutiques

ZEMBOA utilise actuellement une base de données partagée.

```text
MySQL — zemboa
│
├── Store A data
├── Store B data
└── Store C data
```

Les tables métier concernées utilisent `store_id` pour identifier leur boutique.

Exemples :

```text
categories.store_id
products.store_id
product_variants.store_id
stock_levels.store_id
stock_movements.store_id
stock_reservations.store_id
```

L'isolation ne repose cependant pas uniquement sur la base de données.

Elle est complétée dans Laravel par :

- `TenantContext`
- `SetTenantContext`
- `BelongsToStore`
- global scopes
- Policies
- tests cross-tenant

---

## 2. Stores

Table :

```text
stores
```

Principaux champs vérifiés :

```text
id
name
slug
description
slogan
phone
whatsapp
email
address
status
created_at
updated_at
```

`slug` est unique.

`status` possède actuellement la valeur par défaut :

```text
ACTIVE
```

`Store` représente la boutique/tenant principal de ZEMBOA.

---

## 3. Users

Table :

```text
users
```

Principaux champs métier vérifiés :

```text
id
store_id
name
email
phone
email_verified_at
password
status
last_login_at
remember_token
created_at
updated_at
```

`email` est unique.

`store_id` est nullable afin de permettre notamment un utilisateur plateforme comme `SUPER_ADMIN`.

Pour un utilisateur appartenant à une boutique, `store_id` référence :

```text
stores.id
```

La contrainte actuelle utilise une suppression restrictive.

Une boutique ne doit donc pas être supprimée automatiquement avec ses utilisateurs.

---

## 4. Roles et Permissions

Tables :

```text
roles
permissions
role_user
permission_role
```

Relations conceptuelles :

```text
User
  |
  +--- role_user --- Role
                      |
                      +--- permission_role --- Permission
```

Cela sépare :

- l'identité de l'utilisateur ;
- son rôle ;
- ses capacités fonctionnelles.

Le RBAC ne remplace pas l'isolation tenant.

---

## 5. Known migration issue — permission_role

Une anomalie a été détectée pendant l'audit documentaire.

Dans la migration de `permission_role`, la méthode `down()` tente actuellement de supprimer :

```text
role_user
```

alors qu'elle devrait supprimer :

```text
permission_role
```

Statut :

```text
KNOWN ISSUE
```

Cette anomalie devra être corrigée et testée dans une modification dédiée.

---

## 6. Categories

Table :

```text
categories
```

Structure métier principale :

```text
id
store_id
name
slug
description
status
created_at
updated_at
```

Contraintes importantes :

```text
UNIQUE(store_id, slug)
```

Index vérifié :

```text
INDEX(store_id, status)
```

Conséquence :

deux boutiques peuvent utiliser le même slug de catégorie, mais une même boutique ne peut pas avoir deux catégories avec le même slug.

Relation :

```text
Store
  |
  +-- Categories
```

Une catégorie ne peut pas être supprimée lorsqu'elle possède encore des produits.

---

## 7. Products

Table :

```text
products
```

Structure principale :

```text
id
store_id
category_id
name
slug
description
status
is_featured
created_at
updated_at
```

Contraintes importantes :

```text
UNIQUE(store_id, slug)
```

Relations :

```text
Store
  |
  +-- Product

Category
  |
  +-- Product
```

`store_id` et `category_id` utilisent des contraintes restrictives dans la migration actuelle.

Le produit est l'entité commerciale générale.

Les prix et le stock sont portés par ses variantes.

---

## 8. Product Variants

Table :

```text
product_variants
```

Principaux champs :

```text
id
store_id
product_id
sku
price
promo_price
promo_starts_at
promo_ends_at
is_default
status
created_at
updated_at
```

Contraintes :

```text
UNIQUE(store_id, sku)
```

Le prix utilise :

```text
DECIMAL(12,2)
```

et non un type flottant.

Relations :

```text
Product
   |
   +-- ProductVariant
```

Chaque produit doit conserver une variante utilisable.

Un produit simple utilise également une variante par défaut.

---

## 9. Attributes

Tables :

```text
attributes
attribute_values
attribute_value_product_variant
```

Structure conceptuelle :

```text
Attribute
   |
   +-- AttributeValue
            |
            +-- ProductVariant
                 via pivot
```

Exemple conceptuel futur :

```text
Attribute: Color
    |
    +-- Red
    +-- Blue

Attribute: Size
    |
    +-- S
    +-- M
    +-- L
```

Les attributs permettent de décrire les caractéristiques des variantes sans ajouter une colonne SQL pour chaque caractéristique possible.

---

## 10. Product Images

Table :

```text
product_images
```

Elle permet d'associer des métadonnées d'image aux produits.

Le domaine image est actuellement :

```text
PARTIAL
```

La présence de la table et du modèle ne signifie pas que tout le workflow réel d'upload, stockage, validation et suppression de fichiers est terminé.

---

# 11. Stock

Le domaine Stock utilise trois concepts distincts :

```text
StockLevel
StockMovement
StockReservation
```

Ils ne doivent pas être fusionnés.

---

## 12. Stock Levels

Table :

```text
stock_levels
```

Principaux champs :

```text
id
store_id
product_variant_id
physical_quantity
reserved_quantity
low_stock_threshold
created_at
updated_at
```

Valeurs par défaut vérifiées :

```text
physical_quantity = 0
reserved_quantity = 0
low_stock_threshold = 5
```

Contrainte importante :

```text
UNIQUE(product_variant_id)
```

Une variante possède donc un seul état courant de stock.

Relation :

```text
ProductVariant
      |
      +-- StockLevel
```

La suppression d'une variante entraîne actuellement la suppression de son `StockLevel`.

Le `StockLevel` représente l'état courant, pas l'historique.

---

## 13. Quantités de stock

Les concepts sont :

```text
physical = quantité physiquement présente

reserved = quantité déjà réservée

available = physical - reserved
```

`available` est une valeur dérivée.

Elle ne doit pas devenir une quantité indépendante pouvant diverger des deux autres.

Exemple :

```text
physical = 10
reserved = 3

available = 7
```

---

## 14. Stock Movements

Table :

```text
stock_movements
```

Principaux champs :

```text
id
store_id
product_variant_id
type
quantity
reference_type
reference_id
reason
created_by
created_at
```

La quantité est un entier positif non signé.

Le sens du mouvement est représenté par son `type`, pas par une quantité négative.

Exemples de types utilisés par le domaine actuel :

```text
RECEIPT
ADJUSTMENT_IN
ADJUSTMENT_OUT
SALE
RETURN
```

`created_by` peut référencer un utilisateur et utilise actuellement un comportement `nullOnDelete`.

Le mouvement représente l'historique d'une opération physique.

Il ne doit pas être utilisé comme simple état courant.

---

## 15. Immutabilité des mouvements

Un mouvement historique existant ne doit normalement pas être modifié ou supprimé.

Une correction métier doit être représentée par une nouvelle opération compensatoire plutôt que par la réécriture silencieuse de l'historique.

Protection actuelle :

```text
PARTIAL / APPLICATION LEVEL
```

Les protections Eloquent existent, mais une suppression/modification de masse peut contourner certains événements de modèle.

Cette limite doit rester connue.

---

## 16. Stock Reservations

Table :

```text
stock_reservations
```

Principaux champs :

```text
id
store_id
product_variant_id
quantity
status
reference_type
reference_id
expires_at
converted_at
released_at
created_at
updated_at
```

Statut par défaut :

```text
ACTIVE
```

Les réservations permettent de protéger temporairement une quantité disponible.

Relations :

```text
ProductVariant
      |
      +-- StockReservation
```

---

## 17. Cycle d'une réservation

Cycle conceptuel :

```text
              +--> CONVERTED
              |
ACTIVE -------+
              |
              +--> RELEASED
              |
              +--> EXPIRED
```

Une réservation active augmente :

```text
reserved_quantity
```

sans réduire immédiatement :

```text
physical_quantity
```

Lors de sa conversion en vente :

```text
physical_quantity -= quantity
reserved_quantity -= quantity
```

et un mouvement `SALE` est enregistré.

---

## 18. Cart vs Checkout

Décision métier :

```text
Cart
  -> ne réserve pas le stock

Checkout
  -> pourra créer les réservations
```

Ajouter un produit au panier ne garantit donc pas sa disponibilité future.

Cette décision évite de bloquer du stock simplement parce qu'un utilisateur conserve longtemps un panier abandonné.

---

## 19. Réception

Une réception de stock suit conceptuellement :

```text
StockLevel.physical_quantity += quantity

+

StockMovement(type = RECEIPT)
```

L'opération doit passer par l'Action métier correspondante.

---

## 20. Ajustement entrant

Un ajustement entrant :

```text
physical_quantity += quantity

+

StockMovement(type = ADJUSTMENT_IN)
```

Il sert à représenter une correction positive explicite.

---

## 21. Ajustement sortant

Un ajustement sortant :

```text
physical_quantity -= quantity

+

StockMovement(type = ADJUSTMENT_OUT)
```

L'opération doit vérifier que les invariants de stock restent valides.

---

## 22. Retour

Un retour confirmé peut produire :

```text
physical_quantity += quantity

+

StockMovement(type = RETURN)
```

Cependant, dans l'architecture métier prévue, un retour de livraison ne doit pas être remis automatiquement en stock uniquement parce que le livreur déclare un retour.

La vérification finale appartient au commerçant.

Le workflow complet dépendra des futurs modules Orders/Shipping.

---

## 23. Transactions et verrouillage

Les opérations sensibles de Stock utilisent des transactions et, lorsque nécessaire :

```text
lockForUpdate()
```

Objectif :

```text
Read locked state
      |
Validate
      |
Modify StockLevel
      |
Create history/reservation
      |
Commit
```

Une erreur doit provoquer le rollback de l'ensemble.

---

## 24. Suppressions et historique

Principe général :

```text
Current state may change.
Historical truth should not disappear silently.
```

Les suppressions de produits/variantes sont donc protégées lorsqu'un historique Stock existe.

Avec les futurs `OrderItem`, ces protections devront être réévaluées et probablement renforcées avec une stratégie d'archivage.

---

## 25. Intégrité tenant en base

La base possède des foreign keys et des `store_id`, mais elle ne garantit pas actuellement dans tous les cas qu'une relation entre deux lignes utilise obligatoirement le même tenant.

Exemple conceptuel à éviter :

```text
Product.store_id = 1

Category.store_id = 2

Product.category_id = Category.id
```

Une foreign key classique sur `category_id` garantit l'existence de la catégorie, mais pas nécessairement l'égalité des `store_id`.

ZEMBOA complète donc cette limite par :

- TenantContext ;
- scopes ;
- Actions ;
- Policies ;
- validation métier ;
- tests.

Toute nouvelle relation tenant-aware doit tenir compte de cette limite.

---

## 26. Contraintes vs règles métier

Les contraintes SQL doivent protéger ce qui est simple et stable.

Exemples :

```text
foreign keys
unique indexes
not null
unsigned quantities
```

Les règles complexes restent dans le domaine Laravel.

Exemple :

```text
available = physical - reserved
```

ou :

```text
ne pas convertir une réservation déjà RELEASED
```

La base et l'application travaillent donc ensemble.

---

## 27. Tables Laravel techniques

Le projet contient également des tables techniques Laravel générées pour certains services du framework, notamment les mécanismes de cache/jobs selon les migrations présentes.

Elles ne représentent pas directement le domaine métier ZEMBOA.

---

## 28. Modules de données non encore implémentés

Lors de l'audit actuel, les domaines suivants ne doivent pas être considérés comme terminés :

```text
Customers
Cart
Orders
OrderItems
Shipping
Payments
Notifications
Analytics
Subscriptions
Store theme/settings system
```

Leurs modèles de données devront être conçus avant création des migrations correspondantes.

---

## 29. Évolution des migrations

Avant d'ajouter une migration :

1. définir la règle métier ;
2. identifier le tenant propriétaire ;
3. définir les relations ;
4. choisir les contraintes SQL ;
5. définir les index utiles ;
6. réfléchir à l'historique ;
7. réfléchir aux suppressions ;
8. écrire les tests correspondants.

Une migration ne doit pas être créée uniquement parce qu'une interface a besoin rapidement d'un nouveau champ.

---

## 30. État actuel

```text
Stores                  IMPLEMENTED
Users                   IMPLEMENTED
RBAC                    IMPLEMENTED
Categories              IMPLEMENTED
Products                IMPLEMENTED
ProductVariants         IMPLEMENTED
Attributes              IMPLEMENTED — base
ProductImages           PARTIAL
StockLevels             IMPLEMENTED
StockMovements          IMPLEMENTED
StockReservations       IMPLEMENTED

Orders                   PLANNED
Customers                PLANNED
Shipping                 PLANNED
Payments                 PLANNED
Multi-theme/settings     PLANNED
```

La prochaine évolution de ZEMBOA ne nécessite pas de nouvelle table Stock pour le moment.

La prochaine étape est d'exposer proprement les opérations Stock existantes à travers une frontière HTTP sécurisée, sans permettre de modifier directement les quantités.