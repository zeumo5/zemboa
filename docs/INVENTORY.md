# ZEMBOA — Inventory / Stock

Ce document décrit le domaine Stock actuellement implémenté dans ZEMBOA.

Il constitue la référence technique pour :

- l'état courant du stock ;
- les mouvements historiques ;
- les réservations ;
- les opérations métier ;
- les règles d'intégrité ;
- les autorisations ;
- la future frontière HTTP Stock.

---

## 1. Objectif du module

Le module Inventory doit répondre à trois questions différentes :

```text
1. Combien avons-nous actuellement ?
   -> StockLevel

2. Pourquoi cette quantité a-t-elle changé ?
   -> StockMovement

3. Quelle quantité est temporairement réservée ?
   -> StockReservation
```

Ces trois responsabilités restent séparées.

---

## 2. Modèle général

```text
Product
   |
   +-- ProductVariant
           |
           +-- StockLevel
           |
           +-- StockMovement
           |
           +-- StockReservation
```

Le stock est géré au niveau de `ProductVariant`.

Même un produit simple utilise une variante.

---

## 3. StockLevel

`StockLevel` représente l'état courant du stock d'une variante.

Quantités principales :

```text
physical_quantity
reserved_quantity
low_stock_threshold
```

La quantité disponible est calculée :

```text
available_quantity =
physical_quantity - reserved_quantity
```

`available_quantity` ne doit pas devenir une troisième quantité indépendante enregistrée et modifiable séparément.

---

## 4. Signification des quantités

### physical_quantity

Quantité physiquement présente dans le stock de la boutique.

### reserved_quantity

Partie du stock physique actuellement réservée.

### available_quantity

Quantité encore utilisable pour une nouvelle réservation.

Exemple :

```text
physical = 20
reserved = 6

available = 14
```

Invariant essentiel :

```text
0 <= reserved_quantity <= physical_quantity
```

Les Actions métier doivent empêcher les opérations qui violeraient cet invariant.

---

## 5. Création d'une variante

Lors de la création d'une nouvelle `ProductVariant`, son état initial de stock est également créé.

État initial :

```text
physical_quantity = 0
reserved_quantity = 0
low_stock_threshold = 5
```

Une variante ne doit donc pas avoir besoin d'une initialisation manuelle supplémentaire avant de pouvoir participer au domaine Stock.

---

## 6. StockMovement

`StockMovement` représente l'historique d'un changement physique de stock.

Exemples de mouvements actuels :

```text
RECEIPT
ADJUSTMENT_IN
ADJUSTMENT_OUT
SALE
RETURN
```

La quantité enregistrée dans un mouvement est positive.

Le sens est donné par le type.

Exemple :

```text
type = ADJUSTMENT_OUT
quantity = 3
```

et non :

```text
quantity = -3
```

---

## 7. Pourquoi conserver les mouvements ?

Sans historique, voir :

```text
physical_quantity = 14
```

ne permet pas de comprendre pourquoi le stock vaut 14.

Les mouvements permettent de reconstruire le contexte métier :

```text
+20 RECEIPT
- 3 SALE
- 2 ADJUSTMENT_OUT
+ 1 RETURN
- 2 SALE
--------------
 14
```

Le `StockLevel` répond donc à :

> Quel est l'état actuel ?

Le `StockMovement` répond à :

> Que s'est-il passé ?

---

## 8. Immutabilité de l'historique

Un mouvement enregistré représente un fait historique.

Il ne doit normalement pas être modifié ou supprimé.

Pour corriger une erreur, on préfère un mouvement compensatoire.

Exemple :

```text
Erreur :
ADJUSTMENT_OUT 5

Correction :
ADJUSTMENT_IN 5
```

plutôt que supprimer silencieusement le premier mouvement.

Les protections actuelles existent au niveau du workflow Eloquent, mais ne constituent pas encore une garantie absolue contre toutes les opérations SQL ou de masse.

---

## 9. CreateStockMovement

Action existante :

```text
CreateStockMovement
```

Elle centralise la création contrôlée d'un mouvement.

Elle vérifie notamment :

- le type de mouvement ;
- une quantité strictement positive ;
- la cohérence tenant ;
- la cohérence de `created_by` lorsqu'un utilisateur est fourni.

Un utilisateur d'une autre boutique ne doit pas pouvoir être enregistré comme auteur d'un mouvement du tenant actif.

