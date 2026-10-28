# Phase 19B — caisse physique et documents

## Architecture réutilisée

`CreatePosOrder` conserve le moteur `CreateOrder`, son calculateur FCFA, les promotions de variantes et les réservations. `CreatePosCashPayment`, `CreateCashPayment`, `ConfirmPayment` et `FinalizeOrderPayment` restent les seuls mécanismes d'encaissement et de conversion du stock. Les caisses, ouvertures/clôtures, soldes et mouvements utilisent les routes/actions POS existantes. Les paramètres de documents utilisent `StoreDocumentSettingController`.

L'interface HTML se trouve sous `/espace-professionnel/caisse` : catalogue, panier de session distinct du panier marketplace, sessions et opérations de caisse, ventes paginées, détail, encaissement ultérieur, documents. Les anciennes routes JSON sont conservées. Le formulaire d'encaissement complet ne permet pas d'imposer un prix, total ou remise : le serveur recharge les variantes et applique les promotions valides.

## Ventes anonymes autorisées uniquement au POS

Décision du propriétaire : nom, téléphone et e-mail facultatifs au comptoir et indépendants. Sans nom/téléphone complets, aucune fiche Customer n'est créée ; les seuls champs réellement fournis restent dans les snapshots de la commande. Avec nom et téléphone, le mécanisme Customer de la boutique est réutilisé. Aucun User fictif, aucune association automatique User/Customer.

L'extension `allowAnonymousPos` est un argument interne serveur de CreateOrder, jamais repris des entrées du checkout public. Elle exige un acteur POS autorisé et un retrait physique. Les validations du checkout en ligne restent obligatoires ; un test vérifie qu'un champ injecté ne les contourne pas. Le téléphone fourni par l'interface POS est validé et normalisé par BuyerIdentity ; aucune vérification SMS n'est prétendue.

## Paiements et stock

- Panier POS : pas de réservation ni de décrément.
- Enregistrement non payé : commande PENDING/UNPAID et réservation par les règles existantes, sans décrément physique.
- Espèces : confirmation explicite, paiement créé puis confirmé par les actions financières existantes, dans la transaction de vente. Stock physique converti uniquement après règlement intégral confirmé.
- Une vente impayée peut être encaissée depuis son détail si sa session originale est encore ouverte et si l'acteur est autorisé. Les versements confirment le solde total, jamais un acompte implicite. Rejouer la même confirmation ne crée pas un second paiement/décrément.
- Paiements partiels : l'absence de StoreSetting n'accorde plus implicitement cette autorisation. Les tests d'acomptes existants déclarent désormais leur configuration explicitement. Le règlement intégral reste permis sans configuration.
- Mobile Money : mécanismes simulés existants uniquement ; aucune nouvelle interface de confirmation, carte fictive ou débit réel. Les états CONFIRMED/PENDING restent ceux du backend.

Les annulations/retours/remboursements existants ne sont pas réinventés. Aucun traitement automatique d'expiration n'est activé. Une vente impayée liée à une session fermée ne peut pas être encaissée arbitrairement dans une autre session par cette interface.

## Idempotence, accès et concurrence

PosAccess impose web, utilisateur et boutique actifs, boutique réelle de l'acteur, rôle unique autorisé, permissions et vérification de l'employé caissier. SUPER_ADMIN, livreur et acheteur sans rôle professionnel sont refusés. Le gestionnaire ne reçoit aucun droit financier supplémentaire ; son accès aux documents et détails de paiements nécessite notamment payments.view.

La caissière consulte seulement les ventes de ses propres sessions ; propriétaire/gestionnaire restent limités à leur boutique et permissions. Toutes les routes, y compris impressions et PDF, vérifient ces autorisations côté serveur.

SubmitPosSale prend les verrous caisse/session avant la commande et trie les variantes par identifiant avant réservation. Une clé aléatoire de soumission est stockée hachée et unique par boutique ; l'empreinte lie acteur, panier et paramètres. Le replay vérifie l'identité et le contenu avant de retourner la commande existante, même après perte de réponse/session non sauvegardée. Une modification du contenu avec la même clé est refusée. La disponibilité et le devis sont revérifiés avant vente ; un écart impose une nouvelle confirmation. Une erreur provoque rollback, sans retry applicatif automatique.

Les tests en mémoire couvrent replays, devis concurrents périmés et rollback. Ils ne démontrent pas l'attente réelle entre deux connexions MySQL : une validation concurrente sur une base jetable de même version reste à autoriser séparément. Aucun test MySQL n'est exécuté ici.

## Documents

Dompdf 3.1.6 est ajouté directement, sans wrapper Laravel ni scripts Composer. Dépendances transverses ajoutées : php-font-lib 1.0.2, php-svg-lib 1.0.2, masterminds/html5 2.11.0, php-css-parser 9.5.0. Aucun autre paquet existant n'est mis à jour par cette installation.

Facture A4 PDF réelle et aperçu HTML ; tickets HTML 58 et 80 mm avec marges de 2 mm, largeur utile 54/76 mm et hauteur ajustée avant impression navigateur. Aucun pilote ou service d'impression installé. Le choix final du support, la coupe et les marges du pilote thermique nécessitent un essai matériel manuel.

Les articles/prix/remises proviennent des OrderItem figés, l'identité commerciale et le caissier d'un snapshot POS. Le logo est figé uniquement pour PNG/JPEG locaux validés et bornés (512 Kio, 2000 px) ; les SVG et sources distantes ne sont pas incorporés pour limiter les risques de lecture/exfiltration. En leur absence, l'identité textuelle du commerçant reste affichée. Pas de faux numéro fiscal, taxe, TVA ou certification légale.

