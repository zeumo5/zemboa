# Phase 17.2A — raccordement du panier public

## Contrat conservé

`PublicCartController` et `CartService` restent les seuls points de mutation.
Les routes JSON `/shops/{shopSlug}/cart` et `/cart/items[/{variantId}]`
restent inchangées. Le panier est stocké dans `zemboa.cart` en session :
`store_id` et quantités par identifiant de variante, sans prix navigateur.
Invités et acheteurs utilisent le même panier de session ; la connexion acheteur
le conserve. Ce n'est pas un panier enregistré par utilisateur ou synchronisé entre appareils.
Aucun lien avec Customer n'est créé.

Les modèles tenant, les boutiques actives et les règles de publication sont réutilisés.
L'ajout et la modification contrôlent le stock disponible (physique moins réservé),
les statuts de variante, produit et catégorie. Ils ne réservent aucun stock.
Les prix et promotions sont recalculés par le serveur, y compris à la consultation.
Une quantité devenue indisponible est signalée ; un article retiré peut encore être supprimé.

Une autre boutique voit un panier vide, mais ne peut pas remplacer le panier existant.
L'interface indique son origine et propose un lien vers le panier d'origine si cette
boutique est encore publique. Le retrait de tous ses articles permet ensuite de changer.
Aucun bouton de vidage global n'est exposé : le clear JSON existant efface la clé globale,
indépendamment de la boutique. Le cas d'un panier appartenant à une boutique suspendue
reste à arbitrer (abandon explicite et sécurisé à définir) ; aucun abandon automatique.

## Interface

- Fiche produit : choix explicite de variante, quantité, ajout JSON avec CSRF et retour accessible.
- Page GET `/boutiques/{shopSlug}/panier`, nom `marketplace.shops.cart`, sous les middlewares publics existants.
- Le contrôleur HTML fournit uniquement l'identité de boutique et l'éventuel lien vers le panier d'origine.
- Consultation, prix FCFA, disponibilité, modification et retrait via le backend JSON existant.
- Lien permanent dans le header marchand ; lien marketplace vers le panier actif de session.
- FR/EN, champs libellés, annonces aria-live, requêtes en cours protégées contre le double clic.
- Les données JSON sont rendues par textContent, sans insertion HTML de noms produits.
- Aucun checkout, paiement, commande, réservation ou nouveau modèle.

## Vérification

Configuration PHPUnit inspectée : SQLite :memory:, DB_URL vide et cache de configuration absent.
Paramètres imposés au processus PHP Laragon 8.3.30 ; aucun test sur MySQL ou SQLite DEMO.
PublicCartTest, BuyerAuthenticationTest et MarketplacePagesTest : 42 tests, 359 assertions réussis
avant le contrôle complémentaire de boutique suspendue. Ce test a ensuite été
rejoué avec succès : 1 test, 8 assertions. Son assertion de langue a été corrigée
pour demander explicitement le français (la langue applicative est restaurée après la requête).
Lint PHP/JS et git diff --check valides. Build Vite réussi ; avertissements optionnels
fontaine et temps des callbacks de compilation, sans nouvelle dépendance installée.

La validation visuelle réelle dans Chrome (desktop/mobile), les appels réseau réels,
la présentation des erreurs de session expirée et les interactions multi-onglets
restent à vérifier. Aucun test navigateur n'a écrit dans SQLite DEMO pour cette intervention.

## Validation Chrome 17.2B — 22 octobre 2026

Google Chrome installé, profils temporaires isolés, origine 127.0.0.1:8016 seulement.
Parcours réels à 1440 et 390 px, en FR et EN : ajout, variantes distinctes,
confirmation, liens marchand/marketplace, quantités au clavier, disponibilité,
prix et total comparés au JSON, refus du mélange de boutiques, retour au panier
d'origine, retrait et état vide. Aucun débordement horizontal ni erreur JavaScript.
Les HTTP 422 de stock et de changement de boutique sont provoqués volontairement.
Les fichiers statiques réels sont servis depuis public par l'outil pour éviter les
délais du serveur PHP de développement ; HTML et JSON passent par Laravel DEMO.

Correction mineure : le message de mise à jour ne masque plus l'état vide après le
dernier retrait. Contrôlé dans les quatre combinaisons langue/format, avec contrôle
ciblé sur ordinateur après recompilation. Vite : succès en 12,61 s, avertissements
optionnels fontaine et temps de compilation. Aucun paquet installé.

