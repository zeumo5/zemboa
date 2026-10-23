# Phase 17.3A — contrat checkout et décisions préalables

Suite technique : voir [CHECKOUT_PHASE17_3B_VALIDATION.md](CHECKOUT_PHASE17_3B_VALIDATION.md)
pour l'expiration préparée désactivée et les plans de validation sans accès aux bases persistantes.

État source : branche feat/customer-auth, HEAD
8bcddf47fd7fe0e21cde5fe0db62c4631add994d. Inspection ciblée uniquement.
La première partie conserve le diagnostic préalable ; voir la section « Suite après
validation » pour le contrat désormais implémenté et les limites de validation.
Aucune migration persistante ni création de commande DEMO n'est autorisée ici.

## Backend réellement disponible

| Élément | Fonctionnement constaté | Source |
| --- | --- | --- |
| Entrée JSON | POST /shops/{shopSlug}/checkout, sans authentification buyer exigée | routes/web.php, PublicCheckoutController |
| Boutique publique | Slug résolu uniquement pour un Store ACTIVE, contexte tenant établi | SetPublicTenantContext, TenantContext |
| Panier | Session unique avec store_id, variantes/quantités et révision ; un autre tenant ne récupère pas ses articles | CartService |
| Précontrôle | Panier non vide, summary.can_checkout ; catégorie/produit/variante actifs et stock disponible | PublicCheckoutController, CartService |
| Création | Transaction, fusion des variantes répétées, validation des quantités, prix recalculés, snapshots, PENDING/UNPAID | CreateOrder, OrderPriceCalculator |
| Client boutique | Téléphone normalisé et réutilisation dans le tenant ; profil existant non écrasé | FindOrCreateCustomer, Customer |
| Livraison | Paramètres delivery_enabled/pickup_enabled si présents ; zone ACTIVE du tenant, frais lus en base et adresse obligatoire | CreateOrder, StoreSetting, DeliveryZone |
| Stock | StockLevel verrouillé, availableQuantity vérifié, reserved_quantity augmenté ; physical_quantity inchangé au checkout | CreateStockReservation |
| Résultat | JSON 201 avec identifiant/numéro/statuts/montants ; clear du panier après succès | PublicCheckoutController |
| Expiration | Actions présentes, protections des commandes payées/partiellement payées et des colis récupérés | ExpireOrder, ExpireStockReservation |
| Paiement | Conversion des réservations au paiement intégral ; paiement partiel conserve les réservations | FinalizeOrderPayment, ConvertStockReservation |

Les modèles marchands utilisent BelongsToStore : filtres globaux et store_id fixé à
la création. OrderItem n'a pas ce trait : ses accès doivent passer par une commande
autorisée, jamais par un identifiant de ligne fourni seul par le navigateur.
OrderController réserve actuellement /orders aux professionnels autorisés de leur
boutique ; aucune route d'historique acheteur ne doit réutiliser cette autorisation.

routes/console.php ne programme pas l'expiration. Les actions existent, mais une
expiration automatique ne peut pas être annoncée comme opérationnelle. Son ordonnanceur
devra être conçu et validé séparément, sans lancer ces actions pendant cette préparation.

## Deux prérequis avant confirmation HTML

### Propriété des nouvelles commandes — proposition à valider

orders ne contient ni buyer_user_id ni autre relation vers le User acheteur.
customer_id désigne une fiche commerciale locale, pas une preuve de propriété.
Le checkout JSON existant est invité et n'enregistre pas le guard buyer.

Proposition minimale : ajouter orders.buyer_user_id nullable, FK vers users avec
suppression restreinte, et index de consultation adapté. Renseigner uniquement
l'identité validée par le guard buyer côté serveur au moment de la nouvelle commande,
dans la même transaction que CreateOrder. Ne jamais accepter cet identifiant dans
les données contrôlées par le navigateur. Aucun backfill des commandes historiques.
Ce champ ne doit pas devenir librement mass assignable.

Customer reste la fiche du commerçant. Sa réutilisation par FindOrCreateCustomer ne
confère aucun accès à ses autres commandes et ne crée aucune relation User/Customer.
Un écran acheteur futur devra vérifier simultanément buyer_user_id et boutique ;
changer de téléphone ou fournir celui d'un tiers ne transfère aucun historique.
Les professionnels conservent leurs règles de consultation tenant/RBAC actuelles.

