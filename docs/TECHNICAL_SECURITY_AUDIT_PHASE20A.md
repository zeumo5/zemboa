# Phase 20A — audit technique et sécurité

Audit du code local de `feat/customer-auth`, le 24 octobre 2026. Les travaux non committés des phases précédentes sont conservés. Ce rapport ne certifie pas une mise en production : les validations MySQL/MariaDB et les parcours DEMO connectés restent distincts.

**Mise à jour post-audit : A04 est résolue dans le code et les tests ciblés selon la décision métier ci-dessous.** Les résultats initiaux restent conservés pour traçabilité ; la préparation intégrée 20B n'a pas commencé et les migrations persistantes ne sont pas appliquées.

## Périmètre et sources

| Domaine | Sources principales examinées | Contrat et constat |
| --- | --- | --- |
| Routage et sessions | routes/web.php, bootstrap/app.php, config/auth.php, config/session.php, UseCartSessionLocks, SetTenantContext, SetPublicTenantContext | Guards web/buyer séparés ; tenant privé issu de l'acteur, tenant public issu d'un slug actif ; verrou natif avant lecture de session. |
| Administration | User::canUseSuperAdminAccount, EnsureSuperAdmin, AdminSessionController, Admin/StoreController, SendOwnerInvitationController | SUPER_ADMIN actif, is_buyer=false, sans boutique et rôle exclusif ; aucune attribution automatique de droits marchands ; boutique PENDING et propriétaire INVITED avant activation. |
| Acheteur | BuyerIdentity, RegisterBuyer, SessionController buyer, AccountController buyer, requests et migration téléphonique | Téléphone camerounais, e-mail optionnel, mots de passe de 12 caractères, limites de tentatives ; historique par buyer_user_id, jamais par coordonnées Customer. Numéros non vérifiés par SMS. |
| Employés | EmployeeAccess, EmployeeController, EmployeeInvitationController, notifications et InvitationPrivacy | Création de comptes neufs, rôle choisi dans la liste autorisée, tenant serveur ; mot de passe par invitation expirante et à usage unique ; activation prématurée refusée. |
| Panier et checkout | CartService, PublicCartController, PublicCheckout, PublicCheckoutController, CreateOrder, OrderPriceCalculator | Panier mono-boutique, abandon explicite signé, refus des confirmations périmées, quantités/prix serveur, idempotence SQL ; invité limité à sa session pendant deux heures. |
| Stock et expiration | CreateStockReservation, ConvertStockReservation, ExpireStockReservation, ExpireOrder, ExpireUnpaidOrders, config/order_expiration.php | Verrous et contrôles de quantités ; ajout panier sans réservation ; expiration désactivée par défaut et interdite dans le lanceur DEMO. |
| Paiements | CreateCashPayment, ConfirmPayment, FinalizeOrderPayment, ValidateStorePaymentRules, ProcessMobileMoneyPayment, ConfirmVerifiedMobileMoneyPayment, AppServiceProvider | Espèces sous autorisation ; confirmation et stock transactionnels ; acompte sans StoreSetting refusé. Aucun fournisseur Mobile Money réel configuré. Les anciennes entrées SUCCESS non activées échouent fermées. |
| POS et documents | PosAccess, PosCart, SubmitPosSale, Professional/PosController, vues professional/pos, StoreDocumentSetting, Dompdf | Vente physique éventuellement anonyme ; pas de faux client ; clé persistante unique, snapshot vendeur/caissier, contrôle de session ; PDF privé, ressources distantes/PHP/JS désactivés et chroot limité. |
| Livraison | DeliveryAccess, DeliveryAgentController, DeliveryController, AssignDeliveryAgent, UpdateDeliveryStatus, ConfirmDeliveryReturn | Missions limitées au livreur et à la boutique ; PICKUP exclu ; remise seulement PAID ; aucun encaissement livreur ; dates existantes, pas de preuve photo/signature inventée. |
| Retours/remboursements | ReturnRequestController, RefundController, ApproveRefund, SimulateRefund, tests d'atomicité connexes | Lecture statique des contrôles et verrous ; aucune opération financière exécutée hors tests en mémoire. Pas de validation exhaustive de tous les cas SAV dans ce lot. |
| Environnement | bootstrap/demo*, DemoDatabase, DemoConnectionFactory, DemoMail, tools/demo*, config/boost.php, manifestes et .gitignore | DEMO sans connexion alternative, médias/sessions/cache isolés, Mailpit local optionnel, destinataires example.test ; Boost limité à APP_ENV=local. |