Comparaison logique en lecture seule de toutes les tables SQLite DEMO avant/après :
aucune donnée persistante modifiée, aucune réservation ajoutée, intégrité et clés
étrangères valides. Les sessions/langues/paniers sont des fichiers locaux isolés.

Contrôles complémentaires PHPUnit exclusivement sur SQLite :memory:, cache absent :
2 tests / 24 assertions réussis (suspension et conservation à la connexion).
La connexion réelle dans Chrome reste à vérifier avec un compte DEMO existant dont
les identifiants sont disponibles. Le mot de passe aléatoire du précédent audit
n'avait pas été conservé ; aucun compte créé, activé ou réinitialisé ici.

Point important confirmé par un diagnostic local en mémoire (1 test / 5 assertions) :
DELETE /shops/{autreBoutiqueActive}/cart efface également le panier d'une boutique
suspendue. CartService::clear oublie la clé globale sans editableItems, contrairement
aux mutations d'articles. Les routes de la boutique suspendue répondent bien 404 et
l'interface n'expose aucun vidage global. La garantie d'interdiction de toute mutation
n'est donc pas complète au niveau JSON. Aucune correction métier imposée dans cet audit.
À autoriser séparément : vérifier le propriétaire via le garde existant avant clear,
et ajouter le test de non-régression correspondant. Le diagnostic local ne constitue
pas un comportement à pérenniser dans les tests applicatifs.

Rapports, captures et outillage de cet audit sont locaux et ignorés sous storage/demo/.
Aucun commit, push, checkout DEMO, paiement ou développement de phase 17.3.

## Finalisation sécurisée autorisée — phase 17.2

CartService::clear appelle désormais editableItems avant de supprimer la session.
Le scénario historique qui permettait le vidage depuis B a été corrigé : B reçoit
422 sans perdre les articles, puis seul A peut vider son panier actif. Une boutique
suspendue reste inaccessible et son panier ne peut plus être effacé depuis B.
La réserve backend constatée en 17.2B est donc corrigée ; aucun abandon automatique ajouté.

Les cartes globales et marchandes partagent cart-card-action : une variante active
unique et disponible permet l'ajout direct d'une unité ; plusieurs variantes actives
conduisent à la fiche pour choisir ; aucune variante disponible empêche l'ajout.
publicProducts joint une synthèse groupée variantes/stock, avec jointures store_id.
Seuls les compteurs et l'identifiant minimal nécessaires sont exposés aux cartes.
Aucune requête supplémentaire par carte ; le test compare un catalogue de 1 et 6 produits.
Les détails de variantes marchands utilisent toujours leur chargement groupé existant.

L'icône panier est visible dans les deux headers, même sur mobile. Le badge indique
la somme des unités de la session. L'API existante fournit aussi l'identité publique
de la boutique et son URL panier, sans modifier ses champs historiques. Après succès,
cart.js actualise compteur, lien et libellé accessible ; un panier étranger porte
la mention « Autre boutique » et conserve son lien d'origine. Un GET vide d'une autre
boutique ne remet pas le compteur existant à zéro. Le rechargement utilise la session
serveur ; un test vérifie également le badge après connexion acheteur.
L'animation d'ajout est désactivée lorsque prefers-reduced-motion est demandé.
Aucune modification de l'authentification ou de son minimum de 12 caractères.

Validation : PublicCartTest, MarketplacePagesTest et BuyerAuthenticationTest,
45 tests / 401 assertions réussis. Contrôles complémentaires : test des cartes sur
la comparaison SQL finale (1 test / 14 assertions), puis badge après connexion
(1 test / 8 assertions), réussis. Tous sur SQLite :memory:, cache absent et paramètres
imposés au processus PHP Laragon 8.3.30. Aucun test MySQL ou sur SQLite DEMO.
Build Vite réussi en 33,27 s ; avertissements non bloquants fontaine et temps des callbacks.

