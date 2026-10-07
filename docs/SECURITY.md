# ZEMBOA — Sécurité

Ce document décrit les principes de sécurité actuellement appliqués dans ZEMBOA ainsi que les protections qui devront accompagner les futurs modules.

La sécurité repose sur plusieurs couches complémentaires.

```text
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
```

Aucune couche seule ne doit être considérée comme suffisante.

---

## 1. Principe général

ZEMBOA est un SaaS multi-boutiques.

Le risque principal à éviter est qu'un utilisateur d'une boutique puisse :

- consulter les données d'une autre boutique ;
- modifier les données d'une autre boutique ;
- supprimer les données d'une autre boutique ;
- utiliser une relation appartenant à une autre boutique ;
- contourner les règles métier via une route HTTP.

La sécurité multi-tenant est donc une exigence centrale de l'architecture.

---

## 2. Authentification

Les routes métier actuellement vérifiées utilisent le middleware :

```text
auth
```

Une requête métier protégée doit donc provenir d'un utilisateur authentifié.

L'authentification répond à la question :

```text
Qui est l'utilisateur ?
```

Elle ne répond pas à elle seule à :

```text
À quelle boutique appartient la ressource ?

L'utilisateur possède-t-il la permission nécessaire ?
```

Ces contrôles appartiennent aux couches suivantes.

---

## 3. Tenant isolation

L'isolation multi-boutiques repose notamment sur :

```text
TenantContext
SetTenantContext
BelongsToStore
Global scopes
Route Model Binding
Policies
Actions
Database constraints
Tests
```

Objectif :

```text
Store A user
     |
     X
     |
Store B data
```

Un utilisateur d'une boutique ne doit pas accéder aux données d'une autre boutique.

---

## 4. TenantContext

Classe actuelle :

```text
App\Support\TenantContext
```

Elle représente le tenant actif pendant l'exécution d'une requête tenant-aware.

Le comportement retenu est :

```text
fail closed
```

Cela signifie qu'en l'absence d'un tenant valide, le système doit échouer plutôt que supprimer silencieusement le filtrage.

Comportement interdit :

```text
No tenant
    ->
return every store's data
```

---

## 5. SetTenantContext

Middleware actuel :

```text
App\Http\Middleware\SetTenantContext
```

Son rôle est d'établir le tenant à partir de l'utilisateur authentifié avant l'accès aux données tenant-aware.

L'ordre des middlewares est important.

Le contexte tenant doit être établi avant :

```text
SubstituteBindings
```

afin que le route model binding bénéficie déjà du scope tenant.

---

## 6. Route Model Binding

Exemple :

```text
/products/123
```

Si le produit `123` appartient à une autre boutique, le global scope tenant doit empêcher sa résolution normale.

Résultat attendu :

```text
404
```

plutôt que révéler inutilement qu'une ressource d'une autre boutique existe.

Ce comportement devra également être conservé pour les futures routes Stock.

---

## 7. BelongsToStore

Le trait tenant-aware :

```text
BelongsToStore
```

permet notamment :

- d'appliquer le global scope tenant ;
- d'associer automatiquement le tenant actif à la création ;
- de fournir la relation avec `Store`.

Toute nouvelle entité tenant-aware doit être évaluée pour déterminer si elle doit utiliser ce mécanisme.

---

## 8. RBAC

ZEMBOA possède un système de rôles et permissions.

Rôles V1 :

```text
SUPER_ADMIN
STORE_OWNER
DELIVERY_AGENT
```

Les rôles sont associés aux utilisateurs.

Les permissions sont associées aux rôles.

Conceptuellement :

```text
User
  |
  v
Role
  |
  v
Permission
```

---

## 9. Permissions

Les permissions définissent des capacités fonctionnelles.

Exemples actuels :

```text
store.view
store.update

categories.view
categories.create
categories.update
categories.delete

products.view
products.create
products.update
products.delete

inventory.view
inventory.manage

orders.view
orders.update

customers.view

deliveries.view
deliveries.update

payments.view
payments.confirm
```

Certaines permissions existent avant l'implémentation complète de leur module.

Leur présence ne signifie donc pas que tous ces modules sont terminés.

---

## 10. Permissions et tenant

Une permission n'autorise jamais automatiquement l'accès cross-tenant.

Exemple :

```text
User Store A
permission = products.update
```

ne signifie pas :

```text
peut modifier les produits de Store B
```

L'autorisation complète doit prendre en compte :