Dompdf refuse les ressources distantes, le PHP et JavaScript embarqués ; son accès fichier est limité au dossier de vues des documents. Les vues échappent les données. Les documents sont privés, non mis en cache et sans referrer. Le statut de paiement imprimé reste exact, avec avertissement explicite sur une vente non payée.

Les paramètres existants contrôlent formats par défaut, activation numérique, logo/coordonnées, langue et pied de page. Les préférences de format actuelles restent utilisables pour la réimpression ; les informations transactionnelles figées ne sont pas recalculées depuis le catalogue. Une ancienne vente sans snapshot POS n'est pas transformée en facture historique fictive : affichage détaillé possible, nouveau document refusé.

## Déploiement — migration à autoriser

`2026_10_24_083724_add_pos_submission_snapshot_to_orders_table.php` prépare : identité client nullable, clé/empreinte POS, snapshot JSON, auteur utilisateur et index unique boutique/clé. Migration testée exclusivement sur SQLite :memory:. Aucun changement appliqué à MySQL ou SQLite DEMO. La route de vente refuse d'écrire si les colonnes ou l'index de sécurité sont absents.

Le down refuse de retirer les protections s'il existe des ventes POS ou des commandes sans identité complète. Ne pas appliquer de rollback/migration persistant sans sauvegarde cohérente et autorisation. MySQL/MariaDB : vérifier auparavant sur une base jetable de même version la modification de nullabilité sous FK, la FK auteur, le JSON, l'index unique, la conservation des commandes et les contraintes ; les tests SQLite ne valident pas ces DDL en exécution.

## Lecteurs et preuves visuelles

Les lecteurs USB agissant comme clavier utilisent le champ de recherche puis Entrée. Le modèle n'a pas de champ code-barres dédié : la recherche accepte nom/SKU, pas un code EAN/UPC absent du catalogue. Aucun bouton caméra ou scan spécialisé fictif.

Les preuves Chrome sont générées depuis les vrais rendus Blade et réponses HTTP des tests SQLite :memory:, avec réseau intercepté. Elles ne constituent pas une vente ou une connexion sur SQLite DEMO. Captures/résultats/PDF fictif sont sous `storage/demo/phase19b-preview/`, exclu de Git. Aucun compte ni vente DEMO n'est créé par cette préparation.

## Vérifications de cette livraison

- Régression ciblée backend : 150 tests, 741 assertions réussis sur SQLite :memory:.
- Dernière exécution POS après les corrections de formulaire et de rendu : 19 tests, 164 assertions réussis, également en mémoire. Ces résultats recouvrent une partie de la régression précédente et ne doivent pas être additionnés.
- Chrome temporaire : 12 rendus POS FR/EN aux largeurs 1440, 1280, 1024, 768, 390 et 360 px ; clavier, fermeture du menu mobile, boutons de quantité et réponse serveur simulée depuis les véritables réponses HTTP des tests. Aucun débordement ni erreur JavaScript détecté.
- Trois aperçus d'impression HTML (A4, 58 et 80 mm). Le véritable PDF A4 est contrôlé par PDFium : une page, dimensions A4, texte et montants présents, raster inspecté. Le lecteur PDF intégré à Chrome n'a pas rendu le document ; sa capture grise n'est pas une preuve de validation.
- Syntaxe PHP sur 17 fichiers, syntaxe JavaScript et git diff --check : réussis. Build Vite réussi ; avertissement sur le paquet optionnel fontaine absent, sans échec de compilation.
- Aucun accès MySQL, aucune écriture SQLite DEMO, aucune migration persistante, aucun seeder et aucun envoi d'e-mail. Index Git laissé vide.

## Fichiers concernés par cette intervention

Fichiers applicatifs existants adaptés :

- app/Actions/Orders/CreateOrder.php
- app/Actions/Payments/ValidateStorePaymentRules.php
- app/Actions/Pos/CreatePosOrder.php
- app/Http/Controllers/Auth/SessionController.php
- app/Models/Order.php
- composer.json
- composer.lock
- routes/web.php
- lang/en/payments.php
- lang/fr/payments.php
- resources/js/app.js
- resources/css/app.css
- resources/views/professional/layout.blade.php
- resources/views/professional/navigation.blade.php
- tests/Feature/CreateCashPaymentTest.php
- tests/Feature/ValidateStorePaymentRulesTest.php
- tests/Feature/PublicCheckoutTest.php

Nouveaux fichiers POS :

- app/Support/PosAccess.php
- app/Support/PosCart.php
- app/Actions/Pos/SubmitPosSale.php
- app/Http/Controllers/Professional/PosController.php
- database/migrations/2026_10_24_083724_add_pos_submission_snapshot_to_orders_table.php
- lang/fr/pos_interface.php
- lang/en/pos_interface.php
- resources/js/pos.js
- resources/css/pos.css
- resources/views/professional/pos/index.blade.php
- resources/views/professional/pos/cart.blade.php
- resources/views/professional/pos/history.blade.php
- resources/views/professional/pos/show.blade.php
- resources/views/professional/pos/document.blade.php
- resources/views/professional/pos/settings.blade.php
- tests/Feature/PosInterfaceTest.php
- docs/POS_PHASE19B.md

Les autres modifications locales antérieures des phases 17.4A, 18A, 18B et 19A sont conservées ; elles ne sont pas toutes attribuables à cette intervention.
