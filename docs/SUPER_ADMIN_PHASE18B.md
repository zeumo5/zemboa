# Phase 18B — Super administration et création de boutiques

## Périmètre livré

Sur `feat/customer-auth`, en préservant les travaux non committés de 17.4A et 18A :

| Route | Interface / comportement |
| --- | --- |
| `GET /admin/login` | Connexion existante, présentation FR/EN et Vite |
| `GET /admin` | Dashboard global : états des boutiques, professionnels et boutiques récentes |
| `GET /admin/stores` | Liste HTML filtrable par nom/slug et statut, pagination de 20 ; JSON conservé avec `Accept: application/json` |
| `GET /admin/stores/create` | Nouveau formulaire HTML |
| `POST /admin/stores` | Transaction existante boutique + nouveau propriétaire ; JSON 201 conservé ou redirection HTML |
| `GET /admin/stores/{storeId}` | Détail, propriétaires, invitation, décision et historique ; JSON conservé |
| `POST /admin/stores/{storeId}/owner-invitation` | Invitation existante ; redirection HTML ajoutée |
| `PATCH /admin/stores/{storeId}/status` | Décision existante avec motif obligatoire ; redirection HTML ajoutée |
| `GET /admin/stores/{storeId}/status-events` | API historique existante inchangée |

Seule la route de formulaire `/admin/stores/create` est nouvelle. Les autres contrats de routes restent en place. L'ancien rendu minimal du dashboard dans le contrôleur de session est remplacé par un contrôleur de lecture dédié.

## Fondations et sécurité

Authentification professionnelle existante, **guard `web` explicite**, rôle `SUPER_ADMIN`, compte `ACTIVE`, `store_id` nul et absence de rôles commerçants. Le middleware existant `EnsureSuperAdmin` contrôle aussi les accès directs et les mutations ; il ajoute désormais `private, no-store` et `no-referrer` aux réponses administratives. Aucune policy parallèle ne remplace ce RBAC.

Extraits de contrôle pour une seconde revue :

```php
// routes/web.php : groupe administratif, sans middleware tenant marchand.
Route::middleware(['auth:web', 'admin'])->group(/* routes administratives */);

// EnsureSuperAdmin : l'identité provient du guard professionnel.
$user = $request->user('web');
// Vérifications existantes : ACTIVE, store_id === null, SUPER_ADMIN,
// aucun STORE_OWNER / STORE_MANAGER / CASHIER / DELIVERY_AGENT.

// StoreController::store : données explicitement sélectionnées, transaction existante.
$result = DB::transaction(function () use ($data) {
    // STORE_OWNER requis ; boutique PENDING ; propriétaire INVITED,
    // store_id choisi par le serveur, secret aléatoire casté hashed.
});

// SendOwnerInvitationController : broker existant, jamais de jeton dans la réponse.
$token = Password::broker('users')->createToken($owner);
$owner->notify(new OwnerInvitationNotification($token, $store->name));
```

Les formulaires utilisent les routes web et CSRF existants. Les champs de rôle, statut initial, identité acheteur et `store_id` reçus du navigateur ne sont pas utilisés pour créer les comptes. Les e-mails déjà utilisés sont refusés, avec une vérification supplémentaire insensible à la casse. Aucun utilisateur existant n'est converti, fusionné ou rattaché. Aucun lien `Customer` n'est créé.

Les listes chargent les propriétaires en une relation anticipée, sans requête par boutique. Le dashboard ne lit pas les commandes privées et ne calcule pas de chiffre d'affaires. Les professionnels comptés sont les utilisateurs ayant au moins un des quatre rôles marchands, tous statuts confondus. Le nombre de boutiques désactivées additionne INACTIVE et SUSPENDED si ces dernières existent ; les décisions actuellement proposées restent exactement ACTIVE/INACTIVE selon le backend existant.

## Parcours réel

1. Se connecter avec le compte SUPER_ADMIN autorisé sur `/admin/login`.
2. Créer une boutique : nom, slug unique, informations facultatives réellement disponibles, nom et e-mail **disponible** du nouveau propriétaire.
3. La transaction crée la boutique PENDING, le propriétaire INVITED et son seul rôle STORE_OWNER. Aucun mot de passe n'est affiché ni envoyé.
4. Depuis le détail, envoyer explicitement l'invitation. Le transport de messagerie doit être configuré et opérationnel ; le message de succès indique sa remise au service configuré, sans garantir la livraison finale.
5. Le propriétaire suit le formulaire d'invitation existant, définit son mot de passe d'au moins 12 caractères et active son compte. Le broker stocke un jeton haché, expirant après 60 minutes, à usage unique ; le renvoi invalide l'ancien jeton.
6. Le SUPER_ADMIN active ensuite la boutique avec un motif de 10 à 500 caractères. Le backend exige un propriétaire unique, actif, vérifié et exclusivement STORE_OWNER. La transition et son historique sont transactionnels.

