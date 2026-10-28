# Validation DEMO et raccordements métier — 23 octobre 2026

Référence : `a9d53a408a0fa8a3e5b71edea3399e99c89cce75`, branche `feat/customer-auth`.
Inspection ciblée des commandes, réservations, acomptes et promotions ; aucun nouveau développement fonctionnel.

## Validation persistante autorisée, SQLite DEMO uniquement

Le bootstrap `bootstrap/demo.php` et `DemoDatabase` ont confirmé la connexion exclusive
`sqlite_demo`, le driver SQLite et le chemin `C:/laragon/www/zemboa/database/zemboa_demo.sqlite`.
Aucun accès MySQL, migration, seeder, paiement ou traitement d'expiration.

Sauvegarde locale privée, exclue de Git :
`storage/demo/backups/functional-20261023-082853-6300e20d/before.sqlite` (593 920 octets).
L'API SQLite Online Backup a copié une connexion source en lecture seule dans une
transaction de lecture, donc en tenant compte du WAL. Intégrité `ok`, aucune violation
FK, 52 migrations, empreintes de toutes les tables identiques à la source avant création.
La copie et son SHA-256 ont été revérifiés avant l'exécution.

| Cas | Commande | Quantité | Réception | Frais | Total | Résultat |
| --- | --- | ---: | --- | ---: | ---: | --- |
| Invité | ZM-20261023-MUHN6 | 1 | Retrait | 0 FCFA | 4 500 FCFA | PENDING / UNPAID |
| Acheteur | ZM-20261023-FB7UB | 2 | Retrait | 0 FCFA | 9 000 FCFA | PENDING / UNPAID |

Produit fictif existant de DEMO - Mountain Digital ; aucun prix imposé par le test.
Les coordonnées des commandes sont des valeurs synthétiques clairement marquées DEMO,
avec e-mails sur `example.test`. Aucun compte utilisateur n'a été créé ou modifié.

**Portée exacte :** appels des contrôleurs Laravel réels, `CartService`, `PublicCheckout`
et `CreateOrder`, sessions array éphémères. Pour l'acheteur, un compte fictif actif existant
a été fourni au guard `buyer` localement par `setUser`, sans lecture de mot de passe ni
connexion de navigateur. Ce contrôle ne constitue pas un nouveau test Chrome de login,
de CSRF ou du verrouillage des requêtes HTTP ; ces protections n'ont pas été désactivées
dans le code applicatif. Aucun contournement persistant d'authentification n'a été créé.

Vérifications réalisées :

- Quantités, variante, prix effectif, totaux Laravel et retrait autorisé cohérents.
- Propriétaire invité NULL ; propriétaire acheteur égal au compte du guard, sans lien User/Customer ajouté.
- Deux réservations ACTIVE, une par commande ; stock réservé +3 unités, stock physique inchangé.
- Échéance de réservation issue du paramètre marchand : 1 440 minutes (24 h).
- Resoumission identique pour chaque commande : même confirmation, aucune nouvelle commande/réservation.
- Confirmation accessible au bon acteur, refusée dans une autre boutique ; accès invité refusé depuis un autre identifiant de session, depuis le guard acheteur et après deux heures (horloge locale de test, sans écriture).
- Confirmation de l'acheteur refusée au visiteur invité.
- Paiements, utilisateurs, paramètres, produits et autres tables historiques inchangés.
- Seules `customers`, `orders`, `order_items`, `stock_reservations`, `stock_levels` ont changé : ajouts attendus et réservations ; toutes les anciennes lignes des quatre premières tables sont intactes, seules les quantités réservées et leur horodatage attendu changent sur le stock ciblé.
- Intégrité finale `ok`, zéro violation FK. Aucun effacement ou nettoyage des commandes de test.

Les commandes restent dans la DEMO avec trois unités réservées. L'expiration n'a pas été
lancée et reste désactivée : une échéance dépassée ne libère pas le stock spontanément.
La session invitée du test était éphémère : sa confirmation n'est pas transférable au
navigateur personnel. La fenêtre d'accès invité de deux heures est distincte de la durée
de réservation de 24 h.

Limites : retrait uniquement, car la livraison est désactivée dans cette boutique ;
pas de modification des paramètres pour inventer un cas livraison. Idempotence vérifiée
séquentiellement, pas certification des courses SQL MySQL/MariaDB. Le refus d'un second
acheteur connecté distinct n'a pas été reproduit ici ; les contrôles de propriétaire sont
couverts par les tests existants `PublicCheckoutTest`.

