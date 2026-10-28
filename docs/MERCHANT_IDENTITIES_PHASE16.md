# Identités marchandes — phase 16

## État réel de la personnalisation

- `Store` contient nom, description, slogan et adresse publique. Pas de champ
  ville distinct : les cartes affichent l'adresse existante, sans inventer une ville.
- `StoreSetting` contient `logo_path`, `banner_path`, `primary_color`, avec une
  seule ligne par `store_id` (contrainte unique de la migration existante).
- `GET /store/settings` et `PATCH /store/settings` répondent en JSON via
  `StoreSettingController`, `UpdateStoreSettingRequest`, `UpdateStoreSettings`
  et `StoreSettingPolicy`. Le scope tenant et les permissions `settings.view` /
  `settings.update` encadrent ces accès. L'action verrouille la boutique courante.
- La couleur hexadécimale est validée et modifiable par cette API. Les champs
  logo/bannière sont fillable dans le modèle, mais **aucune règle de requête,
  gestion de téléversement ou formulaire Blade d'apparence n'a été trouvé**.
  Ils ne peuvent donc pas être renseignés via cette requête validée actuelle.
- Les vitrines utilisent déjà les chemins du disque `public` et la couleur.
  L'API publique `PublicCatalogController` expose également ces trois champs.
- Tests existants repérés : `StoreSettingTest`, `StoreSettingHttpTest`,
  `StoreSettingsRbacTest` et `MarketplacePagesTest`. Ils n'ont pas été exécutés
  pendant cette intervention ; aucune écriture de base n'a été autorisée.

À développer séparément après validation : formulaire d'apparence utilisant
l'API/action existantes, validation des fichiers et dimensions, stockage isolé
par boutique, autorisations, remplacement/nettoyage des anciens médias et tests
d'isolation. Aucun modèle, contrôleur parallèle ou endpoint de téléversement
n'a été ajouté dans cette intervention.

## Cartes publiques

Le composant partagé de l'annuaire et de l'accueil utilise les champs publics
de `stores` et uniquement les trois champs d'apparence de sa propre ligne de
`store_settings`. La jointure explicite est limitée aux boutiques `ACTIVE`, avec
égalité `identity.store_id = stores.id` et contrainte unique existante. Il ne
neutralise pas le scope tenant de `StoreSetting` et n'expose aucun autre paramètre.
Elle évite les requêtes par carte et fonctionne avec MySQL comme SQLite.

La couleur configurée est validée au rendu et sert au fond d'accent, pas à un
texte arbitraire susceptible de manquer de contraste. Les initiales retirent le
préfixe DEMO uniquement pour l'avatar ; le nom réel affiché conserve ce préfixe.
Sans description ni slogan, aucune présentation fictive n'est ajoutée. Aucune
catégorie n'est affichée : cette information facultative nécessiterait une lecture
supplémentaire du catalogue, sans utilité nécessaire pour cette correction ciblée.

## Kits DEMO proposés — non affectés en base

Sources : `resources/demo-media/branding-v1/` ; [manifest](../resources/demo-media/branding-v1/manifest.json).
Copies de prévisualisation : `public/marketplace/demo/branding-v1/` (ignorées par Git).
Régénération fichiers uniquement :

```powershell
node tools/prepare-demo-branding.cjs
```

| Boutique fictive | Couleur proposée | Identité proposée |
|---|---|---|
| Maison des Collines | `#34635C` | Maison stylisée, coussin et décoration |
| Atelier du Wouri | `#835447` | Monogramme tissé, étoffe et accessoires |
| Mountain Digital | `#263D62` | Montagne/écran, accessoires de bureau |
| Terroir des Hauts Plateaux | `#64733F` | Feuille, café et terroir |

8 SVG originaux de démonstration : logos vectoriels créés pour ZEMBOA et bannières
intégrant les photos synthétiques déjà préparées et documentées dans
`docs/DEMO_MEDIA.md`. Aucun logo officiel tiers ni photographie récupérée sur le
Web. Les titres SVG identifient leur caractère fictif et les bannières portent
« DEMO · COMMERCE FICTIF ». Ces propositions ne représentent pas des commerces réels.

**Aucune association par slug n'est codée en dur dans Blade.** Tant que les
champs de la base DEMO ne sont pas renseignés, les cartes et vitrines conservent
leurs données existantes et les placeholders prévus. Ne pas relancer le seeder
existant pour tenter cette affectation : son `firstOrCreate` ne met pas à jour
les paramètres des boutiques existantes.