```text
Permission
    +
Tenant ownership
```

---

## 11. Policies

Les Policies fournissent une couche d'autorisation proche des ressources.

Policies actuellement présentes lors de l'audit :

```text
CategoryPolicy
ProductPolicy
ProductVariantPolicy
StockLevelPolicy
```

Elles doivent être utilisées en complément du tenant scope.

---

## 12. Defense in depth

Même lorsqu'une ressource est déjà filtrée par `BelongsToStore`, les Policies peuvent vérifier à nouveau la cohérence du tenant.

Cette duplication est volontaire pour les frontières sensibles.

Principe :

```text
Global scope
    +
Policy
    +
Action validation
```

Une erreur dans une couche ne doit pas immédiatement supprimer toutes les protections.

---

## 13. Stock authorization

Le domaine Stock utilise :

```text
inventory.view
inventory.manage
```

`StockLevelPolicy` fournit notamment :

```text
viewAny
view
manage
```

Lecture :

```text
inventory.view
```

Gestion :

```text
inventory.manage
+
same store
```

---

## 14. Pas de CRUD direct sur les quantités

Une route générique permettant :

```text
physical_quantity = 1000
reserved_quantity = 0
```

ne doit pas être créée.

Les modifications doivent passer par les Actions métier :

```text
ReceiveStock
AdjustStockIn
AdjustStockOut
CreateStockReservation
ReleaseStockReservation
ExpireStockReservation
ConvertStockReservation
ReturnStock
```

Cela protège :

- les invariants ;
- les mouvements historiques ;
- les transactions ;
- l'audit ;
- la cohérence des réservations.

---

## 15. Validation HTTP

Les entrées provenant d'HTTP doivent être validées avant leur utilisation.

L'architecture retenue utilise :

```text
Form Requests
```

Responsabilités typiques :

- type des données ;
- champs obligatoires ;
- longueur ;
- format ;
- limites simples ;
- autorisation HTTP lorsque appropriée.

La validation HTTP ne remplace pas les règles métier des Actions.

---

## 16. Validation métier

Une Action doit rester sûre même si elle est appelée depuis une autre frontière que HTTP.

Exemples :

```text
Job
Command
future API
automated workflow
```

Une Action Stock ne doit donc pas supposer que toutes ses données ont nécessairement été sécurisées par un Form Request.

---

## 17. SQL Injection

Les accès métier doivent privilégier Eloquent et le Query Builder avec leurs mécanismes de paramètres.

Toute future requête SQL brute doit être considérée avec prudence.

Les données utilisateur ne doivent pas être concaténées directement dans une requête SQL.

---

## 18. XSS

Les vues Blade doivent conserver l'échappement par défaut des valeurs provenant des utilisateurs ou des boutiques.

Préférer :

```text
{{ $value }}
```

plutôt que rendre du HTML non échappé sans raison.

Toute utilisation future de contenu HTML fourni par un utilisateur devra faire l'objet d'une stratégie explicite de nettoyage/validation.

---

## 19. CSRF

Les formulaires web Laravel modifiant l'état doivent utiliser la protection CSRF du framework.

Les futures routes API utiliseront leur propre modèle d'authentification et ne devront pas contourner arbitrairement les protections Laravel.

---

## 20. Mass assignment

Les Models doivent contrôler les champs pouvant être affectés en masse.

Une requête HTTP ne doit pas permettre de modifier indirectement des champs sensibles tels que :

```text
store_id
role
permissions
reserved_quantity
physical_quantity
```

simplement parce qu'ils sont présents dans le payload.

---

## 21. store_id

Pour les modèles tenant-aware, `store_id` ne doit pas être choisi librement depuis une requête utilisateur.

Le tenant doit provenir du contexte authentifié.

Exemple interdit :

```text
POST /products

store_id = 999
```

avec l'intention de créer un produit dans une autre boutique.

---

## 22. Transactions

Les opérations sensibles doivent être atomiques.

Particulièrement :

- stock ;
- réservations ;
- futures commandes ;
- futurs paiements ;
- futures opérations de livraison.

Une erreur intermédiaire ne doit pas laisser un état partiellement modifié.

---

## 23. Concurrence Stock

Les Actions Stock sensibles utilisent des transactions et des verrouillages pessimistes lorsque nécessaire.

Exemple :

```text
lockForUpdate()
```

Cela limite les courses critiques autour de :

```text
physical_quantity
reserved_quantity
available quantity
reservation status
```