## Réservations : réalité du contrat

- `CreateOrder` appelle systématiquement `CreateStockReservation` dans la transaction de commande ; l'ajout au panier ne réserve rien.
- `CreateStockReservation` verrouille le stock, vérifie `physical_quantity - reserved_quantity`, incrémente la réserve et crée une ligne ACTIVE liée à ORDER. Conversion/libération refusent les états incohérents.
- Durée : `StoreSetting.reservation_duration_minutes` ; 30 minutes en l'absence de paramètres. La date est mémorisée sur la commande et les réservations.
- `allow_reservations` est stocké et administrable, mais **n'est pas consulté par CreateOrder pour désactiver la réserve technique**. Ne pas confondre cette réserve avec une réservation commerciale volontaire.
- Aucun champ d'éligibilité à la réservation par produit, ni parcours public distinct « Réserver », n'a été identifié.
- `CancelOrder` libère les réservations d'une commande PENDING/UNPAID non récupérée par un livreur ; `ExpireOrder` applique aussi l'échéance. Ces actions ne sont pas exposées par des routes publiques client.
- `orders:expire-unpaid` existe, testé dans `ExpireUnpaidOrdersTest`, désactivé par défaut et refusé en DEMO. Son activation/scheduler demande une validation séparée.

## Acomptes et paiements : réalité du contrat

- `ValidateStorePaymentRules` contrôle `allow_partial_payments` et `minimum_deposit_percent`. Le minimum s'applique au premier acompte, sur le total commande, avec arrondi supérieur entier ; le paiement intégral reste accepté.
- En l'absence de paramètres boutique, l'action autorise les paiements partiels par compatibilité. Cette politique doit être explicitement validée avant de présenter une offre d'acompte au client.
- `CreateCashPayment` crée un paiement en attente pour un professionnel autorisé ; `ConfirmPayment` verrouille commande/paiement, contrôle les montants et exige une date future de solde au premier paiement partiel. Cette date ne peut ensuite être remplacée librement.
- `FinalizeOrderPayment` additionne les paiements CONFIRMED via les pipelines verrouillés : PARTIALLY_PAID conserve les réservations ; PAID convertit les réservations en vente et décrémente le stock physique une seule fois (`ConvertStockReservation`). Son point d'entrée générique `execute()` reste volontairement interdit ; réutiliser les actions transactionnelles existantes.
- Solde = total commande moins somme des paiements confirmés, pas un montant fixé par le navigateur. `ListOverdueBalances` liste les échéances dépassées ; il ne règle ni n'annule automatiquement les commandes partiellement payées.
- Aucune route d'encaissement/confirmation de ces acomptes n'est actuellement raccordée à une interface acheteur ou commerçant. Les actions POS ne doivent pas être substituées au checkout public.
- Mobile Money : interface de vérification, actions et simulateur de test existent ; `AppServiceProvider` refuse de fournir un prestataire réel. `AssignMerchantAccountToPayment` exige le compte marchand vérifié de la boutique concernée. Aucun paiement réel disponible, aucune simulation persistante effectuée ici.
- Couverture source existante : `ValidateStorePaymentRulesTest`, `CreateCashPaymentTest`, `CashPaymentWorkflowTest`, `FinalizeOrderStockTest` et tests Mobile Money. Ces suites n'ont pas été relancées dans cette intervention.

## Promotions : réalité du contrat

Les variantes ont `promo_price`, `promo_starts_at`, `promo_ends_at`. `ProductVariant::effectivePrice`
applique le prix promotionnel dans la fenêtre inclusive, avec dates ouvertes possibles.
Les requêtes de gestion imposent prix positif ou nul, promotion inférieure au prix normal
et fin non antérieure au début ; les policies demandent `products.update` dans le tenant.

`CartService`, `CreateOrder` et `OrderPriceCalculator` appliquent ce prix effectif ; les
articles de commande conservent les snapshots. `MarketplaceController` reproduit la
sélection du prix public et `PublicCatalogController` expose `price` / `effective_price`.
Les prix sont donc raccordés, mais pas une présentation complète barré/badge/calendrier
promotionnel ni un écran marchand de campagne. Aucun moteur autonome de coupons,
codes promotionnels ou campagnes multi-produits n'a été identifié dans ce périmètre.
Couverture existante : `ProductVariantTest`, `OrderTest`, `OrderPriceCalculatorTest`.

