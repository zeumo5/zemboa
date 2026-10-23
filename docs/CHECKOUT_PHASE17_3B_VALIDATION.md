# Checkout 17.3B — fiabilisation et validations à autoriser

Branche : feat/customer-auth. Aucun accès MySQL ni ouverture de la base DEMO pour
établir ce diagnostic ; aucune migration persistante, création de base ou commande
d'expiration sur ces bases. Les fichiers 17.3A sont conservés sans indexation Git.

## Dépendances de migration

| Ordre | Migration | Préconditions / effets |
| --- | --- | --- |
| 1 | 2026_10_22_000000_add_is_buyer_to_users_table | users présent ; flag false par défaut, aucun octroi de rôle |
| 2 | 2026_10_22_010000_allow_phone_buyer_identity_on_users_table | is_buyer et phone présents ; email nullable ; projection téléphonique VIRTUAL avec index UNIQUE |
| 3 | 2026_10_23_000000_add_buyer_checkout_identity_to_orders_table | orders/users/stores et FK historiques cohérents ; propriétaire nullable, empreintes et index UNIQUE (store_id, checkout_key_hash) |

Les anciens utilisateurs ne sont pas normalisés ni fusionnés. Le précontrôle de la
migration téléphonique refuse les collisions entre acheteurs historiques avant DDL.
Les doublons professionnels non acheteurs restent possibles ; la connexion par numéro
ambigu est refusée. Les index email historiques sont conservés. Plusieurs email NULL
et anciennes commandes sans clé checkout restent possibles ; aucun historique n'est
attribué au nouveau buyer_user_id. La FK propriétaire interdit la suppression d'un User
référencé, sans cascade destructrice. Les anciennes commandes et le POS restent à NULL.
Le down checkout refuse la suppression de ses protections dès qu'une clé est utilisée.
Le down identité refuse de restaurer un email obligatoire si des comptes sans email existent.

SQLite utilise GLOB et ||, MySQL/MariaDB ASCII/SUBSTR/HEX/CONCAT dans la projection.
Cette différence et les restrictions version/moteur/sql_mode exigent une exécution
spécifique ; la réussite SQLite n'est pas une certification MySQL/MariaDB. Le changement
de nullabilité SQLite reconstruit users sous transaction DDL explicite, avec contraintes
suspendues avant la transaction et foreign_key_check avant commit, puis réactivées.
MySQL/MariaDB peuvent laisser une migration partiellement appliquée après échec DDL.
Ne pas relancer ni rollback automatiquement ; examiner structure et historique séparément.
L'unicité SQL checkout porte sur des empreintes hexadécimales et non les coordonnées.

### État des migrations en attente : ne pas inventer un historique

Les trois fichiers ci-dessus forment la chaîne à contrôler. Le dernier état DEMO rapporté
par le propriétaire était 50 Ran / 0 Pending après is_buyer ; si rien n'a changé, les deux
derniers fichiers sont les candidats attendus. Cette intervention ne consulte pas migrations
sur DEMO : le nombre réellement absent n'est PAS confirmé. MySQL peut manquer les trois,
notamment is_buyer ; son état ne doit pas être déduit de DEMO.

## Validation MySQL/MariaDB jetable — nécessite un accord distinct

1. Après autorisation de lecture, relever version exacte, moteur des tables, charset,
   collation et sql_mode de la cible. Aucun compte ou base créé automatiquement.
2. Après autorisation de création, provisionner une instance/base jetable nommée sans
   ambiguïté, totalement distincte de zemboa et des environnements DEMO/test. Utiliser
   un compte limité à cette base. Configurations locales hors Git, sans modifier .env.
   Refuser tout hôte/base inattendu et vérifier SELECT DATABASE() avant toute écriture.
3. Reconstituer le schéma antérieur par les migrations et des fixtures synthétiques,
   sans importer les données réelles. Conserver une connexion Laravel explicitement
   dédiée ; aucune possibilité de connexion par défaut à zemboa.
4. Appliquer séquentiellement les trois seules dépendances manquantes sur cette cible
   approuvée. Inspecter SHOW CREATE TABLE, colonnes, index, FK et migrations après chaque
   étape. Arrêt au premier échec, avec diagnostic du DDL partiellement enregistré.
5. Tester : collisions historiques avant DDL, trois formats téléphoniques et séparateurs,
   doublons acheteurs refusés, doublons pro maintenus, email NULL multiples, email unique,
   conservation des associations User/rôles, commandes historiques et données de stock.