### Procédure d'affectation à valider avant toute écriture

1. Valider visuellement les 4 kits et les trois valeurs par boutique du manifest.
2. Après autorisation, préparer une commande DEMO dédiée réutilisant
   `bootstrap/demo.php` et `DemoDatabase::assertIsolated()`, sans charger `.env`.
3. Avant transaction : vérifier les quatre slugs exacts, le préfixe `DEMO - `,
   le statut actif, l'existence d'une seule configuration par boutique et des
   huit fichiers locaux attendus. Refuser tout chemin hors du disque DEMO et
   toute collision avec une boutique non fictive.
4. Relever les valeurs actuelles des trois seuls champs d'apparence pour permettre
   leur restauration. Mettre à jour exclusivement ces champs, dans une transaction
   explicitement SQLite DEMO, avec chaque `store_id` vérifié individuellement.
5. Comparer les valeurs relues au manifest ; une seconde exécution doit rester
   sans effet supplémentaire. Aucun rôle, mot de passe, produit, stock ou autre
   paramètre commercial ne doit être modifié.
6. Relancer l'audit public après l'affectation autorisée.

Cette commande d'écriture n'a volontairement pas été exécutée ni ajoutée à un
seeder automatique. Les bannières/logos réellement affectés restent à contrôler
après autorisation ; le présent audit couvre les fallbacks des données actuelles.

## Corrections secondaires ciblées

Le résumé de pagination anglais était reproduit dans l'audit précédent : ajout
des traductions JSON et des libellés FR. L'appel Google Fonts était bloqué par la
garde locale : suppression de cet appel et utilisation d'Instrument Sans déjà
distribuée par Vite, avec polices système de secours. Aucun téléchargement de
police ou dépendance supplémentaire.

La recherche des pages principales quittait l'écran au défilement : un formulaire
GET vers le catalogue global est accessible depuis un disclosure natif dans le
header sticky. Les recherches existantes et les menus sont conservés. Les liens
du footer disposaient déjà de hover et focus visibles ; aucune réécriture du
footer n'a été nécessaire.

## Évolution 20C-2D — centre de personnalisation

Les constats précédents décrivent la phase 16. Le code 20C-2D ajoute le centre
HTML `/espace-professionnel/boutique`, en conservant le layout professionnel et
les vues marchandes indépendantes. Les rubriques identité, apparence, coordonnées,
horaires/langue et règles commerciales sont enregistrées dans un brouillon SQL
privé, jamais directement dans la configuration publique depuis ce formulaire.
Les préférences documentaires restent accessibles dans le POS et conservent
leur enregistrement immédiat existant, explicitement indiqué à l'utilisateur.

`StoreCustomization` orchestre le brouillon et réutilise `UpdateStoreSettings`
à la publication. Une migration additive crée `store_customizations` (une ligne
unique par boutique, révision, empreinte de la version publiée, date de publication)
et ajoute `store_settings.commercial_configured`. Les lignes de paramètres déjà
présentes gardent leur sémantique commerciale grâce à la valeur initiale `true`.
Une première création purement visuelle utilise 30 minutes, refuse les acomptes
et marque la configuration commerciale `false` : elle ne débloque pas le checkout
HTML. Une publication commerciale explicite configure ces règles. Les anciennes
routes JSON restent des sauvegardes immédiates autorisées ; leur première création
utilise les mêmes valeurs sûres et leurs modifications rendent obsolète un brouillon
préexistant. Le brouillon ne peut pas écraser silencieusement ces modifications.

Les écritures utilisent un verrou de la ligne Store, une transaction, une révision
et une empreinte de la configuration publiée. Un formulaire ou une publication
obsolète est refusé ; le propriétaire peut abandonner le brouillon et le recréer.
La migration refuse un rollback susceptible de supprimer des données de
personnalisation ou la distinction commerciale. Elle n'est pas appliquée aux bases
persistantes dans cette phase ; avant application, vérifier le schéma et sauvegarder
la cible autorisée. Une interruption DDL MySQL exige une inspection avant reprise,
sans relance aveugle. Aucun essai MySQL/MariaDB n'est revendiqué.

Seul un propriétaire professionnel actif, avec un rôle unique, la permission et
la bonne boutique peut écrire, publier ou prévisualiser. Le gestionnaire consulte
les informations publiées sans accéder au brouillon ; les autres rôles et les
combinaisons de rôles sont refusés, même avec une permission d'écriture ajoutée.
Les comptes et boutiques inactifs sont refusés. Aucune permission n'est ajoutée.