Les policies Category, Product, ProductVariant, Customer, StockLevel, StoreSetting et Order ont aussi été lues. Les anciens endpoints dépendent encore de plusieurs styles de contrôle (policy, action, contrôleur), ce qui exige les tests de non-régression transversaux ; l'audit ne prétend pas une preuve exhaustive de chaque combinaison de permissions possible.

## Anomalies et corrections

| ID | Gravité | Constat vérifiable | État |
| --- | --- | --- | --- |
| A01 | Critique conditionnelle à un rollback | Le down de 2026_10_01_131424_create_permission_role_table.php supprimait role_user au lieu de permission_role : perte possible des associations utilisateur/rôle si exécuté. | Corrigé dans le fichier seulement. Test down/up en mémoire : rôle utilisateur intact. Aucun rollback persistant exécuté. |
| A02 | Importante | L'invitation propriétaire validait le jeton avant l'activation mais ne le revérifiait pas sous verrou ; la réémission ne verrouillait pas le propriétaire. Une rotation concurrente pouvait laisser accepter un jeton auparavant valide. | Acceptation : revalidation tokenExists sous verrou utilisateur. Réémission : transaction avec verrous boutique/propriétaire. Notification après transaction. Test d'intercalation en mémoire réussi ; contention réelle MySQL non testée. |
| A03 | Moyenne | Le layout client 17.4A avait perdu le badge/lien panier. PublicCartTest échouait après connexion par absence du composant, pas par perte du panier en session. | Composant cart-link et traductions cart-config réutilisés dans buyer/layout, sans nouveau backend. Test ciblé réussi. |
| A04 | Importante, ouverte | Les assertions exactes de StoreManagerRbacAuditTest et CashierRbacAuditTest divergent du seeder et des migrations POS historiques. | Deux tests restent en échec. Aucun droit changé, aucune assertion assouplie. Décision de matrice requise avant de déclarer le RBAC global validé. |
| A05 | Importante pour déploiement | Migrations préparées non validées en exécution MySQL/MariaDB ; schémas persistants non consultés ici. | Préconditions et validation jetable décrites ci-dessous. Pas de feu vert pour migrer une base persistante. |
| A06 | Moyenne, limite d'exploitation | Le verrou session natif expire après session.block_lock_seconds (120 par défaut), sans renouvellement. Le stockage file ne coordonne pas plusieurs serveurs. | Vérifier une durée maximale de requête inférieure au bail ; Redis partagé et délais à valider pour un déploiement multi-instance. Aucun changement d'architecture appliqué. |
| A07 | Moyenne, risque statique à vérifier | CreateOrder réserve les variantes dans l'ordre des articles ; deux paniers contenant les mêmes variantes dans des ordres inverses peuvent se disputer les lignes dans un ordre différent sur MySQL. Le POS trie son panier. | Aucune corruption démontrée ; les transactions doivent annuler un échec. Test concurrent multi-connexion jetable à prévoir, avec examen du traitement des deadlocks et de la reprise. |
| A08 | Moyenne, opérationnelle | Une échéance dépassée ne libère pas spontanément les réservations. L'expiration reste volontairement désactivée, et le lot exclut le POS/les colis récupérés. | Prévoir une politique d'exploitation et une activation séparément autorisée ; aucun traitement lancé ici. |
| A09 | Faible | Fontaine optionnel absent ; avertissement de durée des plugins Vite. Impression thermique matérielle et journaux HTTP hors Laravel non revalidés. | Build réussi. Pas de dépendance ajoutée ; vérifier imprimante et configuration des journaux lors de 20B/déploiement. |

