# Phase 20C-1 — Navigation des espaces et authentification

## Contrat retenu

Le guard professionnel reste `web`, le guard acheteur reste `buyer`. Aucun rôle,
compte, permission ou lien `Customer` n'est créé par cette intervention.
La connexion professionnelle utilise explicitement `web`, même lorsqu'une identité
acheteur est déjà présente. Les rotations et verrous de session existants restent actifs.

`AccountDestination` contrôle l'identité active, le rôle unique, la boutique active
et la vérification de l'adresse des employés. La destination exige également les
permissions effectives ; elle ne les accorde jamais.

| Identité autorisée | Destination HTML |
| --- | --- |
| SUPER_ADMIN qualifié | `admin.dashboard` |
| STORE_OWNER / STORE_MANAGER avec `orders.view` | `professional.dashboard` |
| CASHIER avec `pos.sales.create` | `pos.interface.index` |
| DELIVERY_AGENT avec `deliveries.view` | `courier.index` |
| Acheteur seul | `buyer.account` |
| Visiteur | Connexion appropriée |

`/account` est sorti du groupe de contexte marchand : un SUPER_ADMIN sans boutique
n'y rencontre plus le refus de tenant. Le contrôleur vérifie explicitement le guard
et les invariants avant toute réponse. La réponse JSON ne contient que l'identité
professionnelle courante. Un professionnel sans permission de tableau de bord peut
encore se connecter et consulter cette identité JSON ; aucune destination privée
HTML ne lui est accordée. Les comptes désactivés, boutiques inactives et rôles
ambigus conservent leurs refus. La connexion HTML ignore une ancienne destination
`url.intended` étrangère à l'espace du rôle.

## Séparation entre consultation et achat

Décision métier appliquée côté serveur : toute session `web` authentifiée interdit
les mutations du panier public et le checkout public, y compris invité ou avec un
guard `buyer` simultané. `PublicShopping` est appelé par `CartService` et par
`PublicCheckout::ready()`. Il couvre ajout, quantité, retrait, vidage, changement
explicite de boutique et les points d'entrée du checkout. La confirmation publique
utilisant `ready()` reste également indisponible sous session professionnelle.

La lecture publique reste possible. Les cartes et fiches remplacent leurs actions
d'achat par une indication de consultation. La vitrine conserve son identité et
affiche un retour discret vers le bon espace. Aucun panier existant n'est détruit
par cette restriction. Pour acheter, utiliser une session invitée ou acheteur sans
connexion professionnelle. Le POS n'utilise pas cette restriction publique : ses
ventes et encaissements autorisés sont préservés.

## Interfaces d'authentification

Layout Blade partagé, identité ZEMBOA existante, fonds CSS, carte claire et variante
sombre administrative, labels explicites, erreurs regroupées, focus clavier,
FR/EN et boutons accessibles d'affichage/masquage des mots de passe.

La maquette inspire la composition ; ses photos ne sont pas intégrées et ses
fonctions illustratives ne deviennent pas des promesses produit :

- Acheteur : téléphone camerounais obligatoire à l'inscription, e-mail facultatif,
  connexion par l'identifiant existant et mot de passe minimum de 12 caractères.
- Professionnel et administrateur : connexion par e-mail, conformément au backend.
- Aucun nouveau « se souvenir de moi », paiement prestataire ou consentement juridique.
- Aucune route publique de mot de passe oublié/réinitialisation n'existe : aucun faux
  formulaire ou lien n'est ajouté. La définition du mot de passe par invitation reste réelle.
- Le consentement existant d'activation d'identité acheteur est conservé ; il ne
  permet pas d'acheter pendant qu'une session professionnelle reste connectée.

Les invitations utilisent la boutique et le rôle réellement chargés. Leurs règles
d'expiration, d'usage unique, transactions et protections HTTP sont conservées.
Les erreurs HTML ont une page générique sans jeton ni adresse divulgués.
La fiche administrative lit uniquement `email` et `created_at` du broker : lien
courant émis/expiré, adresse vérifiée, compte et boutique dans leur état actuel.
Elle ne prétend pas connaître la remise de l'e-mail ou un historique absent.

## Fichiers concernés par cette intervention

