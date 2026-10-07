# ZEMBOA — Project Rules

Ce document contient les règles permanentes de développement de ZEMBOA.

Il doit être lu avant toute modification importante du projet.

> État de référence lors de la création de ce document :
> branche `feat/stock`, dernier commit poussé connu `2dab68d`.

---

## 1. Vision du projet

ZEMBOA est une plateforme SaaS e-commerce multi-boutiques.

Une seule application Laravel doit pouvoir gérer plusieurs boutiques indépendantes tout en garantissant l'isolation de leurs données.

Architecture V1 :

- une application Laravel ;
- une base de données MySQL partagée ;
- des tables métier partagées ;
- isolation des boutiques principalement par `store_id`.

Le projet doit rester :

- simple ;
- sécurisé ;
- maintenable ;
- évolutif ;
- testable ;
- adapté à une interface mobile-first.

---

## 2. Principe de développement

Ne jamais générer ou modifier une grande partie de l'application sans validation de l'architecture concernée.

Pour chaque fonctionnalité importante :

1. analyser le besoin ;
2. définir les règles métier ;
3. valider l'architecture ;
4. implémenter une petite étape ;
5. tester ;
6. corriger si nécessaire ;
7. valider avant de continuer.

Éviter les refactorisations sans rapport avec la tâche en cours.

Ne pas supprimer ou réécrire du code fonctionnel sans raison technique clairement identifiée.

---

## 3. Architecture applicative

Le flux privilégié est :

`HTTP -> Form Request -> Controller -> Action/Service -> Model/Database`

Les contrôleurs doivent rester minces.

La logique métier importante doit être placée dans des Actions ou services dédiés plutôt que directement dans les contrôleurs.

Pour les traitements secondaires ou asynchrones, l'architecture prévue est :

`Event -> Listener -> Job -> External Service`

n8n, Botpress ou d'autres outils externes peuvent être utilisés pour des automatisations futures, mais ne doivent jamais devenir la source de vérité du métier ZEMBOA.

Laravel reste le cœur métier de l'application.

---

## 4. Multi-tenancy

L'isolation des boutiques est une règle critique.

Les modèles appartenant à une boutique utilisent `store_id`.

Les mécanismes actuellement utilisés comprennent :

- `App\Support\TenantContext` ;
- le middleware `SetTenantContext` ;
- le trait `BelongsToStore` ;
- le global scope Eloquent tenant ;
- les Policies ;
- les contraintes et index de base de données ;
- les tests d'isolation inter-boutiques.

Le système doit échouer de manière fermée lorsqu'aucun tenant valide n'est disponible.

Une donnée appartenant à une boutique ne doit jamais être accessible ou modifiable par une autre boutique.

Les routes utilisant le tenant doivent conserver l'ordre permettant au contexte tenant d'être établi avant le route model binding.

### Limite actuelle importante

Les clés étrangères simples de la base ne garantissent pas à elles seules que deux enregistrements liés appartiennent au même `store_id`.

L'isolation repose donc également sur les Actions, scopes, Policies et validations applicatives.

Ne jamais prétendre que la base garantit actuellement toute l'intégrité inter-tenant.

---

## 5. Utilisateurs, rôles et permissions

Les rôles actuellement prévus dans la V1 sont :

- `SUPER_ADMIN`
- `STORE_OWNER`
- `DELIVERY_AGENT`

Le `SUPER_ADMIN` peut avoir `store_id = NULL`.

Les utilisateurs internes d'une boutique appartiennent à une boutique dans le MVP.

Les rôles/permissions ne remplacent pas l'isolation tenant.

Une permission indique ce qu'un utilisateur peut faire.

Le tenant indique sur quelles données il peut le faire.

Les deux protections doivent rester séparées.

---

## 6. Catalogue

Le catalogue repose notamment sur :

- Category ;
- Product ;
- ProductVariant ;
- Attribute ;
- AttributeValue ;
- ProductImage.

Le prix et le SKU appartiennent à `ProductVariant`, pas directement à `Product`.

Un produit simple possède également une variante par défaut.

Les montants monétaires doivent utiliser des types décimaux adaptés. Ne pas utiliser de nombres flottants pour représenter les montants métier.