### Matrice RBAC à décider (A04)

CASHIER possède actuellement orders.view, payments.confirm, payments.view, pos.registers.view, pos.sales.create, pos.sessions.open et pos.sessions.close. Son test exact historique n'attend que les trois premiers. Les quatre autres sont nécessaires aux parcours POS existants et sont aussi accordés par les migrations du 18 octobre ; ce constat ne constitue pas une nouvelle attribution.

STORE_MANAGER possède les droits opérationnels attendus par son ancien test et huit droits supplémentaires : pos.cash_movements.manage, pos.registers.manage, pos.registers.view, pos.sales.create, pos.sessions.close, pos.sessions.open, store.document_settings.update et store.document_settings.view.

Le point sensible est concret : RecordPosCashMovement et PosCashMovementController autorisent le gestionnaire muni de pos.cash_movements.manage à enregistrer des entrées/sorties manuelles. La migration 2026_10_18_135637 attribue déjà ce droit au propriétaire et au gestionnaire. Il faut confirmer s'il relève de la gestion opérationnelle autorisée ou de la gestion financière réservée au propriétaire. Les droits documentaires concernent les paramètres de documents ; ils ne donnent pas payments.confirm ni refunds.manage, mais leur attribution doit aussi être confirmée dans la matrice finale.

Après validation explicite, soit les attentes exactes des tests seront complétées pour refléter la matrice approuvée, soit les attributions/actions concernées seront modifiées par une intervention dédiée. Aucun seeder global ni retrait silencieux de droits existants ne doit servir à masquer la divergence.

### Limites financières supplémentaires

Les paiements partiels échouent fermés sans paramètres ; le paiement intégral reste autorisé. L'expression SQL de l'identité téléphonique garantit l'unicité entre acheteurs ; les refus de collision avec les professionnels reposent aussi sur des précontrôles applicatifs. Une course entre création professionnelle et acheteur n'est pas démontrée comme impossible par une contrainte SQL commune à toutes ces identités.

Le checkout JSON historique et le moteur CreateOrder conservent certains comportements de compatibilité en l'absence de StoreSetting, tandis que le formulaire HTML exige une configuration commerciale explicite. Il faut décider d'une règle commune avant exposition de cette API à des clients tiers ; aucune modification des valeurs métier par défaut n'est faite durant l'audit.

## Vérifications exécutées

Avant PHPUnit : phpunit.xml inspecté, connexion SQLite :memory: et DB_URL vide, cache bootstrap/cache/config.php absent. Les nouveaux tests suppriment les autres connexions. PHP utilisé : Laragon 8.3.30. Aucun test MySQL exécuté ; ConfirmPaymentConcurrencyTest, réservé à une autre base MySQL, n'est pas lancé.

1. Corrections A01/A02 et invitations propriétaires : **12 tests réussis, 104 assertions**.
2. Lot transversal, 36 fichiers Feature et trois fichiers Unit : **371 tests, 368 réussites, deux échecs RBAC et une erreur badge**, **2413 assertions**, environ 396 s.
3. Après correction A03 : scénario login/panier ciblé **1 test réussi, 12 assertions**. La campagne complète n'est pas relancée pour masquer les deux échecs RBAC ; ils demeurent ouverts.
4. Contrôle complémentaire PublicCartTest + BuyerAccountTest : **36 tests réussis, 350 assertions**, après restauration du badge. L'erreur du lot transversal est résolue ; les deux échecs de matrice RBAC restent ouverts.
5. Lint : **389 fichiers PHP**, aucune erreur ; syntaxe de **10 fichiers JavaScript**, aucune erreur.
6. Formatage limité aux fichiers PHP corrigés ; git diff --check réussi. Aucun index préparé.
7. Build Vite **réussi en 5,97 s**, 12 modules. Les avertissements fontaine et durée des callbacks n'empêchent pas la compilation.

Les lots se recouvrent : leurs nombres ne doivent pas être additionnés pour annoncer un total de tests distincts. Les tests d'intégration passent par les vrais contrôleurs/actions sur SQLite en mémoire ; ils ne constituent pas des parcours connectés à la DEMO.