Choix recommandé pour la première interface : exiger un buyer authentifié pour
confirmer une commande HTML. Préremplir ses coordonnées sans les enregistrer dans
User lors du checkout ; les éventuels compléments sont des coordonnées de commande.
Conserver le contrat JSON invité existant, sans lui attribuer une propriété globale.
Ne pas modifier silencieusement cette API pour imposer la connexion.

Alternative à décider explicitement : exposer aussi le checkout invité en HTML.
Cela demande une preuve d'accès propre à la commande (capacité opaque, expiration,
stockage protégé), sans récupération par téléphone/e-mail et sans rattachement
rétroactif automatique lors d'une connexion. Ce mécanisme n'existe pas actuellement.

### Idempotence durable — proposition à valider

Le verrou Laravel couvre désormais toutes les requêtes web avant lecture de session,
avec UseCartSessionLocks ; le checkout conserve aussi sa déclaration block(30, 10).
Vérifier lors de l'intégration la durée effective de ce verrou de route par rapport
à la durée globale et au temps maximal de traitement ; ne pas empiler un second verrou.
Ce verrou sérialise une session mais ne rend pas atomiques SQL et sauvegarde de session.
Un succès SQL suivi d'une perte de réponse/session peut donc laisser réessayer un ancien
panier. Le numéro de commande aléatoire unique n'identifie pas la même intention d'achat.

Proposition : compléter orders par des métadonnées checkout nullables pour les anciennes
commandes/POS : empreinte de clé opaque, empreinte du contenu confirmé et liaison à
l'acteur autorisé (buyer ou session invitée). Définir un index UNIQUE sur
(store_id, checkout_key_hash). Les noms définitifs seront fixés lors de l'implémentation.
Ne pas considérer la clé d'idempotence seule comme une autorisation de consultation.

Le serveur émet une intention liée au panier/révision, au tenant et à l'acteur ; la
confirmation valide ses coordonnées et ses choix. La création existante et l'inscription
de ces métadonnées doivent partager une transaction SQL. Une collision UNIQUE doit
annuler toutes les écritures de la tentative perdante, y compris les réservations,
puis permettre une relecture autorisée de la commande gagnante après fin de transaction.
Un même jeton avec contenu différent doit être refusé (409), pas ignoré silencieusement.

Une répétition autorisée après succès doit retrouver le résultat avant le rejet
« panier vide », sans nouvelle commande/réservation. Le contrat d'intention doit garder
la preuve du panier original même après clear ; comparer seulement au panier courant
ne suffit pas. Une rotation de session/connexion doit invalider ou renouveler explicitement
une intention invitée, jamais transférer implicitement sa propriété.
Un panier nouvellement constitué après succès exige une nouvelle intention.

Le bouton désactivé pendant l'envoi reste une aide UX, pas une garantie contre doublons.
Cette proposition doit protéger aussi le POST JSON invité existant, pas seulement le
nouvel écran. Elle ne remplace ni CreateOrder ni les verrous/règles de stock existants.

## Points à traiter dans l'intégration autorisée

- Les longueurs divergent : le contrôleur accepte customer_email jusqu'à 255, mais
  orders.customer_email et customers.email sont limités à 150 dans les migrations.
  Harmoniser la validation à 150 sans tronquer silencieusement.
- customer_phone n'est validé que comme string/max:30 avant le normaliseur ; ses
  InvalidArgumentException ne sont pas traduites en erreurs de formulaire ici.
  Réutiliser la validation stricte existante et rendre une erreur FR/EN contrôlée.
- Si StoreSetting manque, CreateOrder autorise les deux modes par défaut. L'interface
  demandée doit échouer fermée sur les choix non explicitement configurés, sans changer
  implicitement les règles des autres appels métier. Une boutique sans aucun mode actif
  doit rester non confirmable.
- Ne pas annoncer livraison sans zone active disponible ; recalculer ses frais avec
  OrderPriceCalculator côté serveur, jamais à partir d'un total posté.
- allow_reservations n'est pas contrôlé dans CreateOrder : ne pas retirer arbitrairement
  la réservation technique. Clarifier ce paramètre si une offre de réservation est exposée.
- Les acomptes sont contrôlés dans les actions de paiement, pas dans la création d'ordre.
  Ne pas créer de bouton public « payé » ni d'encaissement fictif pendant ce travail.

## Schéma et déploiement