Les suppressions physiques ne doivent jamais détruire silencieusement un historique métier.

Une catégorie contenant encore des produits ne doit pas être supprimée.

Un produit possédant un historique de stock ne doit pas être supprimé physiquement.

Une variante possédant un historique de stock ou une réservation ne doit pas être supprimée.

---

## 7. Stock

Le stock est géré par variante de produit.

Dans la V1 :

`1 boutique = 1 stock logique`

Chaque `ProductVariant` possède au maximum un `StockLevel`.

Le stock distingue :

- `physical_quantity`
- `reserved_quantity`

La quantité disponible est calculée :

`available_quantity = physical_quantity - reserved_quantity`

Elle ne doit pas être stockée comme une troisième quantité indépendante.

L'invariant suivant doit être respecté :

`reserved_quantity <= physical_quantity`

### Mouvements

Toute modification du stock physique doit produire un `StockMovement` dans la même transaction métier.

Types actuellement prévus :

- `RECEIPT`
- `SALE`
- `RETURN`
- `ADJUSTMENT_IN`
- `ADJUSTMENT_OUT`

La quantité d'un mouvement est positive.

Le type détermine le sens du mouvement.

Les mouvements représentent un historique et doivent être considérés comme immuables.

Une correction doit produire un mouvement compensatoire plutôt que modifier l'historique existant.

### Réservations

Ajouter un produit au panier ne réserve pas le stock.

La réservation intervient au checkout.

États actuellement utilisés :

- `ACTIVE`
- `CONVERTED`
- `RELEASED`
- `EXPIRED`

Une réservation active augmente `reserved_quantity` sans diminuer immédiatement `physical_quantity`.

Lors de sa conversion :

- `physical_quantity` diminue ;
- `reserved_quantity` diminue ;
- un mouvement `SALE` est créé.

Une réservation annulée ou expirée libère la quantité réservée.

Les opérations sensibles de stock doivent utiliser des transactions et les verrouillages nécessaires pour limiter les problèmes de concurrence.

---

## 8. Retours et livraison

Le livreur ne décide pas seul de remettre un article retourné en stock.

Un colis refusé ou retourné ne doit pas automatiquement augmenter le stock disponible.

Le commerçant doit d'abord confirmer l'état du produit avant qu'un mouvement `RETURN` puisse représenter sa remise en stock.

Le stock physique est destiné à être décrémenté lorsque le produit quitte réellement le stock dans le workflow de livraison, selon les règles métier qui seront intégrées au module Orders/Shipping.

---

## 9. Paiements

Les règles métier déjà décidées pour l'évolution du projet comprennent :

- prise en charge des frais de livraison ;
- possibilité de paiement à la livraison via les moyens de paiement autorisés de la boutique ;
- le livreur n'est pas destiné à collecter directement l'argent pour son propre compte ;
- possibilité future d'acompte ;
- solde possible lors de la livraison ou du retrait selon le workflow.

Le module complet de paiement n'est pas encore considéré comme implémenté tant que le code correspondant n'existe pas.

---

## 10. Sécurité

La sécurité doit être appliquée en profondeur.

Utiliser notamment :

- authentification ;
- autorisation par Policies ;
- permissions ;
- isolation tenant ;
- Form Requests ;
- validation serveur ;
- protection CSRF ;
- échappement des sorties ;
- requêtes Eloquent/Query Builder sûres ;
- rate limiting lorsque nécessaire ;
- transactions pour les opérations critiques ;
- HTTPS en production ;
- protection des secrets ;
- validation stricte des uploads ;
- logs et sauvegardes.

Ne jamais promettre qu'une application est protégée contre « toutes les attaques ».

La sécurité doit être testée et améliorée continuellement.

---

## 11. Tests

Une fonctionnalité importante n'est pas considérée comme terminée uniquement parce qu'elle fonctionne manuellement.

Les tests doivent couvrir selon le besoin :

- comportement normal ;
- validation ;
- permissions ;
- isolation tenant ;
- accès cross-tenant ;
- invariants métier ;
- cas limites ;
- historique.

