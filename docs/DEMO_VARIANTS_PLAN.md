# Variantes ZEMBOA — constat et proposition DEMO (phase 17.2)

Ce document est une proposition. Aucun seeder, outil de transition ou changement de
base n'est appliqué par cette intervention. Les fichiers DEMO existants restent inchangés.

## Architecture effectivement réutilisable

- Product::variants : plusieurs ProductVariant, chacune avec SKU, prix/promotion,
  statut, variante par défaut et StockLevel propres.
- Attribute::values et AttributeValue::attribute : attributs/valeurs textuels libres
  par boutique. ProductVariant::attributeValues utilise product_variant_attribute_value.
- Product, ProductVariant, Attribute et AttributeValue utilisent BelongsToStore :
  lecture filtrée par store_id et attribution de store_id à la création.
- CreateAttributeValue vérifie que l'attribut appartient au tenant.
- AttachAttributeValue recherche variante et valeur dans le tenant, refuse une autre
  valeur du même attribut pour une variante et utilise syncWithoutDetaching.
- La table pivot impose une paire variante/valeur unique et des clés étrangères.
  Elle n'a pas de store_id ni de FK composite imposant l'égalité des boutiques.
  Le contrôle d'une valeur par attribut est applicatif : il ne doit pas être contourné
  par un attach/sync brut non vérifié. La concurrence sur ce contrôle n'est pas certifiée ici.
- CreateProductVariant n'impose aucune valeur d'attribut : une variante technique
  unique sans attribut est déjà possible. Il crée le stock et gère la première variante
  par défaut ; les actions update/delete conservent les règles existantes.
- Les requêtes valident prix, promotion, SKU unique dans la boutique et statut.
  Aucune génération automatique de combinaisons ni attribut obligatoire par catégorie.
- Le CRUD professionnel des variantes et ses policies existent. Aucune route HTTP
  d'association attribut/variante n'a été trouvée dans routes/web.php : l'action backend
  est réutilisable, cela ne prouve pas l'existence d'un éditeur complet par interface.

Pointure 42/43, taille M/L et couleur, capacité 128/256 Go, conditionnement 250/500 g
sont représentables sans nouvelle structure. Ils sont choisis pour chaque produit,
jamais imposés uniformément à une catégorie ou à toutes les boutiques.

Les vues publiques affichent maintenant « Attribut: valeur » en utilisant les vraies
relations chargées en groupe. Sans attribut affichable, elles conservent le SKU existant.
Prix et stock restent affichés par variante. Aucun libellé Standard/Plus n'est inventé
par le frontend. Le panier JSON conserve ses SKU et les montants du service existant.

Sources : app/Models/{Product,ProductVariant,Attribute,AttributeValue}.php ;
app/Models/Concerns/BelongsToStore.php ; app/Actions/ProductVariant/{CreateProductVariant,
UpdateProductVariant,AttachAttributeValue}.php ; app/Actions/AttributeValue/CreateAttributeValue.php ;
app/Http/Requests/{StoreProductVariantRequest,UpdateProductVariantRequest}.php ;
database/migrations/2026_10_04_* ; tests/Feature/AttributeTest.php.

## Incohérences du jeu DEMO actuel

DemoSeeder crée demo-edition / DEMO Edition pour chaque boutique, et Standard/Plus
pour chaque produit, avec +2 000 FCFA sur Plus. Le stock initial est uniforme selon
la boutique : 20, 3, 0 ou 12 unités par variante. Le catalogue contient des paires nom/prix,
sans définition métier des variantes. Il n'illustre donc ni le produit simple, ni les
tailles réelles de chemise, ni des stocks distincts entre options d'un même produit.

firstOrCreate et syncWithoutDetaching évitent certains doublons, mais ne convertissent
pas ces associations. Une relance ne corrige pas des libellés déjà créés ; elle peut
ajouter des variantes, conserver d'anciens liens et réécrire des médias SVG génériques.
Elle lance aussi les seeders RBAC. Ne pas l'utiliser comme outil de correction ciblée.

## Jeu limité proposé — à valider