6. Tester avec deux connexions/processus indépendants : même intention de commande,
   collision UNIQUE et rollback complet des réservations de la tentative perdante ;
   commandes distinctes sur un stock commun ; paiement contre expiration ; deux expirations
   de la même commande. Vérifier ordre des verrous, absence de survente/double libération,
   temps d'attente et erreurs transactionnelles. SQLite mémoire ne prouve pas ces points.
7. Vérifier reprise après réponse perdue, autorisations interacheteurs/interboutiques,
   schéma incomplet refusé et coût des altérations. Sauvegardes/reprise de la vraie cible
   feront l'objet d'un accord ultérieur ; aucune suppression même de la base jetable ici.

## Expiration préparée, désactivée

Aucun ordonnanceur d'expiration n'existait dans le code inspecté. La commande nouvelle
orders:expire-unpaid réutilise uniquement ExpireOrder puis ExpireStockReservation.
Elle exclut explicitement les commandes POS (pos_session_id non NULL) et ne traite que
PENDING / UNPAID avec échéance non NULL <= maintenant, sans colis
déjà récupéré. Chaque action revérifie les règles sous verrou SQL et transaction ;
les acomptes, paiements, échéances futures et colis récupérés ne sont pas libérés.
Les commandes de toutes boutiques sont candidates, chacune sous un TenantContext isolé
restauré en finally. Une boutique suspendue n'annule pas la libération d'une réservation
réellement éligible ; cela ne réouvre aucun parcours public ni accès aux commandes.

config/order_expiration.php est désactivé par défaut. Désactivée, la commande sort avant
toute requête/cache ; routes/console.php ne crée même pas d'événement planifié. Aucune
exécution lors d'une requête HTTP ou du démarrage ordinaire. Le mode DEMO est toujours
refusé pour cette commande et ne reçoit pas de tâche, même si la configuration était activée.

Activation future seulement après autorisation pour une cible précise :
- ORDER_EXPIRATION_ENABLED=true, ORDER_EXPIRATION_CONNECTION et ORDER_EXPIRATION_DATABASE
  doivent désigner explicitement la connexion par défaut autorisée, sans URL ni configuration
  read/write alternative. Aucun de ces paramètres locaux n'est modifié ici.
- ORDER_EXPIRATION_LOCK_STORE doit être un magasin file local ou Redis partagé approuvé.
  Multi-nœuds : Redis commun obligatoire en exploitation, pas un verrou file par machine.
- Le verrou de lot natif Cache dure 600 secondes ; le lot est borné (--limit=100 par défaut,
  maximum 1000). Configurer délai SQL et temps maximal du processus sous cette durée,
  mesurer le lot et réduire sa taille si nécessaire. Aucun second verrou de session ajouté.
  Les verrous de ligne des actions protègent aussi contre la double libération après
  expiration du bail ou une course avec un autre traitement métier.
- Installer/valider ensuite le déclenchement système du scheduler Laravel chaque minute,
  uniquement dans l'environnement approuvé. Ne pas lancer schedule:run ici.
- Les logs order_expiration.expired / skipped / failed n'incluent que identifiants techniques
  et classe d'erreur ; jamais contacts, SQL, secrets ou message brut d'exception. Un refus
  métier (y compris réserve incohérente) est skipped, journalisé et doit être surveillé.
  Une exception technique donne failed et un code de sortie en échec. La transaction de
  l'action annule la commande concernée ; les autres peuvent poursuivre. Une erreur de
  sélection ou d'infrastructure reste un échec global, pas un succès silencieux.

## Garanties des confirmations

La lecture exige tenant + empreinte d'acteur, et buyer_user_id pour un buyer. Un numéro
de commande, des coordonnées ou le jeton signé seul ne permettent pas d'accéder au reçu.
Pour l'invité, la preuve est liée à l'identifiant de session Laravel et expire deux heures
après préparation de l'intention (pas deux heures après clic). Perte/rotation de session,
connexion buyer ou autre boutique : refus, aucun transfert automatique. Cache no-store.
La borne du jeton est désormais stricte (> maintenant), cohérente avec le reçu.
Le rejeu retrouve la commande durable avant le panier vide ; nouvelle révision et contenu
modifié ne peuvent effacer arbitrairement un panier ou créer un doublon de même intention.
Le verrou web natif couvre les requêtes partageant la session ; l'index SQL et la transaction
protègent la clé durable. Les tests mémoire de collision ne remplacent pas les essais MySQL
à deux connexions ci-dessus. Aucun changement du contrat User/Customer ou des rôles.