- `app/Support/AccountDestination.php`, `app/Support/PublicShopping.php`.
- `app/Services/CartService.php`, `app/Services/PublicCheckout.php`.
- `app/Http/Controllers/Auth/SessionController.php`.
- `app/Http/Controllers/Admin/Auth/AdminSessionController.php`.
- `app/Http/Controllers/Admin/StoreController.php`.
- `app/Http/Controllers/OwnerInvitationController.php`.
- `app/Http/Controllers/EmployeeInvitationController.php`.
- `bootstrap/app.php`, `routes/web.php`.
- `lang/fr/access.php`, `lang/en/access.php`.
- `resources/css/app.css`, `resources/css/auth-ui.css`.
- `resources/js/app.js`, `resources/js/auth-ui.js`.
- `resources/views/auth/{layout,password,invitation-unavailable,login}.blade.php`.
- `resources/views/buyer/{auth,layout}.blade.php`.
- `resources/views/admin/login.blade.php`, `resources/views/admin/stores/show.blade.php`.
- `resources/views/owner-invitations/{accept,complete}.blade.php`.
- `resources/views/employee-invitations/{accept,complete}.blade.php`.
- `resources/views/marketplace/{layout,product}.blade.php`.
- `resources/views/marketplace/merchant/{layout,cart}.blade.php`.
- `resources/views/marketplace/partials/{account-links,cart-card-action}.blade.php`.
- `tests/Feature/AccountNavigationTest.php` et ce document.

Certains fichiers étaient déjà modifiés ou non suivis par les phases précédentes.
Cette liste ne représente pas la totalité du travail local à sauvegarder ultérieurement.

## Vérifications et limites

Précontrôle RBAC : 11 tests, 40 assertions réussis. PHPUnit vérifié : SQLite
`:memory:`, URL vide, configuration non cachée. Les tests de navigation retirent
les autres connexions de la configuration avant `RefreshDatabase`.

Les régressions panier, checkout, POS, invitations et administration ont été
contrôlées. Une première campagne a révélé trois refus de connexion causés par une
exigence de permission de destination placée dans l'authentification. Ce défaut a
été corrigé en distinguant identité professionnelle et accès au tableau de bord.
Les scénarios d'authentification concernés ont ensuite réussi.
La dernière campagne ciblée réunit `AccountNavigationTest`, `BuyerAuthenticationTest`,
`EmployeeAuthenticationTest`, `InvitationPrivacyTest` et `AdminInterfaceTest` :
**74 tests et 695 assertions réussis**. Elle vérifie notamment les deux guards
simultanés, le guard explicite de connexion professionnelle, les refus directs de
mutation/changement de panier, le checkout invité interdit aux professionnels,
les achats publics autorisés aux invités/acheteurs et les états d'invitation.

Chrome local installé, profil temporaire : 72 contrôles de formulaires et 24 de
navigation, FR/EN, aux largeurs 360/390/768/1024/1280/1440. Aucun débordement,
erreur JavaScript ou échec du contrôle des mots de passe/menus/retours professionnels.
32 captures représentatives à 390 et 1440 px dans
`storage/demo/phase20c1-preview/`, avec résultats JSON et scripts locaux exclus de Git.
Ces rendus proviennent des vraies réponses HTTP de tests en mémoire ; toutes les
requêtes réseau de Chrome sont bloquées. Les jetons et adresses d'invitation ainsi
que les CSRF sont neutralisés dans les exports HTML. Les médias réseau absents des
captures de navigation résultent de cette isolation. Aucune session personnelle
Chrome ni connexion réelle à la DEMO n'a été utilisée.

Lint PHP, syntaxe JS, Pint ciblé, build Vite et `git diff --check` contrôlés.
Vite signale l'optimisation facultative des polices via Fontaine, sans échec du build.
Aucune dépendance n'est ajoutée.

Aucune base persistante consultée ou modifiée, aucune migration créée/appliquée,
aucun message envoyé, aucun index Git préparé, aucun commit/push. Les dépendances de
déploiement et migrations des phases antérieures ne sont pas validées à nouveau ici.
Un parcours connecté sur DEMO et toute future récupération de mot de passe restent
des validations/travaux distincts soumis au périmètre autorisé. Ces contrôles ne
constituent pas une validation de production.
