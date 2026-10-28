# Phase 20C-2A — Catalogue commerçant HTML

## État initial et architecture réutilisée

Le domaine catalogue existe : `Product`, `ProductVariant`, `Category`, `ProductImage`,
relations d'attributs et de stock, scopes `BelongsToStore`, policies, Form Requests
et Actions. Les prix/SKU appartiennent aux variantes ; la création d'un produit
crée sa variante par défaut et son stock initial à zéro. Les contrôleurs historiques
fournissent principalement du JSON ou des redirections vers des fiches JSON.
`CreateProductImage` gérait les métadonnées, sans parcours HTTP complet d'upload.

Les nouvelles routes HTML sont distinctes. Aucun endpoint historique JSON n'est
supprimé ou transformé. Les contrôleurs réutilisent `CreateProduct`, `UpdateProduct`,
`CreateProductVariant`, `UpdateProductVariant`, `CreateCategory`, `UpdateCategory`,
`DeleteCategory`, `CreateProductImage` et les Form Requests existantes.

## Interfaces et routes IMPLEMENTED

Préfixe `/espace-professionnel`, noms `professional.*`, middleware `web` existant,
authentification professionnelle et tenant. `EnsureCatalogAccess` contrôle le rôle,
le compte, la boutique et la permission avant la validation des formulaires.

| Méthode et chemin relatif | Nom de route |
| --- | --- |
| GET `/produits` | `products.index` |
| GET `/produits/ajouter` | `products.create` |
| POST `/produits` | `products.store` |
| GET `/produits/{product}/modifier` | `products.edit` |
| PUT `/produits/{product}` | `products.update` |
| POST `/produits/{product}/variantes` | `variants.store` |
| PUT `/variantes/{productVariant}` | `variants.update` |
| POST `/produits/{product}/images` | `images.store` |
| PUT `/images/{image}` | `images.update` |
| GET `/categories` | `categories.index` |
| GET `/categories/ajouter` | `categories.create` |
| POST `/categories` | `categories.store` |
| GET `/categories/{category}/modifier` | `categories.edit` |
| PUT `/categories/{category}` | `categories.update` |
| DELETE `/categories/{category}` | `categories.destroy` |

- Produits : photos existantes ou placeholder, nom, SKU, catégories, prix, état et
  stock autorisé ; recherche nom/SKU, filtres catégorie/état, tri nom/récence,
  pagination de 15 produits avec conservation des paramètres.
- Informations générales : nom, slug, description texte, catégorie, état
  ACTIVE/INACTIVE, mise en avant. Prix et SKU initial sur la première création ;
  SKU généré par l'Action existante si absent.
- Après création, redirection vers la fiche pour ajouter les autres variantes et
  les images. Chaque variante possède son propre formulaire : SKU, prix, promotion,
  dates, état et variante par défaut. Les attributs enregistrés sont affichés.
- Images : ajout, prévisualisation locale avant soumission, texte alternatif,
  ordre et choix de l'image principale. Une nouvelle image peut devenir principale
  sans supprimer les anciennes.
- Catégories : recherche, filtre d'état, compteurs, pagination, création et édition.
  Le lien Produits applique le filtre de catégorie. L'association se modifie dans
  la fiche produit. Suppression seulement avec permission et catégorie vide ;
  `DeleteCategory` vérifie à nouveau cette condition côté serveur.
- Navigation professionnelle/sidebar et raccourcis existants raccordés aux pages
  HTML ; état actif `aria-current="page"`, menus et navigation mobile conservés.
- Messages de succès, erreurs de validation et confirmation de suppression FR/EN.

## Autorisations et intégrité

Les droits proviennent des permissions existantes, sans nouveau seeder ni délégation :

- STORE_OWNER / STORE_MANAGER : mutations uniquement avec la permission appropriée.
  Le gestionnaire n'obtient pas `categories.delete` par défaut.
- CASHIER : consultation des produits uniquement si `products.view` est accordée ;
  aucune mutation sur ces interfaces, même par endpoint direct.
- DELIVERY_AGENT, acheteur seul, compte inactif, boutique inactive/suspendue et
  identités professionnelles ambiguës : refus.
- SUPER_ADMIN : aucune opération commerciale implicite via ce module. Sa supervision
  administrative existante reste distincte.

`ProductPolicy::viewAny` autorise désormais une liste avec permission `products.view`,
compte actif et tenant correspondant. Les policies d'instances restent appliquées.
Le binding et les requêtes Eloquent conservent le scope de boutique. Les identifiants
de produit viennent des routes et le `store_id` du contexte, jamais du formulaire.

