# Images des catégories — décision métier validée

Statut : décision pour le futur tableau de bord commerçant, module Catégories.
Aucune fonctionnalité, migration ou modification de données n'est réalisée dans cette étape documentaire.

## Contrat validé

- Nom obligatoire ; description et image facultatives.
- Image présente et exploitable : fond de la carte, cadrage automatique sans déformation, overlay garantissant la lisibilité du texte.
- Image absente ou indisponible : dégradé CSS et pictogramme générique actuels.
- Identité graphique propre à chaque boutique, sans photographie imposée ni réutilisation automatique d'une photo produit.
- Le commerçant pourra ajouter, remplacer ou supprimer explicitement son image.
- La page Catégories présente des catégories et leurs compteurs publics, sans fiches produits, prix ou ajout au panier.

## Constat dans le code actuel

- `app/Models/Category.php` et `database/migrations/2026_10_03_141738_create_categories_table.php` : nom, slug, description nullable, statut et `store_id` ; aucun champ d'image.
- `StoreCategoryRequest`, `UpdateCategoryRequest`, `CreateCategory` et `UpdateCategory` : aucun traitement d'image de catégorie.
- `config/filesystems.php` fournit déjà le disque `public` (`storage/app/public`, URL `/storage`). Il permet de stocker des médias publics, mais ne constitue pas à lui seul une isolation par boutique ni une validation de fichiers.
- `bootstrap/demo.php` remplace ce disque par `public/marketplace/demo`, URL `/marketplace/demo`. Toute future gestion de médias doit respecter ce bootstrap, sans chemin absolu de production codé en dur.
- `resources/views/marketplace/merchant/categories.blade.php` utilise actuellement le fallback pour les cartes. Le hero peut utiliser la bannière existante de la boutique ; cette bannière ne remplace pas une image de catégorie.

## Modification minimale à prévoir, lors du module de gestion

1. Créer une nouvelle migration additive pour `categories.image_path`, chaîne nullable contenant un chemin relatif au disque `public`. Ne pas modifier la migration historique ni remplir automatiquement les catégories existantes. L'application persistante nécessitera une autorisation distincte.
2. Ajouter ce champ au modèle uniquement avec un traitement maîtrisé côté serveur. Le navigateur transmet un fichier, jamais un chemin arbitraire à enregistrer ou à supprimer.
3. Étendre les requêtes et actions existantes, sans créer un second backend : fichier facultatif, validation effective du contenu et du format, taille et dimensions bornées. Prévoir JPEG/PNG/WebP ; refuser SVG et contenus exécutables pour ce premier périmètre. Les limites exactes seront fixées avant implémentation selon les contraintes d'hébergement.
4. Générer le nom de fichier côté serveur et utiliser un préfixe proposé `stores/{store_id}/categories/{category_id}/`. Tirer les identifiants du contexte boutique autorisé, jamais des seules données du formulaire. Contrôler permissions, appartenance de la catégorie et préfixe du média avant toute mutation ou suppression. Un disque public rend les visuels publics : l'isolation vise notamment les droits de gestion, pas la confidentialité de ces images.
5. Distinguer trois intentions : aucun fichier = conserver l'image ; nouveau fichier valide = remplacer ; suppression explicite = retirer l'image et revenir au fallback. Ne jamais traiter l'absence d'upload comme une suppression.
6. Coordonner fichier et référence SQL : conserver l'ancien média jusqu'à la réussite de l'enregistrement, nettoyer le nouveau fichier en cas d'échec, et traiter les échecs de nettoyage sans supprimer un fichier partagé ou appartenant à une autre boutique. Une transaction SQL ne rend pas les opérations de stockage atomiques.
7. Adapter la carte Blade/CSS existante : image décorative derrière le contenu avec `object-fit: cover`, overlay, focus visible et fallback si le média manque ou échoue au chargement. Conserver les compteurs et liens tenant-scopés actuels, ainsi que FR/EN.

## Validation à préparer avant livraison

- Tests SQLite `:memory:` avec stockage de test isolé : upload valide/invalide, conservation, remplacement, suppression explicite, fichier absent et échec de stockage.
- Refus des accès et suppressions interboutiques, des chemins fournis arbitrairement et des modifications sans permission.
- Contrôles Chrome : contraste sur images claires/sombres, cadrage, mobile, clavier et fallback.
- Aucun essai sur MySQL `zemboa` ou sur SQLite DEMO persistante sans autorisation spécifique.

Cette décision n'autorise ni l'implémentation maintenant, ni une migration, ni une écriture de média ou de données persistantes. Elle doit être reprise lors du développement du tableau de bord commerçant.
