# Phase 17.3C — précontrôle SQLite DEMO du 23 octobre 2026

Suite autorisée : les deux migrations ont ensuite été appliquées après sauvegarde et
essai sur copie. Voir [CHECKOUT_DEMO_MIGRATION_2026_10_23.md](CHECKOUT_DEMO_MIGRATION_2026_10_23.md).
Les résultats 50/2 ci-dessous conservent le précontrôle historique ; l'état après
application est 52 appliquées et 0 en attente, confirmé dans ce compte rendu séparé.

## Résultat réellement constaté

Branche feat/customer-auth. Inspection des sources DEMO et des migrations, puis lecture
du fichier exact C:/laragon/www/zemboa/database/zemboa_demo.sqlite, sans Laravel/Artisan,
sans .env ni .env.demo et sans connexion MySQL. Aucun outil n'a été installé.

La connexion habituelle DEMO configure WAL/synchronous/transaction_mode ; elle n'a pas
été ouverte pour cet audit strict. Les caches dédiés config/routes/events étaient absents.
Les sources bootstrap/demo.php, demo-database.php, DemoDatabase et DemoConnectionFactory
imposent une seule connexion sqlite_demo et le chemin attendu ; elles ne permettent pas
de substituer la connexion MySQL. tools/demo.php check n'ouvre pas la base mais ne donne
pas l'historique ; migrate applique toutes les migrations manquantes, sans --path.

Méthode de consultation : fichier existant, vérification des redirections/hardlinks,
absence de -wal/-shm/-journal, garde Windows FileAccess.Read / FileShare.Read interdisant
les ouvertures en écriture, PDO SQLite avec SQLITE_OPEN_READONLY et URI
mode=ro&immutable=1. PRAGMA database_list a confirmé le chemin exact. Un fichier WAL
présent ou un verrou impossible aurait provoqué un arrêt, sans consultation forcée.
L'empreinte SHA-256 du fichier est restée identique ; aucun sidecar n'a été créé.
Ce mode immutable n'est PAS une recette de lecture d'une base en cours de modification.
L'essai initial via SQLite3 a refusé l'URI avant ouverture ; PDO a permis la lecture.
Le python disponible comme alias Windows n'était pas exécutable : aucune installation.

Consultation limitée à migrations et métadonnées (colonnes, index, FK, intégrité).
Aucun mot de passe, token, clé ou contact utilisateur lu/affiché ; aucune donnée métier
exportée et aucune sauvegarde créée. Aucun PRAGMA de configuration ni checkpoint exécuté.

| Contrôle | Résultat |
| --- | --- |
| Version SQLite du pilote PHP Laragon 8.3 | 3.40.0 |
| Fichiers de migrations présents | 52 |
| Migrations inscrites dans DEMO | 50 : 49 en batch 1, is_buyer en batch 2 |
| Migrations en attente | 2 |
| Historique sans fichier source correspondant | 0 |
| PRAGMA integrity_check | ok |
| Violations PRAGMA foreign_key_check | 0 |
| users.is_buyer | Présent, obligatoire, défaut 0 |
| users.email | Encore obligatoire, index users_email_unique présent |
| users.buyer_phone_identity / index associé | Absents |
| Colonnes checkout orders et orders_checkout_key_unique | Absents |
| FK orders existantes | stores, customers et FK composée vers pos_sessions présentes |

Les 50 noms de l'historique correspondent aux fichiers existants, sauf les deux suivants
qui sont réellement absents de l'historique. Pas de trace d'application partielle de leurs
colonnes/index observés. Cela n'affirme pas l'absence de doublons dans les coordonnées :
les lignes users n'ont pas été examinées. Le précontrôle de collision reste obligatoire.

## Ordre exact à autoriser ultérieurement

1. 2026_10_22_010000_allow_phone_buyer_identity_on_users_table.php
   - is_buyer est déjà appliquée : NE PAS la relancer.
   - Précontrôle des collisions téléphoniques entre acheteurs, email nullable,
     projection virtuelle et index unique. Aucune normalisation historique automatique.
   - Reconstruction SQLite de users : transaction DDL propre et contrôle FK ; vérifier
     explicitement la conservation des liaisons rôles et références existantes.
