# Identité acheteur par téléphone — évolution avant 17.3

## Contrat et périmètre

Le compte reste User global, guard buyer séparé de web, flag is_buyer serveur,
RBAC inchangé. Customer reste strictement une fiche de boutique : aucune association
ou fusion par coordonnées, aucune commande ou donnée tenant rendue accessible.

Nouvelle inscription : nom obligatoire (150 caractères), mobile camerounais obligatoire,
e-mail facultatif et unique s'il existe, mot de passe 12 à 255 caractères et confirmation.
Les données nom/e-mail sont nettoyées ; le mobile est enregistré en +2376xxxxxxxx.
Les champs de rôle, boutique, statut, vérification et identité calculée sont ignorés.
L'e-mail absent reste NULL, jamais une adresse synthétique ; email_verified_at reste NULL.

BuyerIdentity réutilise NormalizeCustomerPhone sans le modifier. Pour une identité,
un filtre préalable refuse les lettres/autres caractères que ce normaliseur de contacts
retirerait silencieusement. Formats acceptés : 690000001, 237690000001, +237690000001
et séparateurs ASCII espace/tiret/parenthèses/point/tabulation/retour à la ligne.
Le contrat actuel concerne les mobiles commençant par 6 ; pas d'extension aux lignes
fixes ou pays étrangers dans cette intervention.

La connexion accepte identifier = téléphone ou e-mail. L'ancien champ email est gardé
comme alias d'entrée pour les clients existants, mais les erreurs de connexion utilisent
identifier. Le limiteur hash l'identifiant normalisé avant validation : changer la
présentation du même numéro ne crée pas une nouvelle limite. Les limites restent
5 tentatives/minute par IP/identifiant et 30 par IP, et inscription 5 par IP/minute.
Les mots de passe restent vérifiés par attemptWhen Laravel et son contrôle temporisé,
y compris quand aucun compte unique ne correspond. Aucun message de connexion ne
distingue compte absent, ambigu, inactif, mot de passe erroné ou activation non autorisée.

## Historique et professionnels

users.phone était nullable et non unique ; users.email était non nullable et unique.
Aucun champ historique n'est normalisé ou réécrit automatiquement.

Les anciens acheteurs sans téléphone conservent la connexion e-mail. Les nouveaux
comptes exigent un téléphone ; la page compte explique les coordonnées absentes et
l'absence de vérification SMS. Une correspondance téléphonique ou e-mail ambiguë
retourne un refus générique, jamais le premier compte trouvé.

Un professionnel conserve ses règles actuelles (connexion par e-mail, vérification
et rôles existants). Son activation acheteur exige mot de passe correct, consentement,
rôle autorisé, absence de SUPER_ADMIN, et téléphone existant valide non ambigu.
Le seul champ alors modifié est is_buyer, comme précédemment ; ni téléphone, ni e-mail,
ni rôles, ni boutique ne sont modifiés par la connexion. Sans téléphone adéquat,
l'activation est refusée ; aucune collecte/modification automatique du contact pro.
Les doublons de contact entre professionnels non acheteurs restent possibles. Une
collision entre leurs coordonnées bloque inscription/connexion téléphonique/activation
concernée jusqu'à revue autorisée. Un compte déjà acheteur peut toujours utiliser son
e-mail non ambigu. Le contact professionnel d'une identité cumulée doit respecter
l'unicité acheteur s'il est modifié ultérieurement ; la base refusera une collision.

## Garantie SQL et concurrence

Migration nouvelle :
2026_10_22_010000_allow_phone_buyer_identity_on_users_table.php

- Vérifie le pilote SQLite/MySQL/MariaDB pris en charge.
- Précontrôle en lecture seule des collisions entre acheteurs historiques avant DDL.
  En cas de collision : exception sans valeurs personnelles, arrêt sans fusion.
- Rend users.email nullable, en conservant l'index unique existant.
- Ajoute buyer_phone_identity, colonne virtuelle calculée depuis phone/is_buyer,
  avec index unique users_buyer_phone_identity_unique. Cachée dans la sérialisation User
  et non mass assignable. Une valeur ne peut pas être fournie librement par un formulaire.
- La projection ne porte que sur les mobiles valides des lignes is_buyer=true ; les
  autres contacts et les acheteurs historiques sans téléphone projettent NULL.