Chrome réel, profil temporaire, 1440 et 390 px en FR/EN : cartes à options, indisponibles,
ajouts depuis les fiches, badge 3 unités pour 2 lignes, rechargement, retrait jusqu'à 0,
propriétaire étranger visible et vidage étranger refusé en 422. Aucun débordement
horizontal ni erreur JavaScript ; animation observée en mode normal et absente en mode réduit.
Les données DEMO présentent 12 cartes à options et 4 indisponibles, sans carte à variante
unique vendable : l'ajout direct sur carte n'est pas certifié dans Chrome, mais sa vue
et sa route sont vérifiées par les tests isolés. Aucun produit DEMO modifié pour ce cas.
La connexion Chrome avec le compte acheteur existant reste limitée par l'absence de
ses identifiants conservés ; sa conservation de session et son badge sont testés en mémoire.

Empreinte logique de toutes les tables SQLite DEMO avant/après identique, stock et
réservations inchangés, intégrité et clés étrangères valides. Rapports et captures
locaux sous storage/demo/audits/cart-final-*, exclus de Git. Aucun commit ni push.

## Vidage confirmé et libellés de variantes

Le bouton de vidage réutilise DELETE public.shops.cart.clear, son contrôle de propriété
et le même client JSON/CSRF. Il reste caché pendant le chargement, sur un panier vide
et dans un contexte étranger. Une dialog native affiche une confirmation avec focus
initial sur Annuler, annulation par bouton ou Échap et validation accessible au clavier.
Les doubles soumissions sont bloquées ; compteur, total et articles ne changent qu'après
réponse serveur réussie. Un refus laisse le panier consultable et permet de réessayer.

Contrôle ciblé : 32 tests / 274 assertions, SQLite :memory: exclusivement, configuration
et absence de cache vérifiées. Syntaxe PHP/JS et git diff --check réussis.
Vite : succès en 4,14 s, avertissements optionnels fontaine et temps des callbacks.
Chrome 1440/390 px, FR/EN : annulation sans DELETE, Échap, focus/Tab/Entrée, un seul
DELETE malgré une seconde pression Entrée, compteur à zéro et total effacé après succès.
Une erreur HTTP 503 a été injectée dans le navigateur (sans appel serveur de vidage)
pour vérifier message, rétention du panier et possibilité de réessayer. Aucun débordement
horizontal ni erreur JavaScript sur les quatre parcours. Les propres refus interboutiques
et boutiques suspendues sont couverts par les tests backend existants, non affaiblis.

La comparaison logique de toutes les tables SQLite DEMO avant/après est identique,
avec intégrité et clés étrangères valides ; aucun stock, paiement ou commande modifié.
Les seules écritures effectives des parcours sont les fichiers de sessions isolées.

Les libellés publics utilisent maintenant « nom d'attribut: valeur », avec chargement
groupé des relations attributeValues.attribute et fallback SKU lorsqu'aucune valeur
n'est présente. Aucun modèle, route d'association ou règle de variantes supplémentaire.
L'analyse et les quatre jeux DEMO proposés sont dans docs/DEMO_VARIANTS_PLAN.md.
Aucun seeder ni donnée DEMO modifié ; la transition proposée exige une nouvelle validation.
Rapport et 13 captures locaux : storage/demo/audits/cart-final-2026-10-22T05-28-47-981Z/.

## Finalisation UX : quantites, produits distincts et retours

Cette section remplace la semantique historique du badge en unites decrite plus haut.
Le backend JSON existant reste unique : POST ajoute, PATCH modifie et DELETE retire
ou vide avec le controle de propriete existant. Aucun changement de schema.

- Chaque detail expose product_id (nullable si la relation ne peut plus etre etablie).
- counts distingue distinct_products, units et unidentified_lines ; deux variantes
  d'un produit comptent une fois. Les lignes inactives identifiables restent comptees.
- Une variante disparue reste dans la session : aucune identite produit n'est inventee.
  Le badge affiche le nombre connu suivi de + et une explication accessible ; le
  recapitulatif signale les lignes non identifiables et conserve leur nombre d'unites.
- La projection SSR des badges fait une requete groupee avec store_id explicite sur
  produits ET variantes, y compris hors d'une route marchande. Le JSON confirme
  toujours le compteur apres mutation ; aucune confiance dans un compteur local de clics.
- products_subtotal est calcule par OrderPriceCalculator sans frais de livraison,
  avec les reductions produits existantes. totals reste compatible et inchange.

La fiche distingue la quantite a ajouter (minimum 1) de la quantite deja presente,
avec modification et retrait via les actions existantes. Les pas -/+ ne remplacent
pas la validation serveur. Le recapitulatif desktop est sticky et devient une barre
mobile fixe ; ResizeObserver reserve sa hauteur, en incluant safe-area-inset-bottom.