---

## 10. ReceiveStock

Action existante :

```text
ReceiveStock
```

But :

enregistrer une réception réelle de marchandise.

Conceptuellement :

```text
BEGIN

lock StockLevel

physical_quantity += quantity

create StockMovement:
    type = RECEIPT

COMMIT
```

Cette Action doit être réutilisée par la future frontière HTTP.

---

## 11. AdjustStockIn

Action existante :

```text
AdjustStockIn
```

But :

effectuer une correction positive explicite.

Conceptuellement :

```text
physical_quantity += quantity

StockMovement:
    ADJUSTMENT_IN
```

Une réception normale et un ajustement positif restent deux intentions métier différentes, même s'ils augmentent tous les deux la quantité physique.

---

## 12. AdjustStockOut

Action existante :

```text
AdjustStockOut
```

But :

effectuer une correction négative explicite.

Conceptuellement :

```text
physical_quantity -= quantity

StockMovement:
    ADJUSTMENT_OUT
```

L'opération ne doit pas rendre le stock incohérent par rapport aux quantités déjà réservées.

---

## 13. ReturnStock

Action existante :

```text
ReturnStock
```

Elle représente le retour confirmé d'une quantité dans le stock physique.

Conceptuellement :

```text
physical_quantity += quantity

StockMovement:
    RETURN
```

Cependant, cette Action ne doit pas être exposée immédiatement comme un simple bouton générique au commerçant.

Dans le futur workflow livraison :

```text
Livreur signale retour
        |
        v
Produit revient boutique
        |
        v
Commerçant vérifie
        |
        v
Retour accepté
        |
        v
ReturnStock
```

Le livreur ne confirme donc pas lui-même la remise définitive en stock.

---

# Réservations

## 14. StockReservation

`StockReservation` représente une quantité temporairement bloquée.

Une réservation ne retire pas immédiatement la marchandise du stock physique.

Exemple :

```text
Avant :

physical = 10
reserved = 2
available = 8
```

Nouvelle réservation de 3 :

```text
physical = 10
reserved = 5
available = 5
```

La marchandise existe toujours physiquement.

---

## 15. CreateStockReservation

Action existante :

```text
CreateStockReservation
```

Elle doit notamment :

1. verrouiller l'état de stock concerné ;
2. calculer la disponibilité ;
3. refuser une quantité indisponible ;
4. créer la réservation ;
5. augmenter `reserved_quantity`.

Conceptuellement :

```text
BEGIN

lock StockLevel

available = physical - reserved

if requested > available:
    reject

create ACTIVE reservation

reserved += requested

COMMIT
```

---

## 16. Cart et réservation

Décision métier validée :

```text
Ajout au panier
    !=
Réservation de stock
```

Le panier ne réserve pas.

Pourquoi ?

Parce qu'un utilisateur pourrait :

```text
ajouter produit
fermer navigateur
revenir plusieurs jours plus tard
```

et bloquer inutilement le stock.

La réservation sera liée au processus de Checkout.

---

## 17. États d'une réservation

Cycle métier :

```text
                 +--> CONVERTED
                 |
ACTIVE ----------+
                 |
                 +--> RELEASED
                 |
                 +--> EXPIRED
```

Une réservation terminale ne doit pas être reconvertie ou relibérée arbitrairement.

---

## 18. ReleaseStockReservation

Action existante :

```text
ReleaseStockReservation
```

But :

libérer volontairement une réservation active.

Conceptuellement :

```text
BEGIN

lock reservation
lock StockLevel

reserved -= reservation.quantity

reservation.status = RELEASED
reservation.released_at = now

COMMIT
```

Le stock physique ne change pas.

La quantité redevient simplement disponible.

---

## 19. ExpireStockReservation

Action existante :

```text
ExpireStockReservation
```

But :

libérer une réservation devenue expirée.

Conceptuellement :

```text
reserved -= quantity

reservation.status = EXPIRED
```

L'Action existe.

En revanche, l'exécution automatique planifiée des expirations n'est pas encore considérée comme implémentée.

Il faudra ultérieurement prévoir le mécanisme scheduler/job adapté.

---

## 20. ConvertStockReservation

Action existante :

```text
ConvertStockReservation
```

But :

transformer une réservation valide en sortie physique de vente.

Conceptuellement :