- Deux variantes de présentation d'un même numéro acheteur ont la même clé unique.
  La garantie est dans la base, pas dans une seule validation exists() ni un verrou
  de session : les inscriptions de sessions différentes sont également protégées.

Le code vérifie les collisions avec TOUS les contacts users, sans filtre de boutique,
et refuse les correspondances ambiguës. L'index unique s'applique aux acheteurs, pas
aux comptes purement professionnels : leurs règles historiques ne sont pas étendues
silencieusement. Une création/modification professionnelle concurrente peut introduire
une ambiguïté de contact ; elle ne peut pas créer deux clés acheteurs identiques.
Dans ce cas, la connexion téléphonique échoue fermée, sans rattachement de comptes.

Les écritures d'inscription/activation vérifient aussi la présence de is_buyer, de la
colonne calculée, de son index UNIQUE et de l'e-mail nullable. En cas de schéma ancien
ou partiel, réponse 503 sans création/activation. Cela protège MySQL ou DEMO tant que
leurs migrations n'ont pas été autorisées. Aucun contrôle de schéma ne remplace une
fenêtre de maintenance pour les opérations DDL.

Le down refuse de rendre l'e-mail obligatoire si des comptes sans e-mail existent.
Aucune adresse factice, suppression de compte ou correction de données automatique.
Sur SQLite, Laravel reconstruit users pour changer la nullabilité. La migration
désactive son enveloppe transactionnelle implicite et exige une exécution au premier
niveau avec clés étrangères initialement actives. Elle suspend les contraintes avant
sa propre transaction DDL, contrôle PRAGMA foreign_key_check avant validation et les
réactive dans finally. Cela évite les suppressions en cascade lors du remplacement
de users ; un test conserve explicitement les associations role_user existantes.
Une transaction englobante ou des clés étrangères déjà désactivées provoquent un refus.
Aucun changement des migrations historiques, des sessions globalement verrouillées,
des règles du panier ou des mécanismes professionnels/stock/POS/paiement.

## Limites de confiance

Aucun SMS, OTP, vérification de propriété téléphonique ou récupération de compte n'est
implémenté. Un numéro déclaré n'est PAS une preuve de propriété ; il peut être réservé
par un tiers avant son titulaire. Ne pas utiliser ce champ comme preuve pour rattacher
Customer, transférer un compte ou récupérer un mot de passe. Une future procédure de
vérification/résolution des conflits nécessitera une conception et validation distinctes.

## Déploiement proposé — aucune commande exécutée sur base persistante

1. Autorisation distincte par environnement. Arrêt des écritures concurrentes (y compris
   professionnels), sauvegarde complète cohérente et vérifiée, plan de reprise.
2. Après autorisation de lecture : vérifier version/moteur, schéma réel, historique
   des migrations et coordonnées ambiguës, sans exporter de données personnelles.
3. users.is_buyer doit déjà exister. MySQL n'est PAS présumé à jour : si elle manque,
   la migration 2026_10_22_000000_add_is_buyer_to_users_table.php doit être revue puis
   appliquée séparément avant la nouvelle. Ne pas lancer toutes les migrations en attente.
4. Valider sur une base jetable de la même version MySQL/MariaDB la génération de la
   colonne virtuelle et son index unique, ainsi que le coût/temps de verrouillage DDL.
   Seule la branche SQLite a été exécutée dans cette intervention ; aucune certification
   d'exécution MySQL/MariaDB ni consultation de leur version réelle.
5. Examiner les collisions en précontrôle. Ne pas nettoyer/normaliser automatiquement
   les utilisateurs historiques. Toute ambiguïté nécessite une décision humaine sûre.
6. MySQL, après nouvelle autorisation et préconditions satisfaites : migration ciblée
   --database=mysql --path=database/migrations/2026_10_22_010000_allow_phone_buyer_identity_on_users_table.php,
   avec PHP Laragon 8.3. Ne pas utiliser fresh/refresh/rollback/seeder. Arrêt au premier
   échec : MySQL peut conserver un changement DDL partiel entre les deux altérations.
7. DEMO, après autorisation : confirmer bootstrap/demo.php, sauvegarde SQLite cohérente
   incluant WAL et fenêtre sans écritures ; inspecter les migrations en attente via
   le bootstrap isolé. tools/demo.php migrate n'accepte PAS --path et applique toutes
   les migrations en attente : l'utiliser uniquement si leur liste est exactement
   celle approuvée. Sinon préparer une exécution ciblée isolée distincte avant accord.
   Ne jamais utiliser le bootstrap Artisan normal pour cette base.