Le stock reste en lecture seule selon `inventory.view`. Aucune quantité reçue du
navigateur n'est persistée. Aucun mouvement/réservation ni paiement n'est créé.
La création de variante conserve l'initialisation à zéro de l'Action existante.
Les vitrines et le POS conservent leurs règles de sélection/publication/disponibilité.

Les éditions HTML utilisent une empreinte des attributs persistés, comparée après
relecture sous transaction/verrou de ligne. Les compteurs de présentation sont
exclus de l'empreinte. Une page obsolète est refusée au lieu d'écraser une édition.
Après un refus, les formulaires d'édition conservent l'empreinte soumise : un second
clic ne renouvelle pas silencieusement le jeton associé à d'anciennes saisies.
Recharger explicitement la page permet de repartir des données courantes.
Les changements de variantes et d'image principale verrouillent d'abord le produit
parent. Les contraintes SQL d'unicité restent déterminantes ; les conflits tardifs
sont convertis en validation compréhensible, sans SQL ni relance automatique.
Ces contrôles ne constituent pas une preuve de concurrence réelle sous MySQL :
aucune base MySQL n'a été consultée/testée. Les anciens endpoints ne reçoivent pas
un nouveau contrat d'empreinte ; leurs validations et Actions restent inchangées.

## Médias

Validation du contenu JPEG/PNG/WebP, MIME et extension autorisés ; SVG/exécutables
refusés. Limites : 5 Mo, chaque côté entre 16 et 4 096 px. Le navigateur transmet
un fichier, jamais un chemin arbitraire à enregistrer. Le disque `public` configuré
par l'environnement est réutilisé, avec nom généré et préfixe
`stores/{store_id}/products/{product_id}/` dérivé des modèles autorisés.

Le fichier est enregistré avant la transaction des métadonnées ; une erreur de
cette transaction déclenche le nettoyage du nouveau fichier. Une transaction SQL
ne rend pas le stockage atomique. Les fichiers historiques ne sont pas supprimés
par cette phase. Les images sont publiques ; l'isolation porte sur leur gestion.
Les tests utilisent exclusivement `Storage::fake('public')`, jamais le disque DEMO.

## Écarts assumés avec la maquette / DEFERRED

- Le layout professionnel existant est conservé ; le dashboard et ses statistiques
  ne sont pas refondus, aucun chiffre de démonstration n'est injecté dans les vues.
- Aucun faux état « brouillon » : seuls ACTIVE/INACTIVE existent dans ce contrat.
- Pas de marque, poids, état neuf/occasion, SEO ou paramètres de boutique inventés.
- Description texte échappée, sans éditeur riche non installé.
- Les attributs existants sont consultables. Cette phase ne crée pas de gestionnaire
  complet d'attributs ni de nouvelles combinaisons couleur/taille implicites.
- La suppression physique de produit/variante et la suppression de fichiers média
  ne sont pas ajoutées à l'interface ; la désactivation et les opérations disponibles
  sont privilégiées. Les anciens contrats de suppression restent inchangés.
- Category ne possède pas d'image. Aucun faux upload de catégorie n'est affiché.
  Voir `CATEGORY_IMAGES_DECISION.md` pour la future extension additive ; aucune
  migration n'est nécessaire ou créée pour le présent livrable.
- Certains autres modules restent des liens JSON explicitement marqués, hors périmètre.

## Fichiers de cette intervention

- `app/Http/Controllers/Professional/CatalogController.php`
- `app/Http/Controllers/Professional/CategoryCatalogController.php`
- `app/Http/Controllers/Professional/CatalogMediaController.php`
- `app/Http/Controllers/Professional/DashboardController.php` (raccourcis uniquement)
- `app/Http/Middleware/EnsureCatalogAccess.php`
- `app/Http/Requests/Professional/UploadProductImageRequest.php`
- `app/Support/CatalogAccess.php`
- `app/Services/CatalogMedia.php`
- `app/Policies/ProductPolicy.php`
- `routes/web.php`
- `resources/views/professional/{layout,navigation,dashboard}.blade.php`
- `resources/views/professional/catalog/{index,product,variant-fields,categories,category,feedback}.blade.php`
- `resources/css/app.css`, `resources/css/professional-catalog.css`
- `resources/js/{app,catalog}.js`
- `lang/{fr,en}/catalog.php`
- `tests/Feature/ProfessionalCatalogTest.php`
- Ce document.