```text
BEGIN

lock reservation
lock StockLevel

validate ACTIVE reservation

physical -= quantity
reserved -= quantity

create StockMovement:
    SALE

reservation.status = CONVERTED
reservation.converted_at = now

COMMIT
```

Cette opération conserve l'invariant :

```text
reserved <= physical
```

---

## 21. Moment de conversion

La règle métier retenue est :

```text
Commande non payée
    -> ne décrémente pas encore le stock physique

Réservation Checkout
    -> augmente reserved

Sortie physique confirmée
    -> conversion
    -> physical diminue
```

L'intégration exacte dépendra des futurs modules :

```text
Checkout
Orders
Payments
Shipping
```

Ces modules ne sont pas encore implémentés.

---

# Concurrence et intégrité

## 22. Transactions

Les opérations Stock qui modifient plusieurs éléments doivent être atomiques.

Exemple interdit :

```text
physical_quantity modifié

ERREUR

StockMovement jamais créé
```

Le système doit obtenir soit :

```text
TOUT réussi
```

soit :

```text
RIEN appliqué
```

D'où l'utilisation de transactions.

---

## 23. lockForUpdate

Les opérations concurrentes peuvent provoquer des ventes/réservations incohérentes.

Exemple :

```text
available = 1

Client A lit 1
Client B lit 1

A réserve 1
B réserve 1
```

Sans coordination, deux réservations pourraient croire disposer de la même unité.

Les Actions sensibles utilisent donc des verrouillages pessimistes lorsque nécessaire :

```text
lockForUpdate()
```

---

## 24. Limite des tests de concurrence actuels

Les tests automatisés actuels permettent de tester les règles métier générales.

Cependant, les comportements réels de verrouillage MySQL ne doivent pas être considérés comme entièrement prouvés par des tests exécutés uniquement avec SQLite.

Des tests d'intégration/concurrence MySQL devront être ajoutés lorsque cette étape deviendra nécessaire.

---

# Tenant

## 25. Isolation des stocks

Chaque donnée Stock appartient à une boutique.

```text
StockLevel.store_id
StockMovement.store_id
StockReservation.store_id
```

Un utilisateur de :

```text
Store A
```

ne doit jamais consulter ou modifier le stock de :

```text
Store B
```

Cette protection utilise plusieurs couches :

```text
TenantContext
BelongsToStore
Route Model Binding
Policies
Actions
Database foreign keys
Tests
```

---

## 26. Références génériques

`StockMovement` et `StockReservation` possèdent des champs de référence génériques :

```text
reference_type
reference_id
```

Ils permettront de rattacher une opération à une entité métier future.

Exemple conceptuel :

```text
Order
Delivery
Return
```

Limite connue :

ces références génériques ne garantissent pas automatiquement au niveau SQL que l'entité référencée appartient au même tenant.

Les futures intégrations devront donc valider explicitement cette cohérence.

---

# Autorisation

## 27. StockLevelPolicy

Une Policy Stock existe :

```text
StockLevelPolicy
```

Permissions utilisées :

```text
inventory.view
inventory.manage
```

### Lecture

```text
viewAny
    -> inventory.view
```

```text
view
    -> inventory.view
       +
       même boutique
```

### Gestion

```text
manage
    -> inventory.manage
       +
       même boutique
```

---

## 28. Pourquoi `manage` ?

Le Stock n'utilise volontairement pas un CRUD classique :

```text
create
update
delete
```

pour modifier les quantités.

Le métier expose plutôt des intentions :

```text
receive
adjust-in
adjust-out
reserve
release
convert
return
```

Cela rend les opérations plus explicites et auditables.

---

## 29. Opérations interdites

La future interface HTTP ne doit pas permettre :

```text
PATCH /stock-levels/1

{
    "physical_quantity": 999999,
    "reserved_quantity": 0
}
```

Une telle route contournerait :

- les mouvements ;
- l'audit ;
- les invariants ;
- les transactions métier.

Les quantités doivent être modifiées par les Actions appropriées.

---

# HTTP — prochaine étape

## 30. État actuel

Le domaine métier Stock est présent.

Mais lors de l'audit documentaire :

```text
StockController      ABSENT
Stock Form Requests  ABSENT
Stock HTTP routes    ABSENT
Stock UI             ABSENT
```