Les tests cross-tenant sont particulièrement importants.

Les tests utilisant directement des modèles tenant-aware en dehors d'une requête HTTP doivent initialiser explicitement le `TenantContext` lorsque nécessaire.

---

## 12. Base de données et historique

Utiliser les contraintes de base de données lorsqu'elles renforcent réellement les règles métier.

Les données historiques ne doivent pas être supprimées en cascade sans justification.

Les tables d'état courant peuvent avoir des règles de suppression différentes des tables historiques.

Exemple :

- `stock_levels` représente l'état courant ;
- `stock_movements` représente l'historique.

Ne pas modifier une ancienne migration déjà utilisée uniquement pour masquer un problème sans analyser l'impact sur les environnements existants.

---

## 13. Multi-thème et personnalisation des boutiques

ZEMBOA doit pouvoir évoluer vers plusieurs thèmes et paramètres propres aux boutiques.

Cependant, cette fonctionnalité ne doit jamais être documentée comme « implémentée » tant que les modèles, migrations, services et interfaces correspondants ne sont pas réellement présents et testés.

Les settings, thèmes, configurations de paiement et intégrations doivent rester séparables des données principales de `Store`.

---

## 14. API

Une API versionnée est prévue pour l'évolution du projet.

Convention prévue :

`/api/v1/...`

Ne pas considérer l'API comme implémentée tant que ses routes et contrôleurs ne sont pas présents dans le projet.

---

## 15. Git

Branches principales prévues :

- `main` : version stable ;
- `develop` : intégration ;
- `feat/*` : fonctionnalités ;
- `fix/*` : corrections ;
- `test/*` : travail spécifique aux tests lorsque nécessaire.

Faire des commits cohérents et limités à une responsabilité.

Ne pas mélanger une grosse refactorisation avec l'ajout d'une fonctionnalité métier.

---

## 16. Documentation

La documentation doit toujours distinguer clairement :

- `IMPLEMENTED` : présent dans le code et vérifié ;
- `PARTIAL` : présent mais incomplet ;
- `PLANNED` : architecture/règle décidée mais non implémentée ;
- `DEFERRED` : volontairement reporté ;
- `KNOWN ISSUE` : problème ou limite connue.

Ne jamais transformer une intention architecturale en fonctionnalité prétendument existante.

Après une modification importante de l'architecture ou des règles métier, mettre à jour la documentation concernée.

---

## 17. Règles pour les futurs développeurs et assistants IA

Avant une modification importante :

1. lire `PROJECT_RULES.md` ;
2. lire `README.md` ;
3. consulter `DECISIONS.md` ;
4. consulter la documentation pertinente dans `docs/` ;
5. vérifier le code réel ;
6. vérifier les migrations ;
7. vérifier les tests existants ;
8. vérifier `git status` avant de modifier le projet.

Ne jamais supposer qu'une fonctionnalité planifiée existe réellement.

Ne pas changer une décision architecturale validée sans expliquer :

- le problème ;
- la raison du changement ;
- les impacts ;
- les migrations éventuelles ;
- les risques ;
- les tests nécessaires.

---

## 18. État de reprise lors de la création de ce document

Au moment de la création initiale de cette documentation :

- la branche de travail est `feat/stock` ;
- le dernier commit poussé connu est `2dab68d` (`feat: add stock authorization policy`) ;
- le catalogue est déjà en place ;
- le multi-tenancy est déjà en place ;
- le système rôles/permissions est déjà en place ;
- le domaine Stock et ses Actions métier sont en place ;
- `StockLevelPolicy` est en place ;
- aucune couche HTTP Stock n'est encore présente ;
- aucune route Stock n'est encore présente ;
- le multi-thème n'est pas encore implémenté ;
- Orders/Checkout/Shipping/Payments complets ne sont pas encore implémentés ;
- le déploiement production n'est pas encore réalisé/documenté comme terminé.

### Prochaine tâche

La prochaine tâche de développement après finalisation de la documentation est :

**concevoir puis implémenter la frontière HTTP du module Stock.**

Elle devra permettre la consultation du stock et les opérations autorisées de réception/ajustement sans exposer une modification CRUD directe des quantités.