8. Vérifier schéma/index, absence de réécriture des coordonnées, contraintes et statuts,
   puis valider les formulaires et connexion téléphone/e-mail en Chrome FR/EN, mobile
   et desktop. Aucun achat ou paiement. Reprise de service après validation uniquement.

Les formulaires peuvent être affichés avant migration, mais les nouvelles inscriptions
et activations ne sont pas opérationnelles sur un ancien schéma : le 503 est volontaire.
Aucune migration persistante n'est autorisée par la présence de ce code dans Git.

### Validation MySQL/MariaDB jetable à autoriser séparément

Ne pas connecter cette validation à zemboa, ni importer ses utilisateurs réels.
Préparer un serveur et une base jetables explicitement identifiés, avec identifiants
limités à cette base, configuration séparée et même version, moteur, jeu de caractères,
collation et sql_mode que la cible. La configuration habituelle du projet reste intacte.
Reproduire le schéma antérieur avec is_buyer et des fixtures entièrement synthétiques.

Critères d'acceptation avant déploiement :
- Exécuter la migration ciblée et vérifier via SHOW CREATE TABLE / information_schema
  la nullabilité de l'e-mail, son index unique, la colonne VIRTUAL et son index unique.
- Vérifier la même projection pour les trois formats téléphoniques et séparateurs
  acceptés, ainsi que NULL pour les contacts historiques invalides ou non acheteurs.
- Vérifier le refus SQL de deux acheteurs de même numéro (y compris deux connexions
  concurrentes), le maintien des doublons purement professionnels et de plusieurs
  e-mails NULL, et le refus d'un e-mail dupliqué selon la collation cible.
- Vérifier la conservation des coordonnées, rôles et associations existants ; une
  collision entre acheteurs historiques doit interrompre le précontrôle avant DDL.
- Vérifier les inscriptions, connexions et activations sur ce schéma, ainsi que le
  refus d'un schéma partiel. Mesurer les verrous et le temps des altérations.

SQLite utilise GLOB et || ; MySQL/MariaDB utilisent ASCII/SUBSTR/HEX et CONCAT dans
l'expression calculée. Les tests SQLite ne valident donc ni cette expression sur le
serveur cible ni les restrictions DDL propres à son moteur/version/sql_mode. Les
altérations MySQL/MariaDB ne bénéficient pas de la transaction DDL SQLite : prévoir
un état partiel en cas d'échec et une reprise manuelle revue, sans relance automatique.
Cette procédure n'a pas été exécutée et nécessite une autorisation spécifique.

## Vérifications réellement exécutées

- Dernier lot ciblé : 81 tests / 768 assertions PASS en 52,439 s : identité acheteur,
  migration SQLite (7 tests), panier, verrouillage web et concurrence en mémoire.
- phpunit.xml et absence de cache de configuration vérifiés avant chaque lancement.
  Connexion forcée SQLite :memory:, autres connexions retirées dans les tests identité.
- Index unique testé directement par des insertions SQL/Eloquent et changements de
  formats, indépendamment de la validation HTTP. Ce n'est pas un test réel à deux
  processus MySQL : l'atomicité repose sur la contrainte UNIQUE de la base.
- Inscription téléphone seul ou avec e-mail, normalisation, doublons, invalides,
  confirmation, login par téléphone/e-mail, consentement/rôles, anti-énumération,
  throttle normalisé, FR/EN, absence de liaison Customer et maintien du panier testés.
- Précontrôle historique, absence de réécriture, garde de rollback et schéma incomplet
  testés exclusivement en mémoire. Aucun seeder.
- Build Vite 8.3.4 réussi en 4,88 s ; avertissements non bloquants fontaine optionnel
  et durée des callbacks. Aucune dépendance ajoutée.
- Chrome non rejoué : la migration DEMO persistante reste non autorisée.
- Aucun accès MySQL, aucune modification SQLite DEMO, .env ou PHPUnit, aucun commit/push.

Références de compatibilité consultées :
- https://www.sqlite.org/gencol.html (colonne VIRTUAL, index UNIQUE, fonctions déterministes).
- https://dev.mysql.com/doc/refman/8.0/en/create-table-generated-columns.html
- https://mariadb.com/docs/server/reference/sql-statements/data-definition/create/generated-columns
