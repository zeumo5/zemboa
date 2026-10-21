# Phase 16 — stabilisation finale du frontend public

## Périmètre de cette dernière passe

Le design validé, les cartes, les médias, les menus, les routes et les layouts
marchands indépendants sont conservés. Aucun travail de phase 17 n'est commencé.
Le footer global de la marketplace n'est pas modifié dans cette passe.

### Corrections finales

- Footer marchand extrait dans `resources/views/marketplace/merchant/footer.blade.php`
  et inclus par le layout partagé. Trois liens réels avec icônes décoratives,
  cibles d'au moins 44 px, bordures, hover, focus clavier et page active.
  « Propulsé par ZEMBOA » est un texte discret séparé du lien de retour.
- Contraste : la couleur marchande validée est employée sur les états interactifs
  uniquement si elle atteint 4,5:1 sur le fond de survol `#F1F5F9` et donc aussi
  sur le fond blanc. Sinon, repli vers `#0F6B57`, sans modifier la couleur stockée.
  Focus de 3 px ; transitions supprimées lorsque le mouvement réduit est demandé.
- Mobile/tablette : petites réductions des espacements verticaux du header,
  de la recherche, des sections et de la notice DEMO. Le conteneur de pagination
  n’est rendu que si plusieurs pages existent : suppression de sa marge vide
  de 30 px sur un catalogue d’une seule page. Aucune nouvelle structure
  de header/sidebar/carte ; footer empilé et liens pleine largeur sur smartphone.
- Données absentes : message neutre pour catégories et coordonnées manquantes.
  La page services n'affiche plus une liste vide lorsque tous les services sont
  désactivés ; seuls les horaires contenant ouverture et fermeture sont présentés.
  Ces changements portent sur l'affichage, pas sur les règles commerciales.

## Contenus dynamiques et futur tableau de bord

Toutes les boutiques utilisent les mêmes vues Blade. `MarketplaceController`
reçoit la boutique du `TenantContext`, contrôle slug/statut et filtre les lectures
de catalogue par `store_id`. Le footer utilise le même `$store` et la même couleur
issue de ses paramètres ; aucune identité ou modalité particulière n'est codée
en dur pour un commerçant. Les paramètres publics de l'annuaire sont joints par
égalité `store_settings.store_id = stores.id` pour les seules boutiques actives.

| Besoin | Données/code existants | Manque à traiter ultérieurement |
|---|---|---|
| Nom et présentation | `Store.name`, `description`, `slogan` ; création/consultation administrateur | Formulaire et action d'édition du profil marchand, permissions et validation |
| Logo et bannière | `StoreSetting.logo_path`, `banner_path`, affichage sur disque public | Formulaire, upload sécurisé, limites MIME/dimensions, rangement par boutique et cycle de remplacement |
| Couleur | `primary_color`, API protégée GET/PATCH `/store/settings`, requête/policy/action existantes | Interface du tableau de bord et aide de contraste |
| Page À propos | Vue partagée utilisant la description et les coordonnées du Store | Éventuels champs éditoriaux dédiés, politique de publication et traductions |
| Coordonnées publiques | `address`, `phone`, `email` ; `whatsapp` existe dans Store | Formulaire marchand et choix explicite des informations rendues publiques |
| Livraison/retrait | `delivery_enabled`, `pickup_enabled`, autres indicateurs et horaires existants | Textes précis, zones, délais et modalités structurées à définir ; ne pas les inventer dans Blade |
| Textes FR/EN | Traductions d'interface et `default_locale` existants | Contenus marchands actuellement monolingues : stockage/édition/traduction à concevoir avant toute nouvelle migration |

La couleur est modifiable via l'API existante, mais `UpdateStoreSettingRequest`
ne gère pas de téléversement logo/bannière. Il n'existe pas de formulaire complet
d'apparence. Les routes administrateur de création/statut ne constituent pas un
tableau de bord d'édition marchand. Réutiliser les actions/policies existantes,
sans architecture parallèle. Aucun de ces formulaires n'est développé ici.

