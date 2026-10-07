# ZEMBOA

ZEMBOA est une plateforme SaaS e-commerce multi-boutiques développée avec Laravel.

L'objectif est de permettre à plusieurs commerçants de gérer leurs boutiques, catalogues, stocks, commandes, livraisons et paiements depuis une même application tout en garantissant l'isolation des données de chaque boutique.

> Statut actuel : développement du MVP.
>
> Branche de travail au moment de cette documentation : `feat/stock`
>
> Dernier commit poussé de référence : `2dab68d` — `feat: add stock authorization policy`
---

## Stack technique

- PHP 8.3
- Laravel 13
- MySQL
- Blade
- Tailwind CSS 4 avec intégration Vite
- Vite
- PHPUnit / Laravel testing tools
- Git / GitHub

Environnement de développement actuel : Laragon sous Windows.

---

## Architecture

ZEMBOA utilise une architecture SaaS multi-boutiques avec :

- une application Laravel ;
- une base de données partagée ;
- des tables métier partagées ;
- `store_id` pour identifier les données appartenant à une boutique.

Le flux applicatif privilégié est :

`HTTP -> Form Request -> Controller -> Action -> Model/Database`

La logique métier importante doit rester dans les Actions/services plutôt que dans les contrôleurs.

---

## Multi-tenancy

L'isolation des boutiques repose actuellement sur plusieurs couches :

- `TenantContext`
- middleware `SetTenantContext`
- trait `BelongsToStore`
- global scope Eloquent
- Policies
- permissions
- contraintes/indexes de base de données
- tests cross-tenant

Le système est conçu pour échouer de manière fermée lorsqu'aucun tenant valide n'est disponible.

Les contraintes SQL actuelles ne garantissent cependant pas à elles seules toute l'intégrité inter-tenant. Les protections applicatives restent indispensables.

---

## Modules actuellement implémentés

### Boutiques et utilisateurs

La base contient les boutiques et les utilisateurs internes associés aux boutiques.

### Rôles et permissions

Rôles V1 :

- `SUPER_ADMIN`
- `STORE_OWNER`
- `DELIVERY_AGENT`

Le RBAC utilise les tables :

- `roles`
- `permissions`
- `role_user`
- `permission_role`

### Catalogue

Le domaine Catalogue comprend actuellement :

- catégories ;
- produits ;
- variantes ;
- attributs ;
- valeurs d'attributs ;
- association variante/valeur ;
- métadonnées d'images produit.

Les opérations HTTP principales existent pour :

- Category
- Product
- ProductVariant

### Stock

Le domaine Stock est implémenté au niveau métier.

Il comprend :

- `StockLevel`
- `StockMovement`
- `StockReservation`

Actions présentes :

- réception de stock ;
- ajustement entrant ;
- ajustement sortant ;
- retour en stock ;
- création de réservation ;
- libération de réservation ;
- expiration de réservation ;
- conversion de réservation en vente.

`StockLevelPolicy` contrôle la consultation et la gestion du stock avec les permissions :

- `inventory.view`
- `inventory.manage`

---

## Règles essentielles du stock

Le stock est géré par `ProductVariant`.

Dans la V1 :

`1 boutique = 1 stock logique`

Les quantités principales sont :

- `physical_quantity`
- `reserved_quantity`

La quantité disponible est calculée :

`available = physical - reserved`

Le panier ne réserve pas le stock.

La réservation intervient au checkout.

Une vente provenant du stock réservé doit convertir la réservation et produire un mouvement historique `SALE`.

Les modifications physiques du stock passent par les Actions métier et ne doivent pas être réalisées par un CRUD direct sur `StockLevel`.

---

## Routes HTTP actuelles

Les routes métier existantes sont protégées par :

`auth + tenant`

Elles couvrent actuellement :

- Categories
- Products
- ProductVariants

La couche HTTP du Stock n'est pas encore implémentée.

---

## Tests

Le projet possède des tests Feature couvrant notamment :

- multi-tenancy ;
- catégories ;
- produits ;
- variantes ;
- attributs ;
- images produit ;
- rôles et permissions ;
- stock ;
- Policies ;
- isolation cross-tenant.

Dernière suite complète connue avant la création de cette documentation :

`171 tests passed — 418 assertions`

Cette valeur est un snapshot historique et doit être mise à jour lorsqu'une nouvelle suite complète est exécutée.

---

## Fonctionnalités non encore terminées

Les éléments suivants ne doivent pas être considérés comme implémentés complètement :

- interface HTTP Stock ;
- interface utilisateur complète ;
- Orders ;
- Checkout ;
- Customers ;
- Shipping ;
- Payments ;
- Notifications ;
- Analytics ;
- API `/api/v1` ;
- système multi-thème ;
- configuration avancée des boutiques ;
- véritable upload/stockage des images produit ;
- expiration automatique planifiée des réservations ;
- déploiement production.

---

## Prochaine étape

La prochaine étape de développement est la frontière HTTP du Stock.

Architecture prévue à valider avant implémentation :

- consultation du stock avec `inventory.view` ;
- réception de stock avec `inventory.manage` ;
- ajustement entrant avec `inventory.manage` ;
- ajustement sortant avec `inventory.manage` ;
- contrôleurs minces ;
- Form Requests ;
- Actions métier existantes réutilisées ;
- aucune route CRUD permettant de modifier directement les quantités de `StockLevel`.

---

## Documentation

La documentation permanente du projet est répartie entre :

- `README.md` — vue d'ensemble et démarrage ;
- `PROJECT_RULES.md` — règles permanentes à respecter ;
- `DECISIONS.md` — décisions architecturales ;
- `CHANGELOG.md` — évolution importante du projet ;
- `docs/` — documentation technique détaillée.

Avant une modification importante, lire `PROJECT_RULES.md`.

---

## Installation locale

Cloner le projet puis installer les dépendances :

```bash
composer install
npm install