Une seule notification affiche les succes pendant 3,8 secondes et conserve les
 erreurs jusqu'a fermeture ou nouvel essai. L'expiration est suspendue si le bouton
 de fermeture a le focus. Aucun focus n'est deplace vers une confirmation de succes.
Les retours utilisent une liste de chemins publics autorises de meme origine, avec
seulement q/category/page/sort ; aucune URL d'authentification ni destination externe.
Une memoire bornee de navigation par onglet conserve les origines et le defilement.
Sans historique exploitable ou stockage disponible, le catalogue marchand reste le lien.

Validation Chrome ciblee : Chrome installe, profil temporaire, 1440/390 px, FR/EN.
Deux variantes = un produit, quantites fiche/panier, ajout, retrait, vidage, rechargement,
retour avec recherche conservee, sous-total serveur et recapitulatif sticky/fixe valides.
Controle complementaire desktop : erreur de stock 422 persistante et refermable,
reprise apres erreur, notification conservee lorsqu'elle a le focus, aller-retour
panier/fiche/catalogue et restauration de 200 px de defilement, retrait depuis la fiche.
La connexion acheteur n'a pas ete rejouee dans Chrome faute d'identifiants conserves ;
la conservation du panier et de ses compteurs apres connexion est couverte en memoire.

Captures et resultats locaux exclus de Git :
- storage/demo/audits/cart-final-2026-10-22T06-31-19-271Z/ (16 PNG).
- storage/demo/audits/cart-final-2026-10-22T06-35-32-615Z/ (controle complementaire).

Aucune migration, seeder, authentification ou permission professionnelle modifiee.
Aucun checkout ni paiement. Empreinte logique SQLite DEMO identique avant/apres,
reserves et stocks inchanges, integrite et cles etrangeres valides. MySQL non utilise.

Verifications finales : 36 tests passes, 322 assertions, exclusivement SQLite :memory:,
phpunit.xml verifie et aucun cache de configuration present. Tests panier, marketplace
et attributs cibles. Huit assertions JS supplementaires valident la liste de destinations
publiques, le refus des origines externes/identifiants URL et le retrait de parametres
non autorises. Lint PHP/JS et git diff --check reussis.
Dernier npm run build : succes Vite 8.3.4 en 4,16 s ; avertissements non bloquants
fontaine optionnel et duree des callbacks Tailwind. Aucune dependance ajoutee.

Ouverture en nouvel onglet egalement validee dans Chrome : une provenance publique
interne conserve sa recherche ; une provenance externe est refusee au profit du
catalogue marchand. Resultats : storage/demo/audits/cart-final-2026-10-22T06-43-13-871Z/.

## Enregistrement automatique et changement explicite de boutique

Les contrôles de la page panier réutilisent PATCH /shops/{shopSlug}/cart/items/{variantId}.
Les clics +/− sont regroupés pendant 220 ms, la saisie manuelle pendant 650 ms ;
Entrée, sortie du champ et bouton Modifier valident immédiatement. Une quantité
inchangée ne provoque pas de PATCH. Le minimum reste 1 et seul Retirer supprime.
Pendant une mutation, les contrôles sont désactivés : une seule mutation locale est
active. Les réponses de lecture anciennes ne remplacent pas une réponse plus récente.
Les prix, sous-totaux, unités et produits distincts viennent toujours du serveur.
En cas de refus ou d'erreur réseau, le champ reprend la dernière quantité confirmée ;
le message invite à réessayer/recharger. Cela n'est pas un rollback serveur d'une
requête dont la réponse aurait été perdue. Le bouton Modifier reste disponible.
Le focus revient au contrôle correspondant après reconstruction des lignes.

Le nouveau POST /shops/{shopSlug}/cart/switch est exclusivement un abandon confirmé
du panier de session, sans ajout implicite, sans commande, sans réservation de stock.
Le résumé JSON fournit foreign_cart : lien vers le panier actif d'origine, URL de
changement et jeton opaque. Aucun détail privé d'une autre boutique n'est exposé.