Les kits de médias fictifs et leur procédure d'affectation sont décrits dans
`MERCHANT_IDENTITIES_PHASE16.md`. Aucune affectation SQLite n'est autorisée par
cette passe ; les champs actuellement renseignés et leurs fallbacks font foi.

## Décision produit définitive — WhatsApp

WhatsApp servira exclusivement aux échanges client/commerçant : questions,
renseignements produit et service client. Aucun lien « Commander sur WhatsApp »
et aucun paiement ou création de commande par WhatsApp ne doivent être introduits.
L'intégration éventuelle de communication est reportée à la phase 17.

Le parcours principal reste intégré à ZEMBOA : sélection, panier, validation de
commande, paiement réellement disponible puis suivi. Les paiements Mobile Money
actuels demeurent simulés ; aucun paiement réel n'est annoncé. À terme, les
paiements réels de chaque commerçant devront cibler son propre compte marchand.
Les interfaces transactionnelles et l'authentification acheteur restent phase 17.

## Explications aux visiteurs — travail futur

Prévoir une rubrique « Comment fonctionne ZEMBOA ? » lorsque les parcours
correspondants seront accessibles. Expliquer simplement découverte, commande,
paiement et suivi, puis les modes de livraison/retrait effectivement activés.
Ne présenter comme opérationnelles que les fonctionnalités réellement connectées.
Les animations resteront discrètes, compatibles mouvement réduit et mobiles.
Aucune nouvelle rubrique pédagogique ou animation importante n'est publiée ici.

## Vérifications et fin du polissage

La syntaxe PHP/Blade/JS, le build Vite, `git diff --check` et l'audit Chrome public
sont les contrôles de cette passe. L'audit ajoute les états normal/hover/focus du
footer pour deux commerces à 1440, 768 et 390 px, mesures de contraste et taille,
mouvement réduit et navigation réelle. Le rapport local et les captures sont sous
`storage/demo/audits/`, ignoré par Git. Les résultats chiffrés du passage final
sont consignés dans le rapport de l'audit et le bilan de livraison.

Aucun PHPUnit n'est exécuté dans cette passe. Les vérifications PHP de contraste
évaluent uniquement le bloc mathématique du footer, hors bootstrap Laravel et
sans SQL. Aucun accès MySQL, migration, seeder ou changement de données SQLite.

Après ces contrôles, arrêt du polissage visuel, sauf défaut bloquant ou régression
avérée. Les fonctionnalités futures ci-dessus constituent une documentation,
pas une autorisation de commencer la phase 17.

## Préparation Git — aucun commit ni push

Les sources de phase 16 à relire puis sélectionner explicitement sont :

- `.gitignore`, `.env.demo.example` (exemple sans secret) ;
- contrôleur public, routes publiques et test `MarketplacePagesTest` existants ;
- configuration/guards/bootstrap/outils et seeders DEMO préparés précédemment ;
- vues marketplace et vues marchandes, CSS/JS associés, traductions FR/EN ;
- photos synthétiques versionnées, sources/manifest des médias et documentation ;
- outil d'audit, sans ses dépendances locales ni ses résultats.

Les bases SQLite et sidecars, `.env`/`.env.demo`, clés, caches, résultats d'audit,
ZIP, sauvegardes, patches racine et fichiers temporaires restent exclus. Les deux
anciens scripts racine d'import/réparation (`install_marketplace.php` et
`zemboa_i18n_returns_corrige.php`) génèrent des sources et backups : ils sont
conservés localement, mais ignorés avec leurs instructions `README_INSTALLATION.txt`.
Les fichiers accidentels `$content)` et `$locales)` sont également ignorés.
Aucun fichier n'est supprimé. Aucun changement n'est ajouté à l'index par l'agent.

Contrôler `git status -sb`, `git diff --check` et le contenu exact de la sélection
avant un futur commit autorisé. Le statut inclut des développements antérieurs à
cette dernière passe : ils sont préservés, pas attribués aux corrections du footer.