Les migrations existantes is_buyer et identité téléphonique ne sont pas présumées
appliquées dans MySQL ou DEMO. Préserver BuyerIdentity::assertSchemaReady et les 503.
Toute nouvelle migration de propriété/idempotence exige un accord sur le contrat ci-dessus.
Préparer et tester seulement en SQLite :memory: après accord ; ne modifier aucune
migration historique ni appliquer sur une base persistante.
La compatibilité MySQL/MariaDB de l'identité téléphonique nécessite toujours une base
jetable de même version, moteur, collation et sql_mode, avec autorisation distincte.

## Validation à effectuer après accord

Tests isolés : parcours HTML/JSON FR/EN, livraison/retrait actifs/inactifs, zones
interboutiques, prix falsifiés ignorés, téléphone invalide, e-mail trop long, stocks
insuffisants, transaction annulée, panier conservé en échec, succès UNPAID avec réservations
sans décrément physique, buyer B refusé sur la commande de A, absence de récupération
historique par coordonnées, répétition/double clic/réponse perdue/concurrence et conflit
de contenu, panier étranger/suspendu, schéma absent et session/CSRF.

## Livré dans cette préparation

Uniquement ce document de diagnostic et de proposition. Aucun endpoint, formulaire,
bouton de confirmation, migration ou mécanisme d'identité/idempotence ajouté avant
validation des deux prérequis. Aucun accès MySQL ni modification SQLite DEMO.

Vérifications de cette préparation :
- phpunit.xml contrôlé et bootstrap/cache/config.php absent ; connexion de test
  forcée sqlite/:memory:, DB_URL vide, sessions/cache/mail/queue isolés pour les tests.
- Sept tests existants ciblés de PublicCartTest réussis, 49 assertions : création
  invitée FR/EN, réservations sans paiement, échec conservant le panier, panier vide
  FR/EN et messages de zone/articles indisponibles. Ils ne prouvent ni la propriété
  acheteur ni l'idempotence durable, qui restent à implémenter après validation.
- Lint PHP réussi sur 17 fichiers inspectés ; git diff --check réussi.
- Aucun changement frontend : build non relancé. Aucun scénario Chrome ni commande
  réelle sur SQLite DEMO. Index Git vide, aucun commit/push/changement de branche.

## Suite après validation — 23 octobre 2026

Le propriétaire a autorisé la préparation des migrations et leurs tests exclusivement
en mémoire, avec checkout invité également en HTML. Les sections précédentes décrivent
la préparation historique ; les choix et changements suivants sont désormais codés.

### Parcours raccordé

- GET/POST /boutiques/{shopSlug}/commande : récapitulatif, choix de service/zone par
  formulaire GET, frais et total recalculés par Laravel, coordonnées préremplies pour
  le guard buyer, confirmation POST avec CSRF. Sans service explicitement configuré,
  sans zone active pour livraison ou avec panier invalide, aucun formulaire de confirmation.
- GET /boutiques/{shopSlug}/commande/{orderId}/confirmation : reçu non transactionnel,
  montants et état réels. Aucun paiement ni bouton de simulation. Cache privé no-store.
- GET /shops/{shopSlug}/checkout : préparation JSON et jeton signé ; POST JSON existant
  conservé, avec confirmation_url ajoutée à la réponse. Ses anciens champs restent acceptés.
- Le panier contient un lien vers la préparation ; ouvrir cette page ne crée ni commande,
  réservation, compte ou fiche Customer. Seule la session est utilisée pour l'intention.
- L'option de livraison/retrait est actualisée par un formulaire GET distinct, sans JS
  obligatoire ; elle n'est pas modifiée dans le formulaire final. La disponibilité et les
  montants sont recontrôlés à la confirmation, jamais acceptés depuis le navigateur.

### Propriété et preuve d'accès invité

Migration nouvelle : 2026_10_23_000000_add_buyer_checkout_identity_to_orders_table.php.
Elle ajoute buyer_user_id (nullable, FK users, suppression restreinte), les empreintes
checkout_key_hash / checkout_request_hash / checkout_actor_hash et checkout_expires_at.
Index UNIQUE (store_id, checkout_key_hash), index (buyer_user_id, store_id).
Les anciennes commandes et le POS gardent NULL ; aucun backfill, aucun Customer/User lié.
Les empreintes sont cachées lors de la sérialisation et ne sont pas mass assignables.
Le down refuse de retirer ces protections si des commandes checkout sont présentes.