Création et envoi de notification restent deux étapes explicites : une erreur de transport n'annule pas une boutique déjà créée correctement et ne l'active pas. L'administrateur peut retenter l'invitation selon les protections existantes. Le throttle d'envoi reste à trois requêtes par minute. Les pages d'acceptation du propriétaire sont réutilisées sans refonte.

## Comparaison avec la maquette et reports

Header de 74 px, sidebar bleu nuit de 235 px, bandeau vert clair, quatre indicateurs réels, tableaux/cartes, colonne d'actions et répartition réelle. Menu natif accessible, fermeture Échap/clic extérieur, contrôles tactiles d'au moins 44 px, adaptations tablette/mobile. Identité de session affichée sans avatar personnel inventé.

Pas de notifications fictives, demandes publiques, recherche globale, documents d'ouverture, promotions, gestion complète des employés ou rapports financiers. La recherche de boutiques est réellement fonctionnelle dans leur liste. Aucun formulaire de ville/catégorie principale n'est inventé : les champs du backend existant sont repris.

La future demande publique devra disposer de son propre statut/dossier et d'une décision auditée, puis appeler le même parcours de création/attribution après validation. Elle ne devra jamais accepter un rôle, un propriétaire existant ou un statut ACTIVE fournis librement. Ce workflow et sa persistance ne sont pas développés ici.

## Validation et preuves

PHP Laragon 8.3.30 utilisé explicitement (le PHP par défaut du PATH est encore XAMPP 8.2). `phpunit.xml` vérifié, `bootstrap/cache/config.php` absent ; tests exclusivement SQLite `:memory:` avec URL vide, sessions/cache en mémoire. La nouvelle classe retire les connexions autres que SQLite de sa configuration. Les fixtures RBAC des anciens tests restent exclusivement en mémoire ; aucun seeder persistant n'est exécuté.

- Suite ciblée : `AdminInterfaceTest`, `AdminStoreManagementTest`, `AdminStoreStatusTest`, `SuperAdminAuthenticationTest`, `OwnerInvitationFlowTest` : **50 tests / 316 assertions réussis**.
- Test ajouté ensuite d'échec après insertion de boutique : **1 test / 4 assertions réussi**. Total distinct couvert : **51 tests / 320 assertions**. La classe d'interface et l'authentification sont revérifiées après la finalisation du middleware.
- Couverture : refus des rôles marchands/acheteurs, guard buyer seul, compte inactif, cumul de rôles, administrateur rattaché à une boutique, injection de privilèges, doublons, e-mail acheteur préexistant, rollback, filtres/pagination, invitation invalide, expiration, renvoi, usage unique et conditions d'activation.
- Lint PHP, `node --check resources/js/admin.js`, Pint ciblé et `git diff --check` réussis. Pint est limité aux fichiers de cette phase pour préserver les travaux antérieurs.
- Build Vite réussi. Avertissement préexistant : `fontaine` optionnel absent ; aucune dépendance frontend ajoutée. Vite signale aussi les temps des plugins.
- Google Chrome installé : **36 vues** de rendus HTTP autorisés produits par PHPUnit en mémoire, FR aux largeurs 1536/1440/1280/1024/768/390/360 et EN à 1440/390, pour dashboard/liste/création/détail. Aucun débordement, image cassée ou erreur JS ; noms longs et menu clavier contrôlés. Aucun formulaire soumis dans Chrome.
- La vraie page `/admin/login` DEMO répond HTTP 200 et a été capturée en mobile. **Les pages protégées n'ont pas été parcourues avec un compte DEMO : aucun SUPER_ADMIN DEMO qualifié n'existe.** Les captures de ces pages sont des restitutions visuelles de réponses de tests, pas une validation d'une session administrateur persistante.
- Les requêtes du navigateur restent locales et GET/HEAD ; aucune interception ne donne accès à des données protégées DEMO. Contacts masqués dans les captures ; seuls des comptes fictifs créés en mémoire figurent dans les rendus.
- Empreintes logiques de toutes les tables SQLite DEMO identiques avant/après l'audit. Aucun accès MySQL, paiement, migration persistante ou écriture métier DEMO.