Les travaux locaux antérieurs restent présents ; cette liste ne remplace pas un
audit Git complet de tous les fichiers non committés.

## Vérifications

- Configuration PHPUnit vérifiée avant exécution : SQLite `:memory:`, `DB_URL` vide,
  absence de configuration Laravel cachée. Le nouveau test retire les autres
  connexions avant `RefreshDatabase`.
- Nouveau module : **18 tests / 173 assertions réussis** : créations/modifications,
  rôle gestionnaire, refus et révocations, consultation caissier, isolation HTML/JSON,
  unicité, prix, publication, versions obsolètes, stock inchangé, uploads valides et
  invalides, nettoyage, erreurs EN et nombre de requêtes indépendant du nombre de lignes.
- Régressions : **131 tests / 478 assertions réussis** : ProductControllerTest,
  CategoryControllerTest, ProductVariantTest, ProductImageTest, PublicCatalogTest,
  PosInterfaceTest, ProfessionalDashboardTest, StoreManagerRbacAuditTest,
  CashierRbacAuditTest, CategoryPolicyTest.
- Pint sur les fichiers PHP concernés uniquement, lint PHP, syntaxe JS,
  `git diff --check` et build Vite. L'avertissement d'optimisation facultative des
  polices Fontaine ne bloque pas le build ; aucune dépendance ajoutée.
- Contrôle Chrome local : rendus réels des vues obtenus par tests HTTP en mémoire,
  profil temporaire, toutes les requêtes réseau bloquées, aucune connexion DEMO.
  FR/EN, largeurs 360/390/768/1024/1280/1440 ; liste, création, édition, catégories,
  formulaire catégorie, menus, états actifs, prévisualisation locale et annulation
  de confirmation. Captures et résultats dans `storage/demo/phase20c2a-preview/`,
  exclus de Git. Les contenus et identités sont synthétiques ; les CSRF sont neutralisés.
  Au total : 60 contrôles FR/EN et 20 captures à 390 et 1440 px. Il s'agit de
  rendus isolés, pas d'un parcours connecté sur la base DEMO persistante.

Aucun accès MySQL, aucune écriture SQLite DEMO, aucune migration persistante,
aucun seeder persistant, aucune invitation, commande ou opération de paiement.
Aucun changement de branche, indexation, commit ou push. Les essais connectés avec
écritures DEMO restent soumis à une autorisation distincte.

## Phase 20C-2E — Promotions et mise en avant (2026-10-25)

**IMPLEMENTED.** Écran `/espace-professionnel/promotions` : recherche produit/SKU,
filtres actif/programmé/expiré/sans promotion, tri, pagination, édition/suppression
du prix promotionnel, aperçu des prix persistés et mise en avant dans la vitrine.
Les variantes sans promotion peuvent en recevoir une depuis cette liste. L'état
promotionnel décrit les dates ; il ne garantit ni publication ni stock disponible.

### Prix et autorisations

- `CatalogPricing::validate()` vérifie l'état final fusionné. `UpdateProductVariant`
  relit/verrouille la variante : baisser 100 000 à 80 000 en conservant une promotion
  à 85 000 est refusé. Les dates conservées sont également vérifiées. La réduction
  doit rester strictement positive après l'arrondi entier du calculateur FCFA existant.
- `CatalogPricing::writable()` impose un propriétaire professionnel actif, non acheteur,
  rôle unique STORE_OWNER, boutique active, tenant correspondant et `products.update`.
  Les Form Requests partagés protègent aussi les anciens endpoints JSON. Aucune
  nouvelle permission ni délégation implicite ; aucune migration nécessaire.
- STORE_MANAGER consulte les promotions et conserve les informations non tarifaires
  et catégories selon ses permissions. La création de produit/variante exigeant un
  prix initial est réservée au propriétaire. L'absence de `is_featured` dans une
  mise à jour conserve sa valeur. CASHIER applique les prix serveur dans le POS ;
  ni livreur ni SUPER_ADMIN ne reçoit de droit commercial implicite.
- Les mutations HTML réutilisent les versions optimistes de `CatalogAccess`.

### Dates et checkout

- Formulaires HTML : Africa/Douala (UTC+1) affiché, saisie minute locale validée puis
  convertie en UTC. Les anciens clients JSON sans marqueur `promotion_timezone`
  conservent le contrat UTC. Aucune ancienne date stockée n'est modifiée. Bornes
  inclusives ; aucune fin de journée inventée.