L'acteur est un HMAC secret serveur : buyer:id authentifié, ou guest:identifiant de
session Laravel. L'intention signée contient tenant, empreinte d'acteur, empreinte de
panier/révision et expiration à deux heures ; aucune coordonnée privée n'y est encodée.
Le jeton n'est pas placé dans l'URL et n'autorise pas seul la lecture d'une commande.
Le reçu exige la même identité buyer et buyer_user_id, ou la session invitée d'origine
avec empreinte identique et délai non expiré. Aucun accès invité récupérable par téléphone,
e-mail ou numéro de commande. Perte/rotation de session : accès invité perdu. Connexion
après commande invitée : pas de transfert de propriété ni de récupération automatique.
Les jetons invités antérieurs à une connexion doivent être renouvelés en rechargeant.

### Idempotence et transactions

PublicCheckout orchestre uniquement CreateOrder : aucun second moteur de commande.
La clé durable dérive du tenant, de l'acteur et du panier/révision ; une nouvelle mutation
du panier produit une nouvelle intention. Le contenu métier normalisé a sa propre empreinte.
Un rejeu autorisé et identique retrouve l'ordre avant les contrôles du panier vide/stock ;
un contenu différent est refusé avec 409. Une intention périmée, étrangère ou altérée est
refusée avec 419. Le rejeu ne vide un panier résiduel que si sa révision correspond encore.
Une collision SQL UNIQUE annule la tentative entière avant relecture du gagnant.
Commande, articles, réservations et métadonnées appartiennent à la même transaction.
Le panier est vidé après succès SQL ; perdre la sauvegarde de session ne permet donc pas
de créer une deuxième commande à partir de l'ancienne révision.

Pour les anciens clients JSON sans jeton, le serveur le dérive de leur panier de session
déjà existant ; le même panier résiduel retrouve la même clé durable. Un client doit conserver
sa session pour toute récupération. L'absence de session ne peut servir à réclamer un ordre.
Le verrou web natif global reste le seul verrou de session ; l'override de route à 30 s a
été retiré afin d'utiliser la durée globale existante. Les verrous SQL de stock restent inchangés.

La validation HTTP limite maintenant les e-mails de commande à 150 caractères et normalise
strictement les mobiles ; les coordonnées ne modifient pas le profil User. Le JSON conserve
le comportement historique des services lorsque StoreSetting manque ; le HTML exige une
configuration explicite. Lorsqu'un paramètre existe, CreateOrder impose ses choix à tous.

### Déploiement et limites

Aucune des migrations n'est appliquée sur une base persistante par cette intervention.
Préconditions : vérifier séparément is_buyer, identité téléphonique puis schéma checkout,
avec autorisations, sauvegardes et validation MySQL/MariaDB sur serveur jetable identique.
Les gardes 503 vérifient le schéma et ses index avant tout checkout, y compris invité.
Ne pas lancer tools/demo.php migrate sans examiner toutes ses migrations en attente.

L'expiration automatique des stocks reste non programmée ; aucune promesse d'exécution
automatique ajoutée. Le reçu est un accusé de création, pas un historique complet ni une
facture. Aucun compte invité, SMS, récupération par coordonnées, paiement ou acompte créé.
Le comportement à deux connexions SQL réellement concurrentes devra aussi être validé
sur le serveur MySQL/MariaDB jetable autorisé ; les tests mémoire ne le certifient pas.
Chrome sur DEMO reste à valider après autorisation des migrations persistantes nécessaires.

### Résultats de l'implémentation

- Lot ciblé : 132 tests PASS, 1 047 assertions (checkout, panier, authentification buyer,
  commandes, paramètres livraison/retrait, prix et verrouillage/concurrence des sessions).
- Puis trois nouveaux tests PASS, 13 assertions : CSRF HTML/JSON, refus de récupération
  historique par contacts et expiration/isolation du reçu invité. Total : 135 tests,
  1 060 assertions en deux lots, exclusivement SQLite :memory: après vérification de
  phpunit.xml et de l'absence de cache de configuration. Le nouveau fichier checkout
  contient 13 tests. Aucun test MySQL, aucun seeder.
- Tests de rejeu d'une ancienne session/panier, conflit de contenu, préservation d'un
  panier nouveau et annulation transactionnelle d'une collision UNIQUE réussis. Ils
  ne constituent pas un essai à deux processus MySQL concurrents.
- Lint PHP et git diff --check réussis. Build Vite 8.3.4 réussi en 11,06 s ; avertissements
  non bloquants fontaine optionnel et durée des callbacks. Aucune dépendance ajoutée.
- Aucun Chrome exécuté, aucune migration ou donnée modifiée dans MySQL/SQLite DEMO.
  Aucun commit, push, indexation, changement de branche ni fichier .env modifié.
