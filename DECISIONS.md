# ZEMBOA — Architecture Decisions

Ce document conserve les décisions techniques et métier importantes prises pendant le développement de ZEMBOA.

Il ne décrit pas uniquement ce qui existe : il explique pourquoi certaines solutions ont été retenues et quelles alternatives ont été volontairement écartées ou reportées.

Statut de référence initial :

- branche : `feat/stock`
- commit poussé de référence : `2dab68d`
- phase : MVP
- prochaine frontière de développement : HTTP Stock

---

## ADR-001 — Architecture SaaS multi-boutiques

**Statut : ACCEPTED**

### Décision

ZEMBOA utilise une seule application Laravel et une base de données partagée pour plusieurs boutiques.

Les données métier appartenant à une boutique utilisent `store_id`.

### Pourquoi

Cette architecture est adaptée au MVP car elle :

- limite la complexité opérationnelle ;
- évite une base de données séparée par boutique ;
- facilite les migrations ;
- permet de faire évoluer progressivement la plateforme.

### Protection

L'isolation ne repose pas uniquement sur `store_id`.

Elle utilise plusieurs couches :

- `TenantContext`
- middleware `SetTenantContext`
- trait `BelongsToStore`
- global scope Eloquent
- Policies
- permissions
- validations métier
- contraintes/indexes SQL
- tests cross-tenant

### Limite connue

Les clés étrangères SQL actuelles ne garantissent pas systématiquement que deux enregistrements liés possèdent le même `store_id`.

Cette garantie dépend également de la couche applicative.

---

## ADR-002 — TenantContext fail-closed

**Statut : ACCEPTED / IMPLEMENTED**

### Décision

Une opération tenant-aware ne doit pas continuer silencieusement lorsqu'aucune boutique active n'est disponible dans `TenantContext`.

### Pourquoi

Retourner toutes les données lorsqu'un contexte tenant manque serait une faille critique.

Le système doit donc échouer plutôt que supprimer le filtre tenant.

---

## ADR-003 — Tenant avant route model binding

**Statut : ACCEPTED / IMPLEMENTED**

### Décision

`SetTenantContext` doit être exécuté avant `SubstituteBindings`.

### Pourquoi

Les modèles utilisant le global scope tenant doivent être résolus seulement après établissement du contexte de boutique.

### Conséquence

Une ressource appartenant à une autre boutique doit devenir invisible lors du route model binding et produire normalement une réponse 404 plutôt que révéler son existence.

---

## ADR-004 — RBAC séparé du tenant

**Statut : ACCEPTED / IMPLEMENTED**

### Décision

Les rôles et permissions déterminent ce qu'un utilisateur peut faire.

Le tenant détermine sur quelles données il peut agir.

### Pourquoi

Avoir `products.update` ne doit jamais permettre de modifier le produit d'une autre boutique.

Les deux protections sont donc complémentaires.

### Rôles V1

- `SUPER_ADMIN`
- `STORE_OWNER`
- `DELIVERY_AGENT`

---

## ADR-005 — Logique métier dans des Actions

**Statut : ACCEPTED / IMPLEMENTED**

### Décision

Le flux privilégié est :

`Request -> Controller -> Action -> Model/Database`

Les contrôleurs restent minces.

### Pourquoi

Cela :

- centralise les règles métier ;
- facilite les tests ;
- évite de dupliquer la logique entre plusieurs interfaces ;
- prépare l'utilisation future des mêmes règles depuis une API, des Jobs ou d'autres workflows.

---

## ADR-006 — Produit et variante séparés

**Statut : ACCEPTED / IMPLEMENTED**

### Décision

`Product` représente le produit commercial.

`ProductVariant` porte notamment :

- SKU ;
- prix ;
- promotion ;
- statut de variante ;
- caractère par défaut.

Même un produit simple possède une variante par défaut.

### Pourquoi

Cela évite d'avoir deux architectures différentes entre les produits simples et les produits avec tailles, couleurs ou autres attributs.

---

## ADR-007 — Prix en DECIMAL

**Statut : ACCEPTED / IMPLEMENTED**

### Décision

Les montants monétaires utilisent des colonnes SQL décimales.

La variante utilise actuellement :

`DECIMAL(12,2)`

### Pourquoi

Les nombres flottants peuvent produire des erreurs d'arrondi incompatibles avec les calculs financiers.

---

## ADR-008 — Une variante possède un état de stock

**Statut : ACCEPTED / IMPLEMENTED**

### Décision

Dans la V1 :

`1 boutique = 1 stock logique`

Chaque `ProductVariant` possède au maximum un `StockLevel`.

### Pourquoi

La gestion de plusieurs entrepôts ou emplacements augmenterait fortement la complexité du MVP.

L'architecture pourra évoluer ultérieurement si plusieurs emplacements deviennent nécessaires.

