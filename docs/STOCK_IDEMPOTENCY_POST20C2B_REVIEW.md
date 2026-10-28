# Validation technique post-20C-2B — 25 octobre 2026

> Le diagnostic initial ci-dessous est conservé comme preuve historique. La section « Correctif de sécurité autorisé » décrit la correction ultérieure des deux risques ; elle remplace leurs conclusions de non-correction, sans modifier l'inventaire 20C-2C.

## Périmètre et preuves

Inspection des sources locales sur `feat/customer-auth`, Laravel 13.34.0 / PHP 8.3.30. Aucun accès MySQL ni SQLite DEMO. Aucun changement applicatif, aucune migration persistante. `PROJECT_RULES.md` et les décisions métier sont conservés.

Il n'existait pas de document dédié 20C-2B sur disque à l'ouverture de cette revue. `docs/INVENTORY.md`, les décisions et le rapport conversationnel précédent ont servi de références ; les descriptions historiques de fonctionnalités absentes dans INVENTORY/DECISIONS ne remplacent pas le code actuel.

`phpunit.xml` impose SQLite `:memory:` ; aucun cache `bootstrap/cache/config.php`. Les nouveaux tests suppriment les autres connexions de leur configuration. Seul le test de cycle de migration ouvre un fichier SQLite neuf créé par `tempnam` dans le répertoire temporaire système, vide et sans copie de données existantes. Sa cible est vérifiée par `PRAGMA database_list`. Il est déconnecté et supprimé après le test. Les données sont synthétiques ; les seeders RBAC sont exécutés uniquement dans ces tests isolés.

## Migration inspectée

`2026_10_25_023117_create_stock_operation_submissions_table.php` crée :

- `id` : clé primaire, `store_id` et `stock_level_id` : clés étrangères vers stores/stock_levels, suppression RESTRICT ;
- `key_hash` et `request_hash` : CHAR(64) non nullables ; `created_at` : timestamp non nullable ;
- contrainte unique `(store_id, key_hash)` nommée `stock_submission_store_key_unique`.

Dépendances directes : `0001_01_01_000000_create_stores_table.php`, `2026_10_05_152838_create_stock_levels_table.php` et leurs dépendances catalogue. La table ne contient ni mot de passe, ni jeton brut, ni quantité financière. SQLite n'impose pas toutes les bornes/longueurs SQL de MySQL ; les empreintes ont réellement 64 caractères grâce à SHA-256 dans le service.

L'unicité SQL porte sur la boutique et la clé. L'utilisateur est lié au jeton chiffré et à l'empreinte de la requête ; son identité est aussi enregistrée par le mouvement `created_by`. Il n'existe pas de colonne utilisateur dans ce journal. Les FK simples ne garantissent pas l'égalité des boutiques des deux parents ; cette règle reste contrôlée par le scope tenant, la policy et le service.

## Opérations et isolation

`StockOperationSubmission` vérifie le guard web, l'identité professionnelle active, les rôles/permissions, le TenantContext et la policy StockLevel. Le jeton chiffré contient acteur, boutique, opération, nonce et expiration de deux heures. Un rejeu identique est ignoré ; un contenu changé est refusé en 409 ; un jeton altéré, expiré ou appartenant à un autre acteur est refusé en 419.

Une transaction englobe le verrou du StockLevel, l'Action existante, le mouvement et l'insertion du journal. La collision SQL est traitée après rollback de la transaction ; aucune relance de l'Action n'est effectuée. ReceiveStock / AdjustStockIn / AdjustStockOut restent la source métier. Le stock réservé n'est pas modifié par ces opérations ; la sortie ne peut le dépasser. Les nouvelles protections ne rendent pas les anciens POST JSON idempotents : leurs contrats restent inchangés.

## Risques confirmés

### KNOWN ISSUE — journal partiel accepté

`ready()` teste seulement `Schema::hasTable`. Le test d'audit interrompt la création juste avant l'index unique : la table subsiste et `ready()` l'accepte. Laravel 13.34.0 ne déclare pas le support des transactions de schéma dans ses grammars SQLite/MySQL ; le test d'une transaction DDL explicite SQLite prouve son rollback, pas une atomicité automatique des migrations Artisan.

Il faut arrêter après un échec DDL et inspecter colonnes, FK, index et historique, sans relance automatique ni `IF NOT EXISTS` masquant un état partiel. Avant application persistante, recommander un contrôle de disponibilité fail-closed portant sur le schéma complet, notamment l'index unique, avec test négatif. Cette correction applicative n'est pas effectuée dans cette revue diagnostique.