Des tests de concurrence réels sous MySQL restent à prévoir.

---

## 24. Historique

Les données historiques ne doivent pas être silencieusement détruites.

Cela concerne actuellement particulièrement :

```text
StockMovement
StockReservation
```

et concernera plus tard :

```text
Orders
OrderItems
Payments
Deliveries
Returns
```

Lorsqu'une donnée devient historique, l'archivage ou les statuts sont généralement préférables à une suppression destructive.

---

## 25. Suppression des produits

La suppression d'un produit est protégée lorsque ses variantes possèdent un historique Stock.

Cela évite de détruire indirectement les informations nécessaires à l'audit.

Les futurs `OrderItem` devront également participer à ces protections.

---

## 26. Suppression des catégories

Une catégorie possédant encore des produits ne doit pas être supprimée.

Cette protection évite des suppressions en cascade non désirées du catalogue.

---

## 27. StockMovement

Les mouvements représentent des faits historiques.

Le workflow normal doit empêcher leur modification ou suppression directe.

Une correction doit normalement utiliser une opération compensatoire.

Limite actuelle :

```text
protection principalement au niveau Eloquent/model workflow
```

Certaines opérations de masse peuvent contourner les événements de modèle.

---

## 28. StockReservation

Les réservations possèdent également des protections contre les suppressions directes.

Les changements doivent passer par leurs transitions métier :

```text
ACTIVE
   |
   +-- CONVERTED
   +-- RELEASED
   +-- EXPIRED
```

---

## 29. Références polymorphiques/génériques

Les champs :

```text
reference_type
reference_id
```

des mouvements et réservations ne garantissent pas à eux seuls l'appartenance au même tenant.

Lors de leur future utilisation avec Orders/Shipping, l'Action métier devra vérifier explicitement la cohérence.

---

## 30. Utilisateur créateur d'un mouvement

Lorsqu'un `created_by` est fourni à un mouvement Stock, l'utilisateur doit être cohérent avec le tenant actif.

Un utilisateur d'une autre boutique ne doit pas être utilisé comme auteur d'un mouvement.

---

## 31. Uploads de fichiers

`ProductImage` existe au niveau du domaine/métadonnées, mais le workflow complet d'upload n'est pas encore considéré comme terminé.

Lors de son implémentation, il faudra notamment contrôler :

- type MIME ;
- taille ;
- extension ;
- nom de fichier ;
- stockage ;
- accès public/privé ;
- suppression ;
- tenant ;
- éventuel traitement d'image.

Le nom fourni par l'utilisateur ne doit pas être utilisé aveuglément comme chemin de stockage.

---

## 32. Paiements

Le module Payments complet n'est pas encore implémenté.

Lors de son développement, les règles suivantes seront critiques :

```text
ne jamais faire confiance au navigateur pour confirmer un paiement

vérifier les callbacks/webhooks du fournisseur

vérifier montant et devise

lier le paiement à la bonne boutique et commande

gérer les appels répétés/idempotents

conserver l'historique
```

Une redirection utilisateur vers une page « succès » ne constitue pas une preuve suffisante de paiement.

---

## 33. Mobile Money

Les paiements Mobile Money sont prévus dans l'architecture métier.

Le futur système devra garder Laravel comme source de vérité de l'état interne des commandes et paiements.

Les intégrations externes fournissent des événements/preuves à vérifier ; elles ne remplacent pas les règles métier de ZEMBOA.

---

## 34. Livreur

Le livreur ne doit pas disposer de permissions administratives générales sur une boutique.

Le rôle :

```text
DELIVERY_AGENT
```

doit rester limité aux opérations nécessaires à son travail.

Il ne doit notamment pas pouvoir modifier arbitrairement :

```text
catalogue
stock
permissions
configuration boutique
```

---

## 35. Retours de livraison

Un livreur peut participer au signalement d'un retour.

Cependant :

```text
livreur signale
      |
      v
commerçant vérifie
      |
      v
retour accepté
      |
      v
stock restauré
```

La remise définitive en stock doit dépendre de la validation métier prévue.

---

## 36. SUPER_ADMIN

`SUPER_ADMIN` représente le rôle plateforme.

Son comportement complet doit rester explicitement conçu.

Le fait qu'un utilisateur plateforme puisse avoir :

```text
store_id = NULL
```

ne signifie pas qu'il doit automatiquement contourner tous les contrôles tenant.

Tout mécanisme de bypass plateforme devra être volontaire, limité, audité et testé.