Le HMAC est lié à la session, à la boutique cible et à l'état complet du panier.
Chaque mutation réussie génère une révision aléatoire en session. Un jeton falsifié,
d'une autre session/cible, obsolète après modification, ou rejoué après recréation du
même contenu est refusé. Aucune clé ou valeur de jeton n'est journalisée dans l'audit.
Le serveur revérifie le propriétaire réel en session et le statut actif de sa boutique.
Le middleware public existant exige aussi une destination active. CSRF reste obligatoire.
CartService::clear() conserve strictement son contrôle de propriété.

UseCartSessionLocks est ajouté en tête du groupe web mais n'agit que sur les routes
public.shops.cart.*. StartSession acquiert le verrou AVANT lecture de la session,
et le libère après sauvegarde. Les lectures et mutations JSON du panier portent block(15,10).
Le stockage du verrou est explicitement fichier : file en application standard,
demo_limiter dans le bootstrap DEMO existant (déjà isolé et vérifié). Une configuration
sans ce pilote persistant est refusée en 503 ; aucun fallback vers array ou une base.
Cette garantie utilise les verrous fichiers locaux ; un déploiement multi-serveurs
nécessiterait un stockage de verrous partagé validé séparément.

L'interface propose Voir mon panier actuel et Changer de boutique. La dialog native
place le focus sur Annuler ; Annuler et Échap ne font aucun appel d'abandon.
La confirmation transmet uniquement le jeton de l'état présenté. En cas d'état devenu
obsolète, le panier est conservé, un message apparaît et une nouvelle confirmation
est nécessaire. Après réussite, badge et état courant sont synchronisés et le client
peut ajouter normalement le produit sélectionné, encore soumis à validation serveur.
Aucun panier n'est vidé par la seule visite d'une autre boutique.

Décision restant ouverte : l'abandon d'un panier dont la boutique est suspendue ou
supprimée. Aucun bouton de changement n'est proposé dans ce cas et aucun jeton n'est
fourni ; la protection et la conservation du panier restent en place. Une éventuelle
exception demanderait une règle métier distincte explicitement validée.

Vérifications de cette passe :
- PHP 8.3 : lint des contrôleurs/service/middleware/bootstrap/routes/traductions/tests OK.
- JavaScript : node --check OK ; git diff --check OK ; index Git inchangé et vide.
- PHPUnit : 34 tests / 342 assertions, puis 2 nouveaux tests / 9 assertions, soit
  36 tests / 351 assertions, SQLite :memory: exclusivement, configuration et absence
  de cache vérifiées avant lancement. Aucun test MySQL.
- Vite 8.3.4 : build réussi en 17,01 s ; avertissements fontaine facultatif et timings
  Tailwind, aucune dépendance ajoutée.
- Chrome réel, profils temporaires : 1440/390 px, FR/EN, quatre parcours réussis.
  Quantités automatiques (+/−, saisie, Entrée, blur), trois clics rapides regroupés
  en un PATCH, montants serveur, restauration sur 422 et 503, lien panier d'origine,
  annulation/Échap, confirmation, absence d'ajout implicite, refus d'un état devenu
  obsolète et nouvelle confirmation réussie. Aucune erreur JavaScript runtime ni
  débordement horizontal détecté. 16 captures dans :
  storage/demo/audits/cart-switch-2026-10-22T15-58-40-045Z/captures/.

L'empreinte logique des tables SQLite DEMO reste identique avant/après, avec stocks,
réservations, intégrité et clés étrangères inchangés/valides. Les seuls changements
persistants des parcours sont les sessions et verrous/cache fichiers DEMO isolés.
Aucun accès MySQL, migration, seeder, paiement, checkout, modification d'authentification,
application du plan de variantes DEMO ou opération Git d'écriture.

Contrôle réseau complémentaire Chrome smartphone FR/EN réussi : vraie interruption
réseau simulée avant appel serveur, quantité restaurée, erreur traduite et reprise
avec focus sur + ; réponse GET ancienne volontairement retardée ignorée après ajout.
Un clic Modifier sans changement ne déclenche aucune requête. Deux captures et résultats :
storage/demo/audits/cart-switch-network-2026-10-22T16-23-26-464Z/.
Le premier passage de cet outil avait une assertion française sensible à la casse,
corrigée dans le script local ; aucun défaut produit supplémentaire n'a été constaté.

## Correction de concurrence : verrou natif commun à toutes les requêtes web