Phase 17.4, proposition seulement : si un accès invité durable est validé, concevoir une
capacité aléatoire propre à une commande, empreinte stockée, TTL/révocation, limitation
des essais, protection des URL/logs/referrers et canal de remise validé. Ne pas transformer
un téléphone/email non vérifié en récupération de commande. Aucun tel accès implémenté ici.

## Plan SQLite DEMO puis Chrome — aucune étape exécutée ici

1. Autoriser séparément lecture/précontrôles, sauvegarde et migrations explicitement listées,
   puis créations de commandes DEMO pour Chrome (elles écrivent commandes/clients/réserves).
2. Arrêter serveur DEMO et tous processus pouvant écrire ; vérifier le chemin canonique
   database/zemboa_demo.sqlite, liens/hardlinks/sidecars, droits et espace disque. Sauvegarde
   SQLite cohérente via API backup dédiée sous arrêt des écritures ; ne pas copier le .sqlite
   seul avec un WAL actif. Si copie à froid, conserver ensemble base/WAL/SHM sans les supprimer.
   Vérifier l'intégrité et l'historique sur la COPIE, jamais restaurer/reseeder l'original.
   Sauvegardes/données/audits restent exclus de Git, sans secrets dans les rapports.
3. Vérifier statiquement puis via le lanceur autorisé bootstrap/demo.php : .env.demo seul,
   aucun cache config/routes/events DEMO, unique connexion sqlite_demo, pilote SQLite,
   chemin exact, FK actives, DemoConnectionFactory et DemoDatabase. Sessions/cache/médias
   restent isolés ; .env et PHPUnit inchangés. Ne jamais utiliser Artisan normal pour DEMO.
4. Consulter migrations et métadonnées via ce bootstrap isolé après accord, comparer la
   liste exacte aux fichiers. tools/demo.php migrate ne filtre PAS --path : ne le lancer
   que si toutes les migrations absentes sont précisément celles autorisées ; sinon préparer
   un lancement ciblé sécurisé avant exécution. Arrêt sur divergence ou schéma partiel.
5. Après migration autorisée : historique attendu, colonnes/index/FK, foreign_key_check,
   intégrité, coordonnées/rôles/commandes conservés et gardes 503 levées. Expiration reste
   désactivée ; aucune clé DEMO régénérée et aucun utilisateur existant modifié.
6. Après autorisation explicite des écritures de démonstration : invité desktop/mobile FR/EN,
   retrait/livraison et frais réels, prix ignorés depuis navigateur, erreur de coordonnées,
   panier invalide, double clic/réponse perdue, reçu accessible seulement dans sa session,
   nouvelle session/refus, expiration simulée sans attendre sur données réelles ; buyer
   existant, préremplissage, propriété et refus autre buyer/boutique. Pas de nouvel utilisateur
   sans accord ni statut de paiement modifié. Les cas de boutique suspendue/stock insuffisant
   restent en mémoire si leur reproduction exige de changer des données DEMO.
7. Captures sans coordonnées/identifiants/jetons, aucun paiement, aucun checkout sur MySQL.
   Décider du sort des commandes DEMO créées ; ne pas les supprimer automatiquement.

## Vérifications 17.3B réellement effectuées

- phpunit.xml vérifié et cache bootstrap/cache/config.php absent ; lancement forcé SQLite
  :memory:, DB_URL vide, cache/sessions/mail/queue de tests isolés. Aucune commande de
  traitement exécutée en dehors des tests mémoire.
- 22 tests PASS, 158 assertions : 13 checkout, 5 commande d'expiration, 4 expiration
  métier existante. Les 135 tests précédents n'ont pas été intégralement relancés.
- Après ajout de l'exclusion POS et du test de refus DEMO, les 6 tests de commande
  passent avec 31 assertions. Ce second lot recouvre les 5 tests de commande du premier ;
  il ne faut pas additionner les deux lots comme des scénarios entièrement distincts.
- Vérifications de syntaxe PHP et git diff --check. Aucun JavaScript ni frontend modifié
  en 17.3B : aucun build supplémentaire, résultat Vite 17.3A conservé.
- Aucun accès MySQL ni base jetable créée ; aucune ouverture/migration/commande DEMO,
  aucun seeder, commit, push, changement de branche ou indexation Git.