## Cartographie des interfaces à raccorder

| Fonction métier / état | Backend opérationnel | Paramètres | Client actuel | Commerçant actuel | Frontend restant | Manque backend / décision |
| --- | --- | --- | --- | --- | --- | --- |
| Checkout — déjà fonctionnel et raccordé | PublicCheckoutController, PublicCheckout, CreateOrder ; routes HTML et JSON | Retrait, livraison, zones/frais, durée | Panier, formulaire, confirmation | GET JSON /orders et /orders/{id} | Relier les espaces client/pro | Validation MySQL/MariaDB et concurrence avant déploiement |
| Historique acheteur — partiellement implémenté | buyer_user_id et confirmation protégée | Identité globale buyer | Compte d'identité, pas d'historique | Commandes isolées par store | Liste/détail client et navigation | Endpoint de liste buyer autorisé à compléter ; ne pas réutiliser l'autorisation pro |
| Gestion commandes — backend existant, interface manquante ; mutations partielles côté HTTP | OrderController GET JSON ; CancelOrder/ExpireOrder internes | RBAC/tenant, statuts, échéances | Pas d'annulation client | Pas d'écran HTML commandes | Liste, détail et actions autorisées | Exposer uniquement les mutations approuvées avec permissions et contrôle de propriété |
| Réserve de stock technique — déjà fonctionnelle et raccordée | CreateStockReservation et conversions/libérations | Durée boutique | Automatique au checkout | Stock JSON ; pas d'écran de réservations | Présenter quantités réservées et échéances | Activation séparée de l'expiration ; verrous MySQL à valider |
| Réservation volontaire — partiellement implémentée | Briques de stock seulement | allow_reservations, durée | Aucun parcours distinct | Paramètres JSON | Aucun bouton avant contrat validé | Signification du flag, éligibilité produit et parcours à définir |
| Acompte et solde — backend existant, interface manquante | Actions Cash/confirmation/finalisation, ListOverdueBalances | Autorisation, minimum %, balance_due_at | Aucun paiement/acompte | Aucun écran ni route complète d'encaissement | Montant dû, acompte, confirmation simulée autorisée, échéances | Raccordement HTTP sécurisé ; politique sans settings et soldes en retard à valider |
| Mobile Money réel — non implémenté ; infrastructure partielle | Vérification, attribution marchand et réconciliation ; simulateur de test | Comptes marchands par boutique | Aucun paiement réel | Réconciliation JSON, pas d'encaissement réel | À différer de la simple intégration de commandes | Prestataire réel absent ; ne pas activer le simulateur comme production |
| Promotions de variantes — calcul raccordé, gestion visuelle manquante | effectivePrice, validation, policies ; POST/PUT variantes | Prix/date par variante | Prix effectif présent, pas de signalétique complète | Gestion JSON des variantes | Champs marchands et présentation promotionnelle fiable | Coupons/campagnes distincts non implémentés |

## Suite recommandée, sans démarrage ici

1. Finaliser le contrat des commandes : schéma MySQL/MariaDB sur cible jetable, règles d'expiration et raccordements HTTP autorisés.
2. Construire l'historique/détail client sur `buyer_user_id`, avec filtrage strict ; ne pas rattacher un Customer sur simple téléphone/e-mail.
3. Construire liste/détail commandes commerçant sur les GET JSON existants ; raccorder ensuite annulation, livraison/retrait et documents selon autorisations.
4. Valider la signification de réservation volontaire, puis présenter les réservations techniques existantes ; ne pas ajouter un bouton Réserver ambigu.
5. Raccorder les acomptes/solde dans un périmètre explicitement simulé et autorisé, avec échéances et rôles ; l'intégration Mobile Money réelle reste séparée.
6. Compléter progressivement catalogue, paramètres, promotions et autres espaces professionnels avec les actions existantes, sans recréer les modèles/services.

Scripts, preuves et sauvegardes locaux sous `storage/demo/` restent exclus de Git et ne
doivent pas être publiés avec des bases ou contacts. Aucun changement de code métier,
aucune nouvelle interface, aucun commit/push dans cette intervention.