**Aucun nouvel audit Chrome n'est exécuté en 20A.** Les 36 contrôles de rendu isolé 19C restent des preuves antérieures, pas une validation des parcours intégrés. Le badge client restauré est couvert par le test HTTP ; son rendu responsive devra être vérifié en 20B.

## Migrations et dépendances

Le disque contient **53 fichiers de migration**, dont la nouvelle migration POS 2026_10_24_083724_add_pos_submission_snapshot_to_orders_table.php. Les migrations sont exécutées uniquement lors des tests en mémoire.

L'état exact des migrations persistantes n'est **pas confirmé** : aucun historique MySQL ni SQLite DEMO n'est ouvert durant cet audit. Les dernières preuves documentaires indiquent MySQL à 49 migrations et DEMO à 52 ; elles ne remplacent pas une lecture actuelle autorisée.

Sur cette seule base documentaire, les candidates à contrôler, dans cet ordre, sont :

1. 2026_10_22_000000_add_is_buyer_to_users_table.php — identité acheteur ; déjà documentée comme appliquée en DEMO, potentiellement absente de MySQL.
2. 2026_10_22_010000_allow_phone_buyer_identity_on_users_table.php — e-mail nullable et colonne téléphonique calculée unique ; déjà documentée en DEMO.
3. 2026_10_23_000000_add_buyer_checkout_identity_to_orders_table.php — propriétaire acheteur et métadonnées SQL d'idempotence ; déjà documentée en DEMO.
4. 2026_10_24_083724_add_pos_submission_snapshot_to_orders_table.php — identité client nullable, auteur/snapshot POS et clé unique ; seule candidate DEMO d'après la dernière preuve.

Ne pas rejouer les trois premières sur la DEMO sans consulter l'historique. Le correctif A01 modifie uniquement le down d'une migration ancienne, sans nécessité de rejouer son up.

Validation MySQL/MariaDB restante : serveur jetable de même version/sql_mode/collation, compte limité à cette base, nom refusant explicitement zemboa, aucune chaîne de connexion héritée de .env. Après autorisation distincte, appliquer le schéma sur cette base jetable, insérer uniquement des fixtures, vérifier colonnes calculées et variantes de téléphone, index UNIQUE, nullabilité sous FK, conservation des associations et snapshots, replays concurrents et rollback applicatif. Les DDL MySQL peuvent laisser un état partiel ; sauvegarde et reprise manuelle sont indispensables avant une migration persistante.

Dompdf 3.1.6 et ses dépendances restent ceux installés en 19B ; aucune nouvelle dépendance dans cet audit. Les manifestes/locks sont conservés. Boost est une dépendance de développement, activée uniquement en environnement local ; ses outils ne doivent pas être installés/exposés en production. Aucun audit de vulnérabilités auprès d'un registre en ligne n'est exécuté : aucune absence exhaustive de CVE n'est affirmée.

## Git, confidentialité et seconde revue

État initial vérifié : 45 fichiers suivis modifiés, 95 nouveaux, index vide. Les modifications préexistantes restent intactes.

Fichiers de cette intervention :

- database/migrations/2026_10_01_131424_create_permission_role_table.php
- app/Http/Controllers/OwnerInvitationController.php
- app/Http/Controllers/Admin/SendOwnerInvitationController.php
- resources/views/buyer/layout.blade.php
- tests/Feature/TechnicalSecurityAuditTest.php
- docs/TECHNICAL_SECURITY_AUDIT_PHASE20A.md

.gitignore exclut les environnements locaux, bases/sidecars SQLite, storage/demo, médias DEMO générés, sauvegardes bak, ZIP, builds, logs et caches. Les exemples d'environnement sont des exceptions explicites à conserver après vérification, pas les fichiers locaux. Ne pas utiliser git add global pour les travaux encore mélangés de plusieurs phases.