---

## ADR-009 — Stock physique et stock réservé séparés

**Statut : ACCEPTED / IMPLEMENTED**

### Décision

`StockLevel` conserve :

- `physical_quantity`
- `reserved_quantity`

La quantité disponible est calculée :

`available = physical - reserved`

### Pourquoi

Stocker également `available_quantity` créerait trois valeurs devant rester synchronisées.

La valeur disponible est donc dérivée.

### Invariant

`reserved_quantity <= physical_quantity`

---

## ADR-010 — Le panier ne réserve pas le stock

**Statut : ACCEPTED**

### Décision

L'ajout au panier ne crée pas de réservation.

La réservation intervient au checkout.

### Pourquoi

Un panier peut être abandonné pendant longtemps.

Réserver dès l'ajout au panier pourrait bloquer artificiellement le stock pour d'autres clients.

---

## ADR-011 — Historique physique via StockMovement

**Statut : ACCEPTED / IMPLEMENTED**

### Décision

Toute modification physique du stock doit être accompagnée d'un `StockMovement` dans la même transaction métier.

Types actuels :

- `RECEIPT`
- `SALE`
- `RETURN`
- `ADJUSTMENT_IN`
- `ADJUSTMENT_OUT`

### Pourquoi

`StockLevel` représente l'état courant.

`StockMovement` représente l'historique expliquant comment cet état a évolué.

---

## ADR-012 — StockMovement est historique et immuable

**Statut : ACCEPTED / PARTIAL**

### Décision

Un mouvement existant ne doit pas être modifié pour corriger une erreur.

Une correction doit produire un mouvement compensatoire.

### Implémentation actuelle

Le modèle bloque les modifications/suppressions effectuées via les événements d'instance Eloquent.

### Limite

Une opération directe de masse via Query Builder peut contourner les événements du modèle.

L'immutabilité n'est donc pas encore une garantie SQL absolue.

---

## ADR-013 — Réservation de stock explicite

**Statut : ACCEPTED / IMPLEMENTED**

### États

- `ACTIVE`
- `CONVERTED`
- `RELEASED`
- `EXPIRED`

### Création

Une réservation augmente `reserved_quantity`.

Elle ne diminue pas immédiatement `physical_quantity`.

### Conversion

La conversion :

1. diminue `physical_quantity` ;
2. diminue `reserved_quantity` ;
3. crée un mouvement `SALE` ;
4. marque la réservation `CONVERTED`.

### Libération / expiration

La quantité réservée est libérée sans modifier le stock physique.

---

## ADR-014 — Transactions et verrouillage du stock

**Statut : ACCEPTED / IMPLEMENTED**

### Décision

Les opérations sensibles de stock utilisent des transactions et `lockForUpdate()` lorsque nécessaire.

### Pourquoi

Deux opérations concurrentes ne doivent pas pouvoir consommer la même quantité disponible sans contrôle.

### Limite connue

Les tests courants ne constituent pas encore une validation complète du comportement concurrent réel sous MySQL.

Des tests d'intégration/concurrence spécifiques pourront être ajoutés ultérieurement.

---

## ADR-015 — Pas de CRUD direct des quantités StockLevel

**Statut : ACCEPTED**

### Décision

La future frontière HTTP Stock ne doit pas exposer un endpoint générique du type :

`PATCH /stock-levels/{id}`

permettant de modifier directement `physical_quantity` ou `reserved_quantity`.

### Pourquoi

Une modification directe contournerait :

- les mouvements historiques ;
- les règles métier ;
- les réservations ;
- les invariants ;
- les transactions dédiées.

### Approche

Utiliser des opérations métier explicites telles que :

- ReceiveStock
- AdjustStockIn
- AdjustStockOut

---

## ADR-016 — Retour physique validé par le commerçant

**Statut : ACCEPTED**

### Décision

Un colis refusé ou retourné ne doit pas automatiquement réintégrer le stock vendable.

Le commerçant doit vérifier le produit avant sa remise en stock.

### Pourquoi

Un article retourné peut être :

- endommagé ;
- incomplet ;
- inutilisable ;
- impropre à une nouvelle vente.

Après validation, `ReturnStock` peut produire le mouvement `RETURN`.

---

## ADR-017 — Historique protégé lors des suppressions

**Statut : ACCEPTED / IMPLEMENTED**

### Catégorie

Une catégorie contenant des produits ne peut pas être supprimée.

### Produit

Un produit possédant des mouvements ou réservations de stock ne peut pas être supprimé physiquement.

### Variante

Une variante possédant un historique de stock ou une réservation ne peut pas être supprimée.

La variante par défaut et la dernière variante d'un produit disposent également de protections métier.

### Évolution