- Un instant commun est utilisé par chaque projection panier/POS/catalogue, et par
  le recalcul transactionnel du checkout avec `CreateOrder`.
- Le jeton checkout signé lie lignes, quantités, prix de base/nets, montants, mode,
  zone/frais au panier, à la boutique, à l'acteur et à l'expiration. Le POST ne
  dérive plus silencieusement un récapitulatif aux prix courants.
- Prix/frais changés : aucune commande ; JSON 409 avec `requires_confirmation`,
  nouveau total et jeton à confirmer. HTML : retour au récapitulatif actualisé avec
  coordonnées conservées et message explicite. Un client JSON sans récapitulatif
  reçoit aussi cette demande. Il doit présenter le montant à l'utilisateur, sans
  relance automatique. Transmettre de préférence le jeton reçu par GET checkout.
- La récupération d'une commande existante précède le recalcul ; la contrainte SQL
  d'idempotence reste déterminante. Aucun doublon ni effacement d'un panier récent.
- Avant création : variantes verrouillées par identifiant croissant, zone verrouillée,
  nouveau devis, comparaison du digest signé, même instant transmis à `CreateOrder`,
  garde finale du total. Le total nul est refusé dans l'Action commune et le formulaire
  HTML ne permet pas sa confirmation. Les frais de livraison ne subissent pas la
  promotion produit. CSRF, guards et verrous de session conservés.
- Après déploiement, recharger les anciens onglets checkout : leurs jetons sans
  empreinte de prix sont refusés (419). L'accès invité de deux heures est conservé.

### Vitrines, historique et limites

Cartes et fiches montrent le prix applicable, le prix normal barré et une réduction
indicative entière. L'aperçu professionnel utilise les données enregistrées ; le
lien ouvre la fiche publique, qui reste soumise à sa visibilité réelle. Une modification
non enregistrée ne prétend pas être publiée. La mise en avant garde son effet dans
la vitrine ; le tri et les badges globaux ne la présentent plus comme priorité éditoriale.
Les noms, couleurs et médias des boutiques restent dynamiques.

Commandes, paiements, acomptes, factures/tickets et remboursements gardent les montants
nets historiques. Une expiration ultérieure ne modifie pas une commande. Éditer une
promotion ne crée aucun mouvement ni réservation. Aucun moteur de coupons, quotas,
campagnes, remise globale, faux avis ou compte à rebours n'est ajouté. Un journal
dédié des modifications de promotions n'est pas développé ; l'historique des commandes
reste celui du backend existant.

**Limites :** SQL testé uniquement sur SQLite :memory:. Verrous et concurrence réelle
MySQL/MariaDB à éprouver sur une base jetable autorisée, avec les migrations antérieures
nécessaires. Aucune compatibilité production n'est déduite de SQLite. Aucune migration
ajoutée par cette phase, aucune migration persistante exécutée. Chrome utilise les
vues HTTP rendues en mémoire, toutes les requêtes interceptées, sans connexion DEMO
ni appel applicatif d'écriture ; cela ne remplace pas un parcours connecté persistant.

### Fichiers concernés par 20C-2E