Cette section remplace le périmètre limité aux routes JSON décrit précédemment.
Laravel StartSession charge puis réécrit la session complète, même pour une page HTML.
Un verrou réservé aux mutations JSON n'empêchait donc pas une page déjà démarrée de
réenregistrer un ancien panier après son abandon. Le jeton de confirmation ne protège
pas contre cette réécriture externe au point d'entrée d'abandon.

Le middleware existant UseCartSessionLocks configure maintenant session.block=true
pour TOUT le groupe web, avant StartSession. Laravel acquiert ainsi son verrou natif
avant lecture de session et le conserve jusqu'après sauvegarde, avec libération dans
finally. HTML marketplace/marchand, JSON panier, langue et authentification partagent
le même espace de verrouillage par identifiant de session. Aucun verrou applicatif
supplémentaire, aucun deuxième backend et aucune règle métier du panier modifiée.
L'enregistrement prepend dans bootstrap/app.php reste inchangé dans cette correction.

Les block(15,10) spécifiques au panier sont supprimés pour utiliser la configuration
commune. Les autres délais explicites préexistants de routes ne sont pas modifiés.
Configuration ajoutée à config/session.php, sans modification des fichiers .env :
- SESSION_BLOCK_STORE : file par défaut ; stockage Redis partagé configurable.
- SESSION_BLOCK_LOCK_SECONDS : 120 secondes par défaut.
- SESSION_BLOCK_WAIT_SECONDS : 10 secondes par défaut.
En DEMO, demo_limiter reste imposé : c'est le cache fichier isolé déjà disponible.
Un pilote non pris en charge, une session cookie ou un délai invalide sont refusés
avant lecture de session (503), sans fallback vers un cache de base de données.

Tests de concurrence : vrai middleware Laravel StartSession, gestionnaire de session
et fournisseur de verrous doublés exclusivement en mémoire, ordonnanceur PHP Fiber.
Les tests n'altèrent ni la méthode de lecture/sauvegarde ni le finally de Laravel.
- Ancienne configuration : l'anomalie est reproduite comme contrôle témoin.
- HTML commencé en premier : l'abandon attend AVANT de lire ; après sauvegarde HTML,
  l'abandon peut se terminer et le panier reste absent.
- Abandon commencé en premier : HTML attend puis lit le panier vide confirmé.
- Ajout, modification, retrait et abandon concurrents : états sauvegardés sérialisés,
  aucune mise à jour perdue dans le pipeline de session testé.
- Exception et timeout : libération correcte, aucune lecture/écriture en cas d'attente
  expirée et aucune libération du verrou d'un autre propriétaire.
- Sessions distinctes : absence de blocage mutuel. Rafale de 100 opérations légères
  sans attente artificielle ni perte d'écritures ; ce n'est pas un benchmark production.

Les tests HTTP de configuration vérifient l'ordre réel du middleware avant StartSession
sur 12 routes existantes HTML/JSON/auth, le verrouillage d'une page HTML, le refus des
configurations invalides, le choix de Redis sans connexion réseau et le stockage DEMO.
Le test de limitation DEMO dans BuyerAuthenticationTest indique désormais explicitement
son stockage de verrous demo_limiter lorsqu'il charge le cache DEMO dans l'application
de test. Aucune modification des contrôleurs ou règles d'authentification.

Validation : 69 tests / 704 assertions PASS, dont 8 tests de concurrence entièrement
sans base, 4 tests de configuration, et régressions panier, pages publiques et identité
acheteur/professionnelle. Les tests applicatifs utilisent exclusivement SQLite :memory:,
avec phpunit.xml vérifié et aucun cache de configuration. Aucun seeder exécuté.
Syntaxe PHP ciblée et git diff --check réussis. Pas de modification CSS/JS dans cette
correction, donc pas de nouvelle compilation frontend ni d'audit Chrome général.
Aucun accès à MySQL ou SQLite DEMO persistante ; aucun commit ni indexation.

Limites natives et exploitation : file convient à un hôte unique ; plusieurs serveurs
exigent un Redis partagé configuré et validé séparément (aucun Redis réel contacté ici).
Les requêtes stateful doivent se terminer avant expiration du bail, y compris les
éventuels délais explicites historiques de routes, et les timeouts serveur doivent
être alignés. Laravel ne renouvelle pas automatiquement ce bail. L'attente est bornée :
une expiration d'attente échoue sans entrer dans le gestionnaire de session ; elle ne
contourne pas le verrou. Les requêtes d'une même session sont volontairement sérialisées.