Une sélection de sources est préparée dans `storage/demo/audits/phase20a/`. Le ZIP `ZEMBOA_PHASE20A_REVIEW.zip` contient les sources applicatives, routes, vues, styles/scripts, configurations, tests, migrations/factories/seeders, outils et documents, sans vendor/node_modules, fichiers d'environnement, bases, uploads, sessions, sauvegardes, captures ou builds. Les liens symboliques, jonctions et fichiers à liens multiples sont refusés. Les fichiers correspondant aux motifs forts de secrets sont exclus avant compression ; les mots de passe de tests restent des fixtures fictives et aucun secret réel ne doit être ajouté pour faciliter la revue.

Le manifeste interne MANIFEST_SHA256.txt couvre chaque source. Le fichier ZIP reçoit aussi son SHA-256 séparé ; CRC et empreintes des entrées sont revérifiés. Le scan par motifs n'est pas une preuve mathématique d'absence de toute donnée sensible ; la sélection exclut structurellement les données locales et les valeurs suspectes ne sont jamais imprimées.

## Plan 20B — validation fonctionnelle intégrée

1. **Décision préalable** : approuver la matrice propriétaire/gestionnaire/caissier, notamment mouvements manuels de caisse et réglages documentaires. Garder les deux tests RBAC rouges tant que cette décision n'est pas traduite explicitement.
2. **Autoriser séparément la préparation DEMO** : arrêter les écrivains, vérifier la cible et l'historique en lecture sûre, sauvegarde SQLite Online Backup tenant compte du WAL, integrity_check et foreign_key_check, validation de la migration POS sur copie, puis application seulement après autorisation correspondante. Prévoir une restauration manuelle contrôlée, sans reset.
3. **Préparer les acteurs et fixtures autorisés** : comptes fictifs distincts propriétaire, gestionnaire, caissier, livreur et acheteur ; invitations via Mailpit local et adresses example.test. Toute création de compte, boutique, commande, livraison ou vente persistante demande une autorisation explicite. Aucun compte administrateur créé par l'audit.
4. **Parcours acheteur** : connexion réelle, panier conservé et badge, choix de variantes, changement de boutique confirmé, checkout invité/connecté, refus d'identifiant modifié, confirmation privée et fenêtre invité.
5. **Parcours commerçant et employés** : invitation/expiration/réémission, activation boutique, rôle exact, suspension/refus immédiat, accès interboutiques et rôle interdit, historique administratif ; contrôler les liens réels plutôt que les seuls rendus isolés.
6. **POS** : ouverture de caisse, vente anonyme/nominative, devis périmé, double clic/replay, vente impayée puis espèces confirmées sous rôle autorisé, stock décrémenté une seule fois, clôture/solde/mouvements selon la matrice décidée. Aucune transaction Mobile Money réelle.
7. **Livraison** : après autorisation de zone/frais/configuration et missions fictives, affectation, récupération, refus de remise impayée, confirmation financière par un autre acteur autorisé, remise, échec et retour propriétaire. Ne pas convertir des commandes PICKUP existantes.
8. **Documents et visuel** : PDF A4 vendeur réel et snapshots après changement du catalogue, tickets 58/80 et imprimante si disponible, autorisations de téléchargement, desktop/mobile et FR/EN, notamment badge client restauré. Ne pas conserver de coordonnées ou de jetons dans les captures.
9. **Concurrence et exploitation** : essais multi-connexion sur base jetable de même moteur, Redis partagé si multi-instance, limites de durée/locks, journalisation serveur sans secrets. Expiration et fournisseurs de paiement restent désactivés jusqu'à autorisation et validation séparées.
10. **Clôture** : preuves des contrôles réellement exécutés, bilan des lignes DEMO créées/modifiées, inventaire Git précis et approbation avant indexation/commit/push.

Aucun accès MySQL, aucune lecture ou écriture SQLite DEMO, migration persistante, seeder persistant, paiement réel, compte DEMO, invitation externe, commit, push, fusion ou changement de branche n'est effectué pendant 20A.

## Correction RBAC post-audit — mouvements manuels réservés au propriétaire