Preuves locales exclues de Git : `storage/demo/audits/admin-18b/` (captures, rapport et rendus de tests). Les jetons CSRF des restitutions HTML sont remplacés par des marqueurs inactifs. Ces rendus ne constituent pas des sessions administratives réutilisables. Seules les captures masquées et le rapport sont destinés au partage.

## Accès SUPER_ADMIN DEMO à autoriser séparément

Lecture SQLite en mode READONLY/query_only : **0 compte qualifié** (ACTIVE, store_id nul, SUPER_ADMIN sans rôle marchand). Le seeder DEMO crée des propriétaires marchands et interdit leur rôle SUPER_ADMIN. Aucun mécanisme de provisionnement d'un administrateur DEMO n'a été exécuté, ni aucun compte existant modifié.

Procédure proposée, non exécutée : arrêter les écrivains DEMO ; sauvegarder SQLite de manière cohérente avec WAL et vérifier son intégrité ; utiliser exclusivement `bootstrap/demo.php` avec les gardes `DemoDatabase::assertIsolated()` et le chemin fixe autorisé ; vérifier le rôle SUPER_ADMIN existant ; créer transactionnellement **un nouveau compte fictif** global ACTIVE avec ce seul rôle, sans rôle marchand et sans identité acheteur ; définir un secret local d'au moins 12 caractères via saisie masquée et cast hashed, sans argument de commande, log ou rapport ; tester ensuite la connexion normale `/admin/login`. L'invitation propriétaire n'est pas un mécanisme de provisionnement administrateur et ne doit pas être détournée.

Cette opération nécessite une autorisation explicite distincte. L'envoi SMTP et les parcours persistants création/invitation/activation restent aussi à autoriser et valider séparément sur DEMO. Aucune validation d'exécution MySQL/MariaDB n'est revendiquée.

## Sources à examiner

- Routes : `routes/web.php`, groupe `/admin`.
- Accès : `app/Http/Middleware/EnsureSuperAdmin.php`, `app/Http/Controllers/Admin/Auth/AdminSessionController.php`.
- Lecture : `app/Http/Controllers/Admin/DashboardController.php`.
- Création/décisions : `app/Http/Controllers/Admin/StoreController.php`.
- Invitation : `app/Http/Controllers/Admin/SendOwnerInvitationController.php`, `app/Http/Controllers/OwnerInvitationController.php`, `app/Notifications/OwnerInvitationNotification.php`, `config/auth.php` (ces trois derniers réutilisés sans modification).
- Vues : `resources/views/admin/{layout,navigation,dashboard,login}.blade.php`, `resources/views/admin/stores/{index,create,show,table}.blade.php`.
- Ressources : `resources/css/admin.css`, `resources/js/admin.js`, leurs imports app, `lang/{fr,en}/admin.php`.
- Tests : les cinq classes citées ci-dessus ; seule `AdminInterfaceTest.php` est créée dans cette phase.

## Préparation Boost imposée par AGENTS.md

Laravel Boost 2.10.3 installé en dépendance de développement avec quatre dépendances transitives, selon l'instruction du dépôt. `composer.json`, `composer.lock`, `AGENTS.md`, `CLAUDE.md` et `boost.json` sont concernés. Le premier téléchargement sandbox a échoué ; l'installation autorisée hors sandbox a ensuite réussi. La génération optimisée anormalement longue a été arrêtée, puis un autoload standard a été généré avec succès et la configuration optimize-autoloader initiale rétablie. `boost:install --guidelines --no-interaction` a été exécuté en local avec connexion SQLite mémoire, sans migration.

`config/boost.php` limite Boost à APP_ENV=local et désactive la collecte des logs navigateur. Il reste désactivé en DEMO, tests et production. Aucune configuration `.env` n'est modifiée. Les fichiers générés d'outillage sont à examiner séparément des interfaces avant toute indexation.

Aucune indexation, aucun commit/push, aucun changement de branche. Tous les fichiers 17.4A et 18A sont conservés.

État final : `feat/customer-auth...origin/feat/customer-auth`, 18 fichiers suivis modifiés, 44 nouveaux fichiers, index vide. Ces totaux incluent les travaux antérieurs. La revérification finale interface/authentification réussit avec 21 tests et 133 assertions ; le rendu de test a ensuite été revérifié seul (1 test, 16 assertions) après neutralisation des jetons CSRF des restitutions locales. Ces relances ne s'ajoutent pas au total de tests distincts.

Les messages métier historiques de refus d'activation/invitation restent, pour certains, en français dans les contrôleurs existants ; les nouveaux écrans et leurs libellés sont traduits. La vérification d'une livraison effective des e-mails reste nécessaire avec le transport de messagerie de l'environnement cible.