---

## 37. API future

L'API versionnée prévue :

```text
/api/v1
```

est actuellement :

```text
PLANNED
```

Avant son exposition, il faudra définir :

- authentification ;
- rate limiting ;
- permissions ;
- tenant resolution ;
- validation ;
- format d'erreur ;
- versionnement ;
- protection contre l'énumération ;
- idempotence des opérations sensibles.

---

## 38. Rate limiting

Les limites spécifiques aux futurs endpoints sensibles ne sont pas considérées comme finalisées.

Elles seront particulièrement importantes pour :

- authentification ;
- récupération de compte ;
- API ;
- checkout ;
- paiement ;
- webhooks ;
- endpoints publics.

---

## 39. Secrets

Les secrets ne doivent pas être commités dans Git.

Cela inclut notamment :

```text
APP_KEY
database passwords
API keys
payment secrets
webhook secrets
SMTP credentials
tokens
```

Les secrets appartiennent à l'environnement/configuration sécurisée.

Le fichier `.env` local ne doit pas être publié dans le dépôt.

---

## 40. Logs

Les logs doivent être utiles sans exposer inutilement :

- mots de passe ;
- tokens ;
- secrets API ;
- informations de paiement sensibles.

Les futurs logs métier devront faciliter l'investigation sans devenir une fuite de données.

---

## 41. HTTPS

Une future installation de production doit utiliser HTTPS.

Les cookies, sessions et intégrations sensibles devront être configurés pour l'environnement de production.

Le développement local via Laragon ne constitue pas la configuration de sécurité de production.

---

## 42. Sauvegardes

Une stratégie de sauvegarde devra être définie avant production.

Elle devra couvrir au minimum :

```text
database
uploaded files
critical configuration
```

Une sauvegarde n'est réellement utile que si sa restauration est également testée.

---

## 43. Déploiement

ZEMBOA n'est pas actuellement documenté comme étant déployé en production.

Avant production, une revue de sécurité devra notamment vérifier :

- `APP_ENV` ;
- `APP_DEBUG` ;
- HTTPS ;
- permissions fichiers ;
- secrets ;
- base de données ;
- sauvegardes ;
- logs ;
- scheduler ;
- queues ;
- stockage ;
- sessions ;
- cache ;
- dépendances ;
- webhooks ;
- erreurs publiques.

---

## 44. Tests de sécurité

Les tests automatisés doivent couvrir particulièrement :

```text
unauthenticated access
missing permission
cross-tenant access
invalid payload
protected deletion
stock invariants
unauthorized mutation
route model binding isolation
```

Une fonctionnalité tenant-aware ne doit pas être considérée terminée sans test cross-tenant pertinent.

---

## 45. Limites connues actuelles

Points à surveiller :

1. les foreign keys simples ne garantissent pas toujours le même `store_id` entre deux ressources liées ;
2. les références génériques Stock ne garantissent pas le tenant au niveau SQL ;
3. certaines protections historiques sont applicatives et peuvent être contournées par des opérations de masse ;
4. les tests SQLite ne prouvent pas entièrement les comportements de verrouillage MySQL ;
5. l'idempotence Checkout/Orders reste à concevoir ;
6. le scheduler d'expiration des réservations n'est pas encore en place ;
7. le workflow réel d'upload des images reste à sécuriser ;
8. Orders, Payments et Shipping restent à implémenter ;
9. le comportement plateforme complet de `SUPER_ADMIN` reste à définir ;
10. le déploiement production et son durcissement restent à réaliser.

---

## 46. Règle pour toute nouvelle fonctionnalité

Avant d'ajouter une fonctionnalité, vérifier :

```text
Who is the user?

Which tenant owns the data?

Which permission is required?

Which Policy protects the resource?

Which input must be validated?

Which business invariant applies?

Does the operation need a transaction?

Does it create historical data?

What happens on deletion?

What happens cross-tenant?

Which tests prove this?
```

Cette vérification fait partie du développement normal de ZEMBOA.

---

## 47. Prochaine étape de sécurité

La prochaine fonctionnalité est la frontière HTTP Stock.

Elle devra préserver toutes les protections existantes :

```text
auth
tenant
route model binding
StockLevelPolicy
inventory.view
inventory.manage
Form Requests
Stock Actions
transactions
tenant isolation
HTTP tests
```

Aucun endpoint ne devra permettre la modification directe et arbitraire des quantités de `StockLevel`.