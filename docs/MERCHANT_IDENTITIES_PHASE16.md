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