Décision confirmée : `pos.cash_movements.manage` est réservée exclusivement au rôle unique STORE_OWNER, actif, authentifié sur web, dans sa boutique active et avec la permission explicite. Aucun rôle supplémentaire, même futur ou inconnu, n'est accepté. Le compte propriétaire peut conserver son identité acheteur distincte ; celle-ci n'accorde aucun droit professionnel.

Les deux échecs initiaux provenaient de listes exactes historiques devenues incompatibles avec les migrations POS : le test caissier attendait trois permissions au lieu des sept existantes ; le gestionnaire recevait aussi des droits POS/documentaires et le droit de mouvements manuels contesté. Les listes restent des égalités exactes : les droits opérationnels existants sont explicités, et les mouvements manuels sont ajoutés aux interdictions du gestionnaire. Aucun contrôle négatif n'est retiré pour obtenir PASS.

PosAccess centralise le contrôle propriétaire dans `canManageCashMovements` et `authorizeCashMovements`. RecordPosCashMovement et les deux endpoints GET/POST du PosCashMovementController l'utilisent, avant validation/écriture pour le POST. L'ancienne attribution persistante à un gestionnaire ou caissier ne permet donc plus de contourner la règle. La consultation utilise la même permission historique et reste elle aussi réservée au propriétaire ; aucune permission de lecture déléguée n'est inventée. Le formulaire POS utilise ce même contrôle.

RolePermissionSeeder conserve la permission pour STORE_OWNER seulement. Les permissions ventes POS, sessions et encaissements existantes ne sont pas modifiées. En particulier, STORE_MANAGER ne reçoit pas implicitement payments.confirm ; ses ventes non payées et ses autres droits opérationnels restent ceux existants. Les règles financières, calculs de solde et mouvements de stock sont conservés.

La migration historique `2026_10_18_135637_add_pos_cash_movement_permission.php`, suivie dans le commit `12390dd`, reste inchangée. La nouvelle migration `2026_10_24_142445_restrict_manual_pos_cash_movements_to_store_owner.php` retire uniquement les associations de cette permission aux rôles non STORE_OWNER, y compris inconnus, et garantit l'association propriétaire si ce rôle existe. Elle ne modifie ni utilisateurs, ni associations utilisateur/rôle, ni autres permissions, ni écritures de caisse. Elle est transactionnelle et réexécutable sans duplication. Son down ne restaure pas les droits révoqués. Elle n'est exécutée que dans les tests SQLite :memory: ; son application persistante reste à autoriser séparément, ainsi que toute autre migration en attente. Aucun seeder global persistant n'est nécessaire ni exécuté.

Résultats ciblés : **84 tests réussis, 392 assertions**, SQLite :memory: après vérification de phpunit.xml et de l'absence de cache de configuration. Lot : StoreManagerRbacAuditTest, CashierRbacAuditTest, RecordPosCashMovementTest, PosCashMovementInfrastructureTest, PosInterfaceTest, CreatePosOrderIntegrationTest, CreatePosCashPaymentTest, ConfirmPosPaymentSecurityTest, ClosePosSessionTest et CalculatePosCashBalanceTest.

Les tests couvrent propriétaire autorisé, gestionnaire/caissier avec ancienne permission refusés, cumul propriétaire avec chaque autre rôle connu ou futur refusé, appels HTTP directs, propriétaire inactif, boutique suspendue, session étrangère, maintien des soldes et paiements, migration corrective idempotente et conservation des autres associations. Les tests de la migration historique restent des tests de son comportement historique ; un test distinct vérifie son correctif sur un état ancien.

Fichiers de la correction : app/Support/PosAccess.php, app/Actions/Pos/RecordPosCashMovement.php, app/Http/Controllers/PosCashMovementController.php, database/seeders/RolePermissionSeeder.php, nouvelle migration corrective, resources/views/professional/pos/index.blade.php, tests/Feature/StoreManagerRbacAuditTest.php, tests/Feature/CashierRbacAuditTest.php, tests/Feature/RecordPosCashMovementTest.php et ce document.