Cette liste désigne l'intervention actuelle ; les travaux antérieurs non committés
restent présents, sans indexation implicite.
- `app/Actions/ProductVariant/CreateProductVariant.php`
- `app/Actions/ProductVariant/UpdateProductVariant.php`
- `app/Actions/Product/UpdateProduct.php`
- `app/Actions/Orders/CreateOrder.php`
- `app/Models/ProductVariant.php`
- `app/Support/CatalogPricing.php`
- `app/Support/PosCart.php`
- `app/Services/CartService.php`
- `app/Services/PublicCheckout.php`
- `app/Http/Controllers/MarketplaceController.php`
- `app/Http/Controllers/PublicCatalogController.php`
- `app/Http/Controllers/PublicCheckoutController.php`
- `app/Http/Controllers/Professional/PromotionController.php`
- `app/Http/Requests/StoreProductRequest.php`
- `app/Http/Requests/UpdateProductRequest.php`
- `app/Http/Requests/StoreProductVariantRequest.php`
- `app/Http/Requests/UpdateProductVariantRequest.php`
- `app/Http/Requests/Professional/SavePromotionRequest.php`
- `app/Policies/ProductPolicy.php`
- `app/Policies/ProductVariantPolicy.php`
- `app/Http/Middleware/EnsureCatalogAccess.php`
- `routes/web.php`
- `lang/fr/promotions.php`
- `lang/en/promotions.php`
- `tests/Feature/PromotionManagementTest.php`
- `tests/Feature/PublicCheckoutTest.php`
- `tests/Feature/PublicCartTest.php`
- `tests/Feature/ProfessionalCatalogTest.php`
- `resources/css/app.css`
- `resources/css/promotions.css`
- `resources/js/app.js`
- `resources/js/product.js`
- `resources/js/promotions.js`
- `resources/views/professional/layout.blade.php`
- `resources/views/professional/navigation.blade.php`
- `resources/views/professional/catalog/product.blade.php`
- `resources/views/professional/catalog/variant-fields.blade.php`
- `resources/views/professional/promotions/index.blade.php`
- `resources/views/marketplace/partials/promotion-price.blade.php`
- `resources/views/marketplace/components/product-card.blade.php`
- `resources/views/marketplace/merchant/catalog-list.blade.php`
- `resources/views/marketplace/product.blade.php`
- `resources/views/marketplace/merchant/checkout.blade.php`
- `docs/MERCHANT_CATALOG_PHASE20C2A.md`
- `DECISIONS.md`
- `database/factories/UserFactory.php`

La factory de test explicite `is_buyer=false`, qui est déjà la valeur par défaut SQL,
pour que les objets fraîchement créés respectent le même invariant que les comptes
professionnels rechargés depuis la base. Les cas négatifs acheteur restent explicites.
### Vérifications finales 20C-2E

- **321 tests distincts / 2 108 assertions réussis**, cumul des dernières exécutions
  ciblées réussies par scénario, sans additionner les relances. Cela ne représente
  pas une nouvelle exécution de la suite générale. Couverture : promotions, catalogue
  public et professionnel, anciens contrôleurs produits/variantes, checkout/panier,
  POS, commandes, remboursements, règles d'acompte et audits RBAC gestionnaire/caissier.
- Les anciennes fixtures de checkout ont été adaptées pour consulter explicitement
  le récapitulatif et conserver le cookie invité avant validation ; aucune assertion
  de stock, montant ou isolation n'a été assouplie. Les échecs intermédiaires ont
  été corrigés puis les scénarios concernés relancés.
- Dates JSON avec décalage explicite : normalisation UTC dans les deux Actions de
  variantes, après validation. Les dates absentes d'une mise à jour restent intactes.
  Test spécifique de création et mise à jour via l'ancien endpoint ; bornes inclusives
  et formulaires Douala couverts. Tester la session SQL en UTC lors de la future
  validation MySQL/MariaDB, particulièrement pour les colonnes TIMESTAMP existantes.
- Projections DECIMAL SQLite numériques et données JSON numériques converties
  explicitement en chaînes avant le calculateur FCFA, pour conserver son arrondi
  plutôt qu'une coercition implicite vers un entier. Test catalogue/fiche publique.
- Lint PHP : **29 fichiers** ; syntaxe JavaScript, Pint ciblé, build Vite et
  `git diff --check` réussis. Aucune dépendance ajoutée. L'avertissement Fontaine
  facultatif préexistant ne bloque pas le build.
- Chrome : **24 contrôles** (Promotions + fiche publique, FR/EN, 360/390/768/1024/1280/1440).
  Aucun débordement global, aucune erreur JavaScript, tableau desktop sans défilement
  horizontal à 1440 px, liste en cartes sur téléphone. Clavier et annulation du dialogue
  de suppression vérifiés. Aucun appel d'écriture applicatif exécuté.
- Captures et preuves locales, exclues de Git :
  `storage/framework/testing/promotions-preview/` : `promotions-fr-1440.png`,
  `promotions-fr-390.png`, `promotions-fr-390-list.png`, `promotions-en-390.png`,
  `product-fr-1440.png`, `product-fr-390.png`, `product-en-390.png`, `chrome-results.json`.
- État final : `feat/customer-auth...origin/feat/customer-auth`, **96 fichiers suivis
  modifiés / 195 fichiers non suivis**, comprenant les travaux antérieurs préservés.
  **46 fichiers concernés par 20C-2E**, dont 10 créés durant cette intervention.
  Index vide. Aucun commit, push, changement de branche ni opération persistante.
  `PROJECT_RULES.md` est inchangé. Aucun accès MySQL ou SQLite DEMO ; tests en mémoire.