### KNOWN ISSUE — rollback du journal et anciens formulaires

`down()` supprime uniquement le journal, pas le stock, les mouvements, les comptes ou commandes. Le cycle jetable vérifie leur conservation par empreintes des tables métier. Cependant les preuves de rejeu sont perdues : après recréation, un jeton déjà traité, encore valide, applique de nouveau son opération. Un test de caractérisation le démontre.

Ne pas traiter ce rollback comme une annulation de stock. Ne pas supprimer/recréer/purger le journal alors que des formulaires peuvent être soumis. Une procédure contrôlée doit bloquer les écritures, neutraliser les formulaires préexistants — par exemple attendre toute leur durée maximale de validité pendant l'arrêt — et vérifier le schéma avant réouverture. Une génération/version de jeton serait une évolution à décider séparément. Aucune rotation de la clé applicative n'est proposée.

## Tests exécutés

- `ProfessionalInventoryTest` : 15 tests, 144 assertions, SQLite `:memory:` ; doubles soumissions, opérations distinctes, tokens, permissions, isolation, réserve, rollback transactionnel, schéma absent et contrainte unique.
- `StockOperationMigrationTest` : 4 tests, 51 assertions ; cycle migrate/rollback/migrate limité à la nouvelle migration sur fichier jetable, contraintes FK/unique, intégrité et conservation des données ; transaction SQLite explicitement interrompue ; deux tests de caractérisation des limites ci-dessus en mémoire.
- Total distinct : 19 tests, 195 assertions. Les deux tests de caractérisation documentent des risques ; leur réussite ne signifie pas qu'ils sont corrigés.
- Lint PHP, Pint ciblé et contrôles Git de whitespace. Aucune suite générale, aucun build nécessaire pour cette revue sans frontend modifié.

## Validation MySQL/MariaDB restant à autoriser

Aucun serveur jetable déjà autorisé n'a été établi ; aucune connexion n'a été tentée. Les types et contraintes utilisés existent dans les grammars Laravel installés ; cela ne valide pas leur exécution sur la version cible.

Préconditions : autorisation distincte ; serveur isolé de même version/configuration, compte limité à une base jetable, données synthétiques, connexions principales/DEMO absentes de la configuration du processus. Vérifier InnoDB sur toutes les tables concernées, types BIGINT UNSIGNED des parents et FK, SQL mode strict, charset/collation, privilèges et ordre des migrations.

Critères : migration complète avec index unique et deux FK RESTRICT ; refus des doublons/orphelins ; arrêt contrôlé sur schéma partiel ; cycle down/up sans altération des tables métier avec traitement explicite des anciens jetons ; intégrité transactionnelle du mouvement/journal ; deux sessions concurrentes pour même jeton/même stock et même jeton/contenus différents, opérations distinctes sur même stock, sortie contre réservation, échec injecté et contention/deadlock. Vérifier qu'une seule opération est engagée pour un même jeton, sans reprise automatique dangereuse. Ne jamais tester sur `zemboa`.

## Inventaire préalable 20C-2C — lecture de code uniquement