2. 2026_10_23_000000_add_buyer_checkout_identity_to_orders_table.php
   - orders/users/stores et schéma antérieur présents. Propriétaire nullable vers users
     avec RESTRICT, métadonnées nullables, unicité par boutique/clé. Les anciennes
     commandes et les comptes ne sont ni attribués ni modifiés par coordonnées.

Le schéma est cohérent avec ces deux migrations en attente. Les risques restants sont
les collisions historiques à détecter, les écritures concurrentes, une interruption
entre migrations et la préservation effective des données lors de la reconstruction.
Les gardes 503 sont donc attendues avant migration ; ne pas les contourner.

## Procédure future de sauvegarde, validation et migration — NON exécutée

1. Accord explicite pour sauvegarde, essais sur copie et application de ces DEUX fichiers.
   Geler les écritures et arrêter le serveur DEMO ainsi que les autres processus écrivains.
   Refaire le contrôle de chemins, liens, sidecars, espace disque, version PHP/SQLite et
   historique : l'état constaté aujourd'hui ne dispense pas d'un contrôle juste avant action.
2. Sauvegarde locale horodatée dans storage/demo/backups, exclue de Git et protégée par
   ACL. Sans WAL/journal et sous garde de lecture, une copie à froid de la base suffit.
   Si WAL présent : conserver ensemble base et WAL/SHM à l'arrêt des écrivains, avec
   empreintes, sans supprimer les originaux ni forcer un checkpoint sur eux. Vérifier
   la copie dans un dossier distinct ; si nécessaire utiliser l'API backup SQLite sur
   cette copie pour produire une image autonome qui intègre les commits du WAL.
   Ne jamais supposer que la copie du .sqlite seul contient les commits d'un WAL.
3. Vérifier l'image autonome de sauvegarde : integrity_check=ok, foreign_key_check vide,
   50 migrations et métadonnées attendues. Comparer les décomptes et empreintes de données
   sur les colonnes historiques, en local protégé, sans exposer de contacts/identifiants.
   Une sauvegarde dont la restauration n'est pas vérifiable interdit la migration.
4. Sur une copie jetable locale expressément autorisée, appliquer la même chaîne et
   vérifier 52 inscriptions, email nullable/unique, projection/index acheteur, colonnes
   checkout/index/FK, conservation des utilisateurs/rôles/commandes/stocks et intégrité.
   Utiliser une configuration propre à cette copie, jamais le lanceur normal de MySQL
   ni le lanceur DEMO dont la garde impose exclusivement la vraie base DEMO.
5. Après succès et autorisation d'application : rétablir uniquement le bootstrap DEMO,
   confirmer DemoConnectionFactory/DemoDatabase, caches dédiés absents, unique connexion
   sqlite_demo et chemin attendu. Ne pas régénérer la clé ni modifier .env ou PHPUnit.
   Le serveur reste arrêté. Si la liste Pending est toujours exactement les deux ci-dessus,
   la commande proposée est :

   ```powershell
   & 'C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' tools/demo.php migrate
   ```

   Cette commande n'est pas autorisée par ce document et n'a PAS été exécutée.
   Si une autre migration apparaît, arrêter : pas de filtre --path dans cet outil,
   donc pas d'exécution globale partiellement approuvée. Aucun seeder ni reset.
6. Vérifications après succès : 52 Ran / 0 Pending, index et FK exacts, intégrité,
   conservation des valeurs historiques et associations, nouvelles colonnes nulles
   pour anciennes commandes. Ne pas remplir buyer_user_id par recherche de contacts.
   Contrôler les garde-fous et pages de préparation, expiration automatique toujours
   désactivée/refusée en DEMO. Aucun achat nécessaire pour ces contrôles de schéma.
