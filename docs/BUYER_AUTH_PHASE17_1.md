# Phase 17.1 — identité et authentification acheteur

## Contrat retenu

- Une table `users`, le modèle `User`, son email globalement unique et le cast `hashed`
  existants sont réutilisés. Aucun modèle de compte parallèle, aucune bibliothèque ajoutée.
- Un flag `users.is_buyer`, serveur et non mass assignable, vaut false pour les comptes
  existants. `store_id = null` ne signifie pas « acheteur » : le super administrateur
  utilise déjà cette possibilité. Aucun rôle ni permission professionnel n'est créé.
- Le guard Laravel de session `buyer` partage le provider `users`, mais pas la clé de
  connexion du guard `web`. Une connexion acheteur ne connecte donc jamais implicitement
  un professionnel, même si l'identité possède des rôles professionnels.
- Une inscription crée un acheteur ACTIVE sans boutique, sans rôle et sans email vérifié.
  L'email est normalisé (espaces périphériques et casse) ; un email existant est refusé,
  sans modification du compte. La connexion recherche également sans distinction de casse.
- Un professionnel existant peut activer le flag après contrôle de son mot de passe ET
  consentement explicite dans le formulaire client. Son identité, sa boutique et ses rôles
  restent inchangés. Sa connexion professionnelle conserve ses propres règles, y compris
  boutique active et vérification email des employés. La suspension d'une boutique ne
  suspend pas à elle seule l'usage personnel acheteur d'un utilisateur encore ACTIVE.
- Le cumul SUPER_ADMIN/acheteur est refusé dans cette étape, y compris avec flag présent.
- `Customer` reste une fiche tenant : aucun rattachement, même avec des coordonnées
  identiques, et aucun accès aux commandes existantes n'est ajouté.

## Routes et sessions

| Méthode | URL | Nom |
|---|---|---|
| GET / POST | `/client/inscription` | `buyer.register` / `buyer.register.store` |
| GET / POST | `/client/connexion` | `buyer.login` / `buyer.login.store` |
| GET | `/client/compte` | `buyer.account` |
| POST | `/client/deconnexion` | `buyer.logout` |

Toutes sont dans le groupe `web` avec CSRF. Le middleware `buyer` protège le compte
et la déconnexion, contrôle le statut, le flag et l'exclusion SUPER_ADMIN à chaque requête.
Il ne change pas le guard par défaut et n'initialise aucun `TenantContext`.
Les redirections de succès ciblent le compte client, sans reprendre une URL professionnelle
« intended » ni un paramètre de retour fourni par le navigateur.

L'inscription et la connexion renouvellent l'identifiant et le jeton CSRF de session.
La déconnexion utilise `logoutCurrentDevice()` du guard buyer, détruit l'ancien identifiant
et renouvelle le jeton CSRF ; elle préserve le panier, la langue et la connexion web éventuelle.
Les contrôleurs professionnels restent inchangés : leur déconnexion invalide toujours la
session complète et termine donc aussi une connexion acheteur présente dans ce navigateur.
Aucune option « se souvenir de moi » n'est exposée dans le parcours acheteur.

Limites dédiées, indépendantes des noms des limiteurs professionnels : connexion 5 tentatives
par minute et couple IP/email normalisé, plus 30 par IP ; inscription 5 par IP et minute.
Le mot de passe d'inscription doit comporter 12 à 255 caractères, avec confirmation.
Les mots de passe et confirmations ne sont jamais rendus dans les formulaires après erreur.
Les pages réutilisent le layout et les styles marketplace, sans boutons transactionnels.

## Migration et déploiement : ne pas exécuter implicitement

`2026_10_22_000000_add_is_buyer_to_users_table.php` ajoute seulement le booléen, défaut false.
Elle doit être appliquée avant d'utiliser ces routes en environnement persistant.
Son ajout au dépôt ou son exécution par les tests ne vaut PAS autorisation MySQL/DEMO.
Le down supprime ce flag : il ferait perdre les activations acheteur et n'est pas une
procédure de reprise à utiliser automatiquement.

Aucun seeder global, rôle BUYER, association RBAC ou compte préexistant n'est modifié par
la migration. La validation d'inscription protège la casse sur SQLite comme sur MySQL ;
l'index unique existant protège la collision simultanée d'un même email normalisé.
Les éventuelles identités historiques distinctes seulement par la casse ne sont ni fusionnées
ni corrigées par cette étape.