Le module est donc fonctionnel au niveau métier, mais pas encore exposé comme fonctionnalité HTTP complète au commerçant.

---

## 31. Frontière HTTP prévue

Première proposition validée architecturalement :

```text
GET  stock
GET  stock/{stockLevel}

POST stock/{stockLevel}/receive
POST stock/{stockLevel}/adjust-in
POST stock/{stockLevel}/adjust-out
```

Les noms Laravel définitifs seront validés avant implémentation.

---

## 32. Permissions HTTP

Lecture :

```text
inventory.view
```

Mutation :

```text
inventory.manage
```

Le route model binding doit également bénéficier de l'isolation tenant.

Une tentative cross-tenant doit donc normalement aboutir à une ressource invisible/404 plutôt qu'à l'exposition de données d'une autre boutique.

---

## 33. Form Requests prévues

Les opérations de mutation devront utiliser des Form Requests.

Les données attendues seront notamment de la forme :

```text
quantity
reason
```

Les règles exactes seront déterminées à partir des signatures des Actions existantes avant écriture du code.

Il ne faut pas inventer un contrat HTTP différent du domaine existant sans justification.

---

## 34. Controller prévu

Le futur Controller Stock doit rester mince.

Exemple architectural :

```text
Request
   |
Authorize
   |
ReceiveStock Action
   |
Response
```

Pas :

```text
Request
   |
Controller de 300 lignes
   |
SQL / calculs / historique / permissions mélangés
```

---

## 35. Réservations dans HTTP

Les opérations suivantes ne doivent pas être exposées immédiatement comme boutons manuels génériques :

```text
CreateStockReservation
ConvertStockReservation
ExpireStockReservation
ReturnStock
```

Elles seront principalement utilisées par leurs workflows métier :

```text
Checkout
Orders
Shipping
Returns
Scheduler
```

---

# Tests

## 36. Tests Stock déjà présents

Le projet possède des tests couvrant le domaine Stock, notamment autour :

- des niveaux de stock ;
- des mouvements ;
- des réservations ;
- des Actions ;
- des invariants ;
- des permissions ;
- du cross-tenant ;
- des protections de suppression.

Dernière suite globale connue :

```text
171 passed
418 assertions
0 failed
```

---

## 37. Tests nécessaires pour Stock HTTP

Avant de considérer la frontière HTTP terminée, tester au minimum :

```text
guest rejected

user without inventory.view rejected

owner can view own stock

cross-tenant stock invisible

user without inventory.manage cannot mutate

owner can receive stock

owner can adjust stock in

owner can adjust stock out

invalid quantity rejected

insufficient available stock rejected

successful operation creates correct movement

failed operation does not partially mutate stock
```

Les tests devront utiliser les conventions déjà présentes dans le projet.

---

# Limites connues

## 38. Points à renforcer plus tard

Les éléments suivants restent connus :

1. idempotence Checkout/Orders non implémentée ;
2. expiration automatique des réservations non planifiée techniquement ;
3. tests de concurrence MySQL à ajouter ;
4. protections d'immutabilité principalement applicatives ;
5. opérations Eloquent de masse pouvant contourner certains événements ;
6. références génériques non garanties cross-tenant par SQL ;
7. futurs `OrderItem` à intégrer aux protections historiques ;
8. statuts actuellement représentés sans enum métier complet ;
9. invariant Stock principalement garanti par les Actions applicatives ;
10. les workflows Orders/Checkout/Shipping ne sont pas encore connectés au Stock.

Ces limites ne doivent pas être cachées lors des futures évolutions.

---

# Point exact de reprise

## 39. Prochaine tâche

Après finalisation et vérification de la documentation :

```text
MODULE
Inventory / Stock

LAYER
HTTP boundary

STATUS
NEXT
```

Ordre prévu :

```text
1. Inspecter les Controllers existants
2. Inspecter les Form Requests existantes
3. Revalider routes/web.php
4. Définir précisément les routes Stock
5. Définir les Form Requests
6. Définir le Controller
7. Écrire les tests HTTP
8. Implémenter progressivement
9. Exécuter les tests Stock
10. Exécuter la suite complète
11. Commit séparé
```

Ne pas réimplémenter `StockLevel`, `StockMovement`, `StockReservation` ou les Actions déjà fonctionnelles.

La prochaine fonctionnalité consiste à **connecter proprement HTTP au domaine Stock existant**.