7. Au premier échec : arrêter sans relance ni rollback automatique. Garder le serveur
   fermé et conserver l'état en échec/base/sidecars et journaux dans un emplacement
   distinct protégé, sans suppression. Après validation de restauration : archiver cet
   ensemble hors du chemin actif, remettre l'image autonome vérifiée au chemin canonique
   sous arrêt total des écrivains, sans mêler les anciens WAL/SHM à l'image restaurée.
   Vérifier empreintes, intégrité, FK et historique de sauvegarde avant redémarrage.
   Ne jamais écraser/supprimer l'état défaillant ni modifier la clé DEMO pour restaurer.

## Validation MySQL/MariaDB jetable

Aucun accès à zemboa et aucune base créée. Version et état réels MySQL ne sont pas connus.
Voir aussi CHECKOUT_PHASE17_3B_VALIDATION.md : relever après autorisation version, moteur
InnoDB, charset/collation/sql_mode ; provisionner ensuite une cible jetable explicitement
autorisée, compte limité à cette cible, configuration indépendante et SELECT DATABASE()
avant écriture. Aucune copie des comptes réels dans les fixtures synthétiques.

La chaîne peut inclure is_buyer sur MySQL : ne pas déduire son état de DEMO. Tester
chaque migration manquante dans l'ordre. La projection MySQL/MariaDB utilise des fonctions
différentes (ASCII/SUBSTR/HEX/CONCAT, contrairement à GLOB/|| sous SQLite) et dépend des
règles du moteur/version. Tester DDL, index UNIQUE, NULL multiples, doublons et FK,
précontrôle historique sans réécriture, conservation des données et refus de schéma partiel.
Vérifier deux connexions concurrentes : même intention, collisions et rollback des réserves,
stock partagé, expiration contre paiement et double expiration. Succès attendu : une seule
commande/réservation par intention, aucun débit physique au checkout, aucune fuite tenant,
aucune double libération et mesures de verrouillage acceptables. Arrêt immédiat sur échec
DDL : MySQL peut laisser un schéma partiellement appliqué, sans rollback automatique fiable.
Cette validation nécessite son autorisation propre, distincte de celle de migration DEMO.

## Chrome après autorisation des écritures de démonstration

Serveur isolé http://127.0.0.1:8016 via tools/demo.php serve uniquement ; session Chrome
temporaire, desktop/mobile, FR/EN. Ne pas lancer l'outil d'audit historique qui bloque
tous les POST sans revoir son autorisation : ce checkout écrit vraiment dans la DEMO.

- Invité : panier, préparation, coordonnées obligatoires/invalides, retrait disponible,
  livraison avec zone active/frais réels, récapitulatif puis PENDING/UNPAID et réservations.
- Confirmation : accès dans la même session, refus autre session/boutique/ID deviné,
  délai de deux heures depuis l'intention. Test de borne temporelle en mémoire plutôt
  qu'une manipulation de l'horloge ou des commandes de la base DEMO.
- Répétition/double clic/réponse perdue : même commande, pas de seconde réserve ; contenu
  modifié refusé, nouveau panier préservé. Pas de paiement ni changement de statut manuel.
- Buyer existant : connexion, coordonnées préremplies, création sous le véritable User,
  accès refusé à un autre buyer et absence de récupération d'anciennes commandes par contact.
  Connexion après commande invitée : aucun transfert automatique de celle-ci.
- Panier étranger/indisponible et boutique suspendue : tests mémoire si leur reproduction
  exige des changements DEMO non autorisés. Vérifier erreurs, clavier, responsive et liens.
- Captures/rapport sans contacts, mots de passe, jetons ou identifiants de test ; fichiers
  locaux exclus de Git. Autoriser le compte DEMO à utiliser ou sa création séparément.

Les essais créent commandes/Customer/réservations : demander un périmètre d'écritures
explicite, puis décider du maintien des données. Aucun nettoyage automatique prévu.

## Bilan de cette intervention

Seul ce document a été créé. Aucun changement fonctionnel ni migration historique modifiée.
Aucun test PHPUnit ou build répété : les sources fonctionnelles n'ont pas changé.
git diff --check exécuté ; aucune indexation, branche changée, fusion, commit ou push.
Sources SQLite consultées pour les conditions strictes de lecture :
- https://www.sqlite.org/uri.html (immutable et mode=ro)
- https://www.sqlite.org/wal.html (WAL partie de l'état persistant)