| Fonctionnalité | État | Sources et limites |
| --- | --- | --- |
| Création de commande, snapshots, réservations, calcul serveur | IMPLEMENTED | CreateOrder, PublicCheckout, PublicCheckoutController, CreatePosOrder ; tests OrderTest/PublicCheckoutTest/PosInterfaceTest existants. |
| Liste/détail professionnels de commandes | PARTIAL | OrderController + OrderPolicy : JSON paginé et détail autorisé OWNER/MANAGER. Dashboard professionnel et espace acheteur HTML existent ; pas de gestion HTML professionnelle complète. |
| Annulation/expiration | PARTIAL | CancelOrder/ExpireOrder : PENDING + UNPAID, refus après prise en charge ; libération des réservations. Pas d'endpoint d'annulation dans les routes actuelles ; expiration automatique non activée par cette revue. Ces Actions ne doivent pas être exposées sans autorisation HTTP dédiée. |
| Statuts de préparation/clôture de commande | KNOWN ISSUE | Les écritures actuelles de status portent PENDING et CANCELLED. Le paiement et la livraison ont des états séparés ; ils ne constituent pas un workflow CONFIRMED/PREPARING/READY/COMPLETED de commande. Ne pas afficher de faux états opérationnels. |
| Clients par boutique | PARTIAL | CustomerController, CreateCustomer/UpdateCustomer/FindOrCreateCustomer, CustomerPolicy : liste/détail/création/modification JSON, téléphone normalisé et unique par boutique, aucune suppression exposée. Liste non paginée ; HTML professionnel à raccorder. Aucun lien User/Customer à créer implicitement. |
| Retour SAV | IMPLEMENTED | Create/Review/Receive/Inspect/RestockReturnRequest et ReturnRequestController ; tests CreateReturnRequestTest existants. OWNER et permissions requis ; commande PAID et livraison DELIVERED dans la fenêtre autorisée. Inspection avant ReturnStock, restockable_quantity et restocked_at empêchent une réintégration arbitraire/répétée. |
| Interfaces SAV, retours POS/retrait | PARTIAL | Endpoints JSON présents ; pas de portail SAV HTML complet. La condition livraison DELIVERED ne couvre pas indistinctement retrait ou POS. Toute extension exige une règle validée. |
| Retour après échec de livraison | IMPLEMENTED | ConfirmDeliveryReturn : confirmation propriétaire ; cas UNPAID libère les réservations et annule, sans augmentation physique. Ne pas confondre ce workflow avec une remise en stock d'un article vendu. |
| Remboursements administratifs | PARTIAL | PrepareRefund/ApproveRefund/SimulateRefund, RefundController : PENDING → APPROVED → SIMULATED, plafonds liés aux paiements confirmés, unicité par retour, inspection obligatoire. Aucun transfert d'argent réel ; aucun parcours d'annulation sécurisé des engagements de remboursement. |
| Échanges | PLANNED | Aucun modèle, Action ou route d'échange identifié. Besoin à définir ; aucun contrat d'échange considéré comme validé. |
| Paiements espèces/acompte/solde | IMPLEMENTED | CreateCashPayment/ConfirmPayment et méthodes dédiées de FinalizeOrderPayment : confirmations contrôlées, PARTIALLY_PAID conserve les réservations, PAID les convertit ; l'entrée générique execute() reste interdite. |
| Mobile Money réel | PARTIAL | Actions de vérification/réconciliation présentes, mais le binding MobileMoneyVerifier lève une erreur faute de prestataire réel configuré. Ne pas promettre de paiement ou remboursement externe disponible. |
| Livraison | IMPLEMENTED | AssignDeliveryAgent/UpdateDeliveryStatus + espace livreur HTML : attribution isolée, ASSIGNED → PICKED_UP/FAILED puis DELIVERED/FAILED ; DELIVERED exige PAID. Pas de nouvelle transition ni de collecte d'espèces livreur ajoutée. |

Les statuts IMPLEMENTED de cet inventaire correspondent au code et à ses tests existants inspectés ; ces suites hors stock ne sont pas réexécutées durant cet inventaire.

## Décisions à valider avant la suite

1. Autoriser le contrôle de schéma complet avant opérations de stock et choisir la procédure d'invalidation des formulaires en cas de rollback du journal.
2. Définir les véritables transitions opérationnelles de commandes, leurs permissions et leur articulation avec paiement/livraison ; ne pas déduire les états de la maquette.
3. Confirmer si 20C-2C raccorde seulement le SAV actuel OWNER ou élargit rôles, retours POS/retrait, annulations après acompte ; ne pas élargir silencieusement.
4. Conserver les remboursements explicitement administratifs/simulés tant qu'un mécanisme financier réel n'est pas validé. Définir séparément les échanges.
5. Autoriser séparément tout test MySQL jetable et toute migration persistante.

Aucune implémentation 20C-2C, aucun index Git préparé, aucun commit/push/merge, aucune base existante consultée ou modifiée.

## Correctif de sécurité autorisé

### Choix et impacts techniques

Une mémoire locale PHP ne permet pas de coordonner plusieurs processus. Un cache partagé introduirait une dépendance et ne suffirait pas à garantir l'atomicité avec le stock. Une génération placée dans une autre table impose de synchroniser sa rotation avec toute recréation du journal. Le choix retenu utilise le journal SQL existant : chaque formulaire doit avoir une ligne préenregistrée avant de pouvoir être consommé.

La migration encore non appliquée dans le périmètre autorisé est adaptée : ajout de `user_id` obligatoire avec FK RESTRICT vers users, `stock_level_id` et `request_hash` deviennent nullables. Une ligne émise contient utilisateur/boutique/empreinte du nonce, avec stock et requête à NULL ; la même ligne est complétée dans la transaction métier. Aucun jeton brut n'est stocké, aucun nouveau moteur de stock ou cache partagé n'est introduit. Le chargement d'un nouveau formulaire écrit une trace technique, sans modifier stock ou mouvements. La perte de toutes les lignes — rollback/recréation ou vidage du journal — invalide les formulaires existants, consommés ou non.