Lorsque Orders/OrderItems existeront, ces références historiques devront également participer aux règles de suppression ou conduire à une stratégie d'archivage.

---

## ADR-018 — StockLevelPolicy utilise des opérations métier

**Statut : ACCEPTED / IMPLEMENTED**

### Permissions

Consultation :

`inventory.view`

Gestion :

`inventory.manage`

### Décision

`StockLevelPolicy` possède une capacité métier `manage`.

Les opérations CRUD génériques de modification/suppression de `StockLevel` ne doivent pas servir à modifier les quantités.

### Pourquoi

La Policy doit refléter l'architecture métier du stock plutôt qu'encourager un CRUD dangereux.

---

## ADR-019 — Multi-thème séparé du cœur Store

**Statut : ACCEPTED / PLANNED**

### Décision

La personnalisation visuelle des boutiques devra pouvoir évoluer séparément des données principales de `Store`.

### Pourquoi

Ajouter progressivement toutes les options de thème directement dans `stores` rendrait cette table difficile à maintenir.

### État actuel

Le système multi-thème n'est pas encore implémenté.

Aucune documentation ne doit le présenter comme fonctionnel tant que son implémentation n'existe pas.

---

## ADR-020 — API versionnée

**Statut : PLANNED**

### Décision

L'API future suivra une convention de versionnement telle que :

`/api/v1/...`

### État actuel

L'API métier n'est pas encore implémentée.

---

## ADR-021 — Services externes non autoritaires

**Statut : ACCEPTED**

### Décision

Des outils tels que n8n ou Botpress pourront être connectés à ZEMBOA pour des automatisations ou fonctionnalités secondaires.

Ils ne doivent pas contenir la logique métier fondamentale ni devenir la source de vérité des commandes, paiements, stocks ou autorisations.

Laravel et la base ZEMBOA restent autoritaires.

---

## ADR-022 — Frontière HTTP Stock

**Statut : ACCEPTED FOR DESIGN / NOT YET IMPLEMENTED**

### Objectif

La prochaine couche HTTP doit exposer des opérations métier sécurisées autour du Stock.

Architecture prévue :

- liste du stock ;
- consultation d'un StockLevel ;
- réception ;
- ajustement entrant ;
- ajustement sortant.

### Autorisation

Lecture :

`inventory.view`

Mutation :

`inventory.manage`

### Contraintes

- middleware `auth`;
- middleware `tenant`;
- route model binding tenant-aware ;
- Form Requests ;
- contrôleurs minces ;
- Actions existantes réutilisées ;
- aucune modification CRUD directe des quantités.

### Hors périmètre immédiat

La conversion des réservations appartient au futur workflow Orders/Checkout.

`ReturnStock` doit être intégré au futur workflow de retour/livraison plutôt qu'exposé immédiatement comme ajustement générique du commerçant.

---

# Known Technical Issues / Deferred Hardening

Les éléments suivants sont connus et ne doivent pas être oubliés.

### Migration permission_role

Le `down()` de la migration actuelle `create_permission_role_table` appelle par erreur :

`Schema::dropIfExists('role_user')`

au lieu de supprimer `permission_role`.

Ce problème a été découvert pendant l'audit documentaire.

Il ne doit pas être corrigé silencieusement au milieu d'une autre fonctionnalité.

### Tenant integrity

Les relations SQL ne garantissent pas encore systématiquement l'égalité des `store_id` entre toutes les entités liées.

### StockMovement immutability

Les événements Eloquent protègent les opérations normales d'instance, mais pas nécessairement les requêtes de masse.

### StockReservation deletion

Même principe : la protection modèle n'est pas une contrainte SQL absolue.

### Stock invariant

`reserved_quantity <= physical_quantity` est actuellement principalement garanti par les Actions métier, pas par une contrainte SQL dédiée.

### Reservation idempotency

L'idempotence du checkout et des conversions liées aux commandes doit être finalisée avec le futur module Orders/Checkout.

### Reservation expiry scheduler

La logique d'expiration existe, mais son exécution automatique planifiée n'est pas encore intégrée.

### Concurrency testing

`lockForUpdate()` est utilisé, mais des tests MySQL de concurrence plus réalistes restent à prévoir.

### Product history

Lorsque `OrderItem` sera introduit, la suppression des produits/variantes devra préserver cet historique.

### Product images

Les métadonnées existent, mais le véritable workflow d'upload/stockage n'est pas encore complet.

### Multi-theme

Architecture prévue uniquement. Non implémentée actuellement.

---

# Règle de maintenance

Lorsqu'une décision architecturale importante change :

1. ne pas effacer silencieusement l'ancienne décision ;
2. expliquer pourquoi elle est remplacée ;
3. indiquer la nouvelle décision ;
4. documenter l'impact ;
5. ajouter ou adapter les tests concernés.