## Vérifications prévues

`BuyerAuthenticationTest` contrôle inscription, hachage, rejet des champs sensibles,
doublons email y compris professionnels/casse, validation, absence de lien Customer,
rotation de session/CSRF, erreurs de connexion, activation volontaire professionnelle,
absence de privilèges web, exclusion admin, coexistence et déconnexion, comptes protégés,
révocation, isolation des identités, throttling, FR/EN et CSRF réellement activé dans son test.
Les tests pro/admin existants doivent rester valides.

Résultat de cette intervention : **59 tests, 357 assertions réussis** avec PHP Laragon
8.3.30, filtre limité à `BuyerAuthenticationTest`, `EmployeeAuthenticationTest`,
`SuperAdminAuthenticationTest`, `MarketplacePagesTest` et `GuestLocaleHttpTest`.
Les 21 tests acheteurs sont inclus dans ce passage. Lint PHP valide, whitespace conforme
et build Vite réussi (9,58 s). Avertissements non bloquants : paquet optionnel fontaine
absent et temps de callbacks Tailwind/CSS signalé. Aucun nouveau paquet installé.
Validation finale : migration appliquée exclusivement à SQLite DEMO après sauvegarde
SQLite en ligne vérifiée, compatible WAL. Intégrité et clés étrangères valides :
50 migrations exécutées, aucune en attente. La migration acheteur reste à appliquer
sur MySQL avec autorisation distincte ; aucun accès MySQL pendant cette validation.

Chrome installé, profil temporaire : inscription, connexion, compte protégé, erreurs,
déconnexion et menu mobile vérifiés à 1440 et 390 px. Un compte fictif autorisé créé ;
les données métier préexistantes restent inchangées. Rapports et 15 captures sans
identifiants conservés localement dans storage/demo/audits/. Aucun achat ni paiement.

Correction ciblée : cache DEMO général conservé en mémoire, limiteur persistant dans
storage/demo/limiter via bootstrap/demo-cache.php. Le cache array seul perdait les
compteurs entre requêtes HTTP. Test de régression avec recréation des instances et
confirmation Chrome : HTTP 429 au sixième essai invalide.

Avant PHPUnit : configuration sans cache, DB_CONNECTION=sqlite, DB_DATABASE=:memory:,
DB_URL vide, paramètres imposés aussi au processus. Le nouveau test refuse toute autre
configuration avant RefreshDatabase et ne conserve que la connexion SQLite en mémoire.
Ne lancer aucun test MySQL. Aucun test ne doit utiliser SQLite DEMO.

## Limites et suite à valider

Cette étape ne vérifie pas l'email et n'ajoute ni récupération de mot de passe acheteur,
ni MFA, liaison Customer, historique, panier HTML, checkout, paiement, PDF ou likes.
La preuve d'identité et de propriété devra être définie avant d'exposer un historique.
Le flag ne dispense jamais des contrôles RBAC et tenant dans les espaces professionnels.
La langue suit les mécanismes existants : session et, lorsqu'une connexion professionnelle
coexiste, priorité de la préférence de cet utilisateur web. Des préférences acheteur
indépendantes et l'édition d'un profil client restent hors périmètre.

Branche active : `feat/customer-auth`, créée depuis le HEAD de `feat/marketplace`.
Tous les changements locaux sont conservés ; aucun fichier indexé, commit ou push.
`ZEMBOA_BUSINESS_OVERVIEW.md` reste le compte rendu historique précédant cette implémentation.

## Évolution avant 17.3 — téléphone et e-mail facultatif

Le contrat initial ci-dessus est historique. Voir docs/BUYER_IDENTITY_PHONE_PHASE17.md
pour l'évolution actuelle : téléphone obligatoire aux nouvelles inscriptions, e-mail
facultatif, identifiant téléphone/e-mail, clé téléphonique acheteur calculée et unique,
refus des collisions historiques et protection avant migration. Les anciens comptes
sans téléphone ne sont pas modifiés automatiquement. Aucun numéro n'est vérifié par SMS.
La migration nouvelle et celle is_buyer ne sont pas présumées appliquées à MySQL.
Aucune nouvelle migration n'est appliquée sur DEMO dans cette intervention.