Une soumission exige une ligne préexistante correspondant à l'acteur et au tenant ; elle ne crée jamais cette autorisation à la réception du POST. La ligne est verrouillée avant le stock. Une requête identique déjà consommée est ignorée ; une requête différente est refusée en 409. Un ancien jeton dont la ligne a disparu est refusé en 419. Une nouvelle instance de service ou connexion voit le même état SQL. Aucun cache de schéma positif n'est conservé entre requêtes. Aucun traitement n'est relancé automatiquement après échec SQL.

### Schéma partiel — CORRIGÉ

`ready()` vérifie à chaque appel les colonnes indispensables, types, nullabilité et absence de génération, la clé primaire auto-incrémentée, l'unicité exacte `(store_id, key_hash)` et les trois FK RESTRICT vers les parents attendus dans le schéma autorisé. SQLite doit avoir ses FK activées. Pour MySQL/MariaDB, les trois tables modifiées doivent être InnoDB et FOREIGN_KEY_CHECKS actif. L'introspection ou une opération SQL en échec bloque le traitement ; les erreurs contrôlées sont traduites en 503 sans exposer les détails SQL dans la réponse. Les règles tenant/permissions et les Actions métier restent en place.

### Rejeu après recréation — CORRIGÉ

Les anciens tests de caractérisation sont devenus des tests de non-régression : une interruption avant l'index est refusée ; après down/up, les deux anciens jetons — déjà utilisé et encore inutilisé — sont refusés, les stocks/mouvements restent inchangés, et un nouveau formulaire fonctionne. Le cycle sur SQLite neuve jetable ferme et rouvre aussi la connexion pour vérifier que l'état ne dépend pas d'une instance PHP.

Des tests supplémentaires couvrent huit schémas partiels, les FK SQLite désactivées, une erreur d'introspection, une erreur lors de la consommation SQL après création du mouvement, et l'utilisation de nouvelles instances du service. Un échec transactionnel laisse la ligne non consommée et annule intégralement le mouvement et la quantité ; une nouvelle tentative explicite peut réussir une seule fois.

### Déploiement et limites restantes

Aucune application persistante effectuée. La migration de création est adaptée parce qu'elle est encore en préparation avant utilisation persistante selon le périmètre confirmé ; aucune migration historique exécutée n'est réécrite. Si une installation possédait déjà l'ancien schéma, le code le refuserait : son état devrait être vérifié après autorisation et une migration corrective distincte préparée. Ne pas supprimer un journal existant pour le mettre à niveau.

La validation MySQL/MariaDB en exécution reste requise sur un serveur jetable spécifiquement autorisé : introspection réelle des types/FK/engines, migration interrompue, cycle de recréation, sessions réellement concurrentes, contention et deadlocks. Les tests SQLite et les connexions rouvertes ne remplacent pas un test simultané MySQL. Le DDL doit toujours être effectué en maintenance, sans écrivain concurrent ; la vérification de schéma n'est pas un verrou global contre des changements SQL arbitraires.

Les lignes émises mais abandonnées augmentent la taille du journal. Aucun nettoyage automatique n'est ajouté ; toute politique de rétention devra conserver le refus des jetons supprimés. La restauration intégrale d'une ancienne sauvegarde de la base avec des mouvements et autorisations anciens est un sujet distinct des cycles down/up testés. Les endpoints JSON historiques restent hors de cette protection de formulaires HTML. Aucun rôle, aucune quantité réservée, aucun historique n'est effacé par ce correctif.

### Résultats du correctif

24 tests ciblés, 281 assertions réussies : ProfessionalInventoryTest et StockOperationMigrationTest, sans double comptage des relances. Lint PHP sur les six fichiers PHP concernés, Pint ciblé, `git diff --check` et contrôle des fichiers non suivis réussis. Aucun build frontend nécessaire : aucun CSS, JavaScript ou Blade modifié. Les tests historiques ont été renforcés avec les refus attendus ; les comptages distinguent désormais autorisations émises et opérations effectivement consommées.

Fichiers concernés : StockOperationSubmission.php, la migration de création du journal encore en préparation, ProfessionalInventoryTest.php, StockOperationMigrationTest.php, lang/fr/inventory.php, lang/en/inventory.php et le présent rapport. Aucun contrôleur, Action de stock ou mécanisme d'authentification modifié. L'index Git reste vide et les travaux locaux antérieurs sont conservés.
