# Médias photographiques DEMO — phase 16

Les offres restent fictives. Les visuels sont des images synthétiques générées pour ce projet
avec l'outil imagegen intégré, puis exportées en JPEG (qualité 88) pour le web.
Il ne s'agit ni de photographies des stocks réels d'un commerçant ni de photos issues de catalogues tiers.
Aucun logo, emballage de marque ou photographie tierce n'a été utilisé comme référence.

## Provenance et prompts

Chaque visuel a été généré séparément avec le même cadrage : photographie de catalogue carrée,
produit entier centré, fond studio blanc cassé, lumière douce, ombre de contact et matière réaliste,
sans personne, logo, texte, marque ni filigrane. Les sujets précis sont :

- mug : mug en céramique ivoire mouchetée, anse arrondie ;
- coussin : coussin en lin naturel avec motif géométrique ocre discret ;
- panier : panier de rangement en fibres naturelles avec deux anses ;
- plateau : plateau rectangulaire en bois de noyer avec rebords ;
- chemise : chemise en coton ivoire pliée, manches courtes ;
- sac : cabas en toile naturelle avec détail tissé indigo ;
- pochette : pochette zippée textile indigo et terracotta ;
- foulard : foulard tissé indigo et ocre à franges ;
- cafe : sachet kraft neutre et quelques grains de café ;
- the : sachet kraft neutre et feuilles de thé dans une petite coupelle ;
- corbeille : corbeille basse en fibres naturelles tressées ;
- set : set de table rond tissé en fibres naturelles ;
- stand : support de téléphone de bureau en aluminium sombre, sans téléphone ;
- sleeve : housse d'ordinateur en feutre gris avec rabat ;
- mat : tapis de bureau charbon aux coins arrondis ;
- cable : trois attaches de câbles en silicone, tons naturels.

Prompt commun de production (anglais) :

> Single square photorealistic premium ecommerce studio product photograph of [subject].
> Fictional unbranded Cameroon demo merchandise. Entire object clearly visible centered,
> occupies 70% of image, warm off-white seamless background, soft daylight, realistic contact
> shadow and material texture. No people, no logo, no words, no text, no watermark, no other
> items except specified. Professional catalogue still life, not illustration. For demo only,
> not actual goods for sale.

Les premiers visuels de maison et vêtements ont utilisé une formulation équivalente demandant
un rendu éditorial sobre, sans marque et avec l'objet entier visible sur fond studio clair.
Les fichiers finaux du projet se trouvent dans public/marketplace/demo-photos-v1/.

## Installation sans écriture SQLite

L'audit a lu uniquement product_images, products et stores dans database/zemboa_demo.sqlite,
via PDO SQLite ouvert avec SQLITE_OPEN_READONLY. Les 16 chemins enregistrés étaient des SVG DEMO
locaux génériques. Aucun identifiant ni chemin en base n'a été modifié.

resources/demo-media/catalogue-v1.json associe chaque photo au chemin SVG existant de son article.
tools/install-demo-media.php remplace seulement le contenu de ces fichiers SVG par une enveloppe
contenant le JPEG embarqué et la mention DEMO / VISUEL IA - NOT FOR SALE. Le navigateur peut ainsi
charger l'image sans dépendance externe et sans changer son URL.

Ce script n'importe ni Laravel, ni .env, ni PDO et n'exécute ni requête SQL, ni migration, ni seeder.
Il valide tous les fichiers source et cibles avant de commencer, refuse les chemins redirigés et
conserve la première version des SVG dans storage/demo/media-backups/v1/.

Commandes de contrôle et de réinstallation, uniquement sur les fichiers locaux :

~~~powershell
$demoPhp = 'C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe'
& $demoPhp tools/install-demo-media.php --dry-run
& $demoPhp tools/install-demo-media.php --apply
~~~

Le dossier public/marketplace/demo/ et les sauvegardes storage/demo/ restent exclus de Git.
Les JPEG sources, le manifeste et l'installateur peuvent être versionnés. Une future réexécution
autorisée du seeder actuel remettra ses SVG génériques : relancer ensuite cet installateur
pour rétablir les visuels, sans modifier le seeder ni la base. Ne pas exécuter le seeder pour
installer les images.

## Vérification locale

Actualiser le navigateur sans cache pour les anciennes URL SVG. Vérifier Maison des Collines,
Atelier du Wouri et les autres boutiques, les fiches produit, le cadrage carré, les détails de variantes
et la mention DEMO. Les fichiers source JPEG ne constituent pas des annonces commerciales réelles.
