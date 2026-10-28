# Phase 17.4A — espace client global et historique

Branche `feat/customer-auth`. Aucun nouveau schéma, système d'authentification ou mécanisme de commande.
Le document antérieur `DEMO_CHECKOUT_BUSINESS_VALIDATION.md` est conservé.

## Pages et contrat de lecture

| Route HTML | Fonction |
| --- | --- |
| GET /client/compte | Tableau de bord : compteurs réels, trois commandes récentes maximum et aperçu du profil |
| GET /client/commandes | Historique global paginé, dix commandes par page |
| GET /client/commandes/{orderId} | Détail d'une commande appartenant au buyer connecté, sinon 404 |
| GET /client/profil | Identité, téléphone normalisé et e-mail facultatif, en lecture seule |

Les routes conservent le middleware `buyer`, le guard de session existant et le verrouillage natif
commun des requêtes web. L'ancien endpoint `/client/compte` reste la destination après connexion ;
sa lecture est déplacée de SessionController vers AccountController. Inscription, connexion,
activation de l'identité buyer, déconnexion et RBAC ne sont pas modifiés.

Le layout `buyer.layout` porte l'identité globale ZEMBOA, une sidebar desktop, un menu de compte
accessible et une navigation mobile. Il ne réutilise pas le layout marchand. Les liens et formulaires
fonctionnent sans nouveau backend transactionnel. Le petit script client ferme le menu par Échap
ou clic extérieur ; les liens natifs et le POST de déconnexion CSRF sont conservés.

## Frontière de propriété

AccountController retire seulement le scope nommé `tenant` sur les lectures de commandes de cet
espace global, puis impose toujours `buyer_user_id = Auth::guard('buyer')->id()` dans la requête.
Il ne supprime pas tous les scopes et ne change ni le trait BelongsToStore, ni les lectures professionnelles.
Les paramètres de requête user_id, buyer_user_id ou store_id ne sélectionnent jamais le propriétaire.

Le détail est d'abord résolu dans cette requête propriétaire, sans binding Order global implicite.
Ses articles sont ensuite chargés par la relation de cette commande autorisée. Les paiements sont
filtrés par son order_id ET son store_id et par le statut CONFIRMED. Aucun Customer n'est chargé
pour retrouver un propriétaire. Commandes invitées, anciennes commandes non attribuées et commandes
d'autres acheteurs restent exclues, même si leurs coordonnées correspondent.

Un SUPER_ADMIN, un compte inactif ou sans identité buyer est refusé par le middleware existant.
L'historique reste consultable pour les propres commandes d'une boutique suspendue ; aucun lien
vers une vitrine suspendue n'est proposé. Ce droit de lecture n'ouvre aucun parcours transactionnel.
Les réponses de l'espace client sont `private, no-store` et `Referrer-Policy: no-referrer`.
L'absence de buyer_user_id provoque un refus 503 de l'historique, sans rapprochement alternatif.

## Données affichées

- Compteurs : toutes les commandes propres, status PENDING, payment_status PAID. « En attente » ne signifie pas automatiquement « non payée ».
- Chargements groupés de la boutique et des quantités d'articles ; pagination et ordre chronologique déterministes.
- Détail : snapshots de nom produit, variant_description si réellement enregistré, quantité, prix final unitaire, sous-total, remise, frais et total mémorisés. La référence SKU reste dans un détail repliable, sans fabrication d'attributs depuis la variante actuelle.
- Réception : mode mémorisé et, pour la livraison, zone, adresse et instructions disponibles sur la commande ; aucun suivi fictif.
- Montant payé : somme des paiements CONFIRMED de la commande/boutique, avec la conversion monétaire entière existante de FinalizeOrderPayment. Le solde est affiché seulement si les montants et le statut UNPAID/PARTIALLY_PAID/PAID concordent. Sinon, récapitulatif indisponible. Il s'agit des paiements confirmés, pas d'un nouveau calcul de remboursements.
- Profil : données du compte uniquement, téléphone masqué dans l'aperçu du tableau de bord et complet sur la page Profil. Aucun formulaire d'édition sensible ; l'absence de vérification SMS reste signalée sur le profil.
- Aucun bouton paiement, acompte, annulation, remboursement, réservation volontaire, facture ou récupération de commande invitée.

Les commandes existantes peuvent avoir variant_description NULL : le libellé commercial de variante
ne peut alors pas être reconstitué fidèlement depuis un catalogue modifiable. Le SKU enregistré reste
consultable. Enrichir les snapshots du checkout serait un travail distinct, non réalisé ici.

## Vérifications

phpunit.xml, variables d'exécution et absence de bootstrap/cache/config.php vérifiés avant PHPUnit.
Les tests refusent toute connexion autre que SQLite :memory: et limitent les connexions disponibles.

BuyerAccountTest et BuyerAuthenticationTest : **40 tests réussis, 352 assertions**. Couverture :
guard requis, sessions professionnelles seules refusées, comptes désactivés/SUPER_ADMIN refusés,
compteurs propriétaires, commandes multiboutiques, paramètres d'identité ignorés, détail interdit,
coordonnées communes sans rattachement, pagination, états vides, FR/EN, profil en lecture seule,
paiements confirmés isolés et montants incohérents masqués, boutique suspendue, non-régressions auth.
L'assertion préexistante de téléphone complet est déplacée sur la page Profil, conformément au nouveau parcours.

Build Vite réussi, syntaxe PHP/JS et git diff --check contrôlés. Avertissement préexistant : fontaine facultatif.

Chrome installé, contexte isolé sur 127.0.0.1:8016 : 28 vues des quatre pages, FR aux largeurs 1440,
1024, 768, 390 et 360, EN à 1440 et 390. Aucun débordement, image cassée ou erreur JavaScript.
La commande acheteur existante apparaît ; la commande invitée est absente et son détail renvoie 404.
Le menu mobile s'ouvre et se ferme par Échap. Les champs personnels sont masqués dans les captures.

Pour ce contrôle, une session de test temporaire préchargée pour le compte DEMO existant a été fournie
au navigateur isolé, puis supprimée. Ce n'est pas un nouveau test de connexion par mot de passe.
Aucun profil Chrome personnel utilisé, aucun compte créé/modifié, aucun cookie ou secret publié.
Seuls GET/HEAD locaux et le POST existant de langue, limité à la session, sont autorisés par le script.
Les empreintes logiques de toutes les tables SQLite DEMO sont identiques avant et après.

Captures et preuves locales exclues de Git : `storage/demo/audits/buyer-17-4a/`.

## Limites et suite

La maquette est adaptée aux données réelles et au responsive : tous les compteurs restent disponibles
sur mobile ; le menu « Mon compte » remplace un bouton hamburger décoratif ; aucune information
commerciale ou étape de suivi n'est inventée. Pas de nouvelle interface professionnelle dans cette phase.

Les migrations d'identité/checkout déjà préparées restent les prérequis d'un autre environnement.
La validation MySQL/MariaDB sur une cible jetable et toute application persistante exigent une autorisation
séparée. Cette phase n'a ni connecté MySQL, ni migré, ni créé de commande ou paiement dans la DEMO.
Les parcours transactionnels client et la gestion professionnelle restent des étapes distinctes.