| Produit existant | Variantes envisagées | Prix fictifs FCFA | Stock initial envisagé |
|---|---|---|---|
| Mug céramique, Maison des Collines | Une variante technique sans attribut | 3 500 | 3 |
| Chemise coton, Atelier du Wouri | Taille M, Taille L | 15 000 chacune | M 8, L 2 |
| Coussin décoratif, Maison des Collines | Format 40 × 40 cm, 50 × 50 cm | 7 000, 9 000 | 3, 1 |
| Café moulu, Terroir des Hauts Plateaux | Conditionnement 250 g, 500 g | 4 000, 7 500 | 0, 0 |

Ce sont des objectifs de démonstration, pas des décisions de stock appliquées.
Les stocks réels existants devront être revérifiés avant tout plan d'ajustement.
Aucun téléphone ou chaussure fictif supplémentaire n'est nécessaire pour ces quatre cas.

## Fichiers et comportement futurs proposés

1. database/seeders/Demo/catalog.php : décrire explicitement les attributs, variantes,
   prix et stocks initiaux par produit, avec codes techniques stables.
2. database/seeders/Demo/DemoSeeder.php : consommer ces définitions pour de nouvelles
   bases approuvées, sans variantes Standard/Plus automatiques ni réassort à la relance.
3. Outil de transition distinct à préparer après accord (nom proposé tools/demo-variants.php,
   non créé ici) : plan sans écriture puis application ciblée, sans relancer DemoSeeder,
   RBAC, comptes, paramètres boutiques ou installation d'images.

Pour une DEMO déjà peuplée, conserver les slugs, ID produits/variantes et SKU existants
même si leur suffixe technique reste Standard/Plus. Créer les attributs/valeurs appropriés
avec leurs clés naturelles dans le bon tenant. Retirer uniquement les associations
DEMO Edition des variantes sélectionnées puis utiliser AttachAttributeValue pour les
nouvelles valeurs. Ne jamais renommer globalement Standard en M : d'autres produits
partagent cette valeur dans la boutique. Pour le mug, conserver la variante par défaut
et rendre l'autre inactive après contrôle de ses références, sans suppression.

L'ajustement de stock nécessiterait une autorisation détaillée supplémentaire. Employer
ReceiveStock / AdjustStockIn / AdjustStockOut selon le besoin, avec motif DEMO traçable,
pas une mise à jour brute ignorant les mouvements. Refuser toute modification si une
réservation, commande ou autre référence métier rend le plan ambigu. Pas de paiement.

## Sécurité et idempotence de la procédure envisagée

- Bootstrap DEMO et DemoDatabase::assertIsolated, chemin SQLite fixe, seule connexion
  autorisée ; aucun chargement normal de .env ni paramètre libre de connexion.
- Sauvegarde SQLite cohérente incluant les données WAL, vérifiée avant application ;
  fenêtre sans écritures concurrentes et comparaison d'une empreinte du plan approuvé.
- Allowlist de quatre produits par store_id/slug/SKU, vérification des références et
  des états attendus ; toute collision ou dérive provoque un arrêt sans écriture.
- Transaction unique pour les associations/statuts et ajustements approuvés. Utiliser
  firstOrCreate pour les valeurs et syncWithoutDetaching via l'action contrôlée.
- Reconnaître l'état structurel déjà converti et ne jamais rejouer le stock initial,
  même s'il a été consommé ensuite. Les mouvements non nuls portent un motif versionné.
  Un état inconnu/intermédiaire est bloqué pour revue humaine, jamais assimilé à une
  nouvelle installation. L'idempotence devra être testée avant de livrer l'outil.
- Préserver les médias, comptes, paramètres, commandes et données hors allowlist.
- Valider d'abord l'outil et deux passages sur SQLite :memory: ; présenter le plan exact
  et demander l'autorisation explicite avant tout changement sur SQLite DEMO.

Aucune commande d'application n'est fournie comme existante : l'outil proposé doit
encore être implémenté et validé après accord. Aucun changement de ces deux seeders
n'a été effectué, et aucun seeder ou migration n'a été exécuté ici.