Les médias du brouillon restent sur le disque privé `local`, avec une route
authentifiée `no-store`. JPEG/PNG/WebP uniquement, validation du contenu, de la
taille et des dimensions, noms générés côté serveur. La publication copie vers
`stores/{store_id}/branding/` sur le disque public. L'ancien fichier reste présent
jusqu'à la réussite de la publication. Seuls les médias gérés par ce workflow,
non référencés ailleurs, sont nettoyés ; les anciens médias partagés restent
préservés. Un échec de nettoyage peut laisser un fichier orphelin, sans remettre
en cause la publication. Une erreur d'écriture SQL conserve le brouillon et nettoie
les nouvelles copies publiques. Les disques configurés restent réutilisés, sans
chemin DEMO codé en dur.

La prévisualisation sert les mêmes vues, avec une substitution limitée à la requête,
sans écriture publique. Elle propose accueil, catalogue, catégories, informations et
services. Les liens ouvrent la version publique ; les achats restent bloqués pour
les sessions professionnelles. Les accents clairs sont assombris pour conserver
un contraste d'au moins 4,5:1 avec le blanc. Les images absentes utilisent le fallback.

Limites conservées : slug non modifiable, FCFA/XAF, horaires informatifs sans plage
nocturne, seuil de stock applicable aux nouvelles variantes seulement, aucun faux
thème ou réglage de notifications, aucune activation d'expiration automatique et
aucun compte Mobile Money modifiable depuis ce centre. Les zones et tarifs de
livraison ne disposent pas d'un nouvel éditeur. Les factures POS acceptent pour leur
logo PNG/JPEG jusqu'à 512 Ko ; le formulaire explique cette limite.

Les tests de `StoreCustomizationTest` et les régressions ciblées utilisent uniquement
SQLite `:memory:` et des disques simulés. Les contrôles Chrome hors ligne utilisent
leurs rendus fictifs, jamais SQLite DEMO, aux largeurs 360, 390, 768, 1024, 1280 et
1440 px. Les captures et fixtures restent dans le stockage de test ignoré par Git.

### Fichiers de l'évolution 20C-2D

34 fichiers concernés, dont 12 nouveaux. Les fichiers déjà modifiés ou non suivis
avant cette phase conservent leurs travaux antérieurs.

```text
app/Actions/Payments/ValidateStorePaymentRules.php
app/Actions/StoreSettings/UpdateStoreSettings.php
app/Http/Controllers/MarketplaceController.php
app/Http/Controllers/PublicCheckoutController.php
app/Http/Controllers/StoreDocumentSettingController.php
app/Http/Controllers/Professional/DashboardController.php
app/Http/Controllers/Professional/StoreCustomizationController.php
app/Http/Requests/Professional/SaveStoreCustomizationRequest.php
app/Models/StoreSetting.php
app/Policies/StoreSettingPolicy.php
app/Services/StoreCustomization.php
app/Support/StoreBranding.php
app/Support/StoreCustomizationAccess.php
database/migrations/2026_10_25_075628_create_store_customizations_table.php
docs/MERCHANT_IDENTITIES_PHASE16.md
lang/en/customization.php
lang/fr/customization.php
resources/css/app.css
resources/css/store-customization.css
resources/css/storefront.css
resources/js/app.js
resources/js/store-customization.js
resources/views/professional/customization/index.blade.php
resources/views/professional/navigation.blade.php
resources/views/professional/pos/settings.blade.php
resources/views/marketplace/shop.blade.php
resources/views/marketplace/merchant/about.blade.php
resources/views/marketplace/merchant/catalog.blade.php
resources/views/marketplace/merchant/categories.blade.php
resources/views/marketplace/merchant/checkout.blade.php
resources/views/marketplace/merchant/layout.blade.php
resources/views/marketplace/merchant/services.blade.php
routes/web.php
tests/Feature/StoreCustomizationTest.php
```

Validation finale : 82 tests, 528 assertions sur SQLite `:memory:`. Chrome hors ligne :
114 contrôles initiaux FR/EN et sur les six largeurs, avec prévisualisation locale
d'un fichier, sélection du format mobile et navigation clavier. Lint PHP/JS,
Pint limité aux fichiers de la phase, build Vite et `git diff --check` réussis.
Aucune lecture/écriture MySQL ou SQLite DEMO, aucune migration persistante.
