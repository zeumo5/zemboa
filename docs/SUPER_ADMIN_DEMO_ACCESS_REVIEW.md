# Contrôle final 18B — accès SUPER_ADMIN DEMO proposé

## Portée et résultat

Revue statique des sources actuelles, sans modification applicative, accès MySQL, lecture/écriture SQLite, migration, seeder, compte, envoi d'e-mail ou nouvelle exécution de PHPUnit. Les 51 tests / 320 assertions précédemment réussis restent les preuves d'exécution ; ils ne prouvent ni une validation concurrente multiconnexion ni une exécution MySQL. Les travaux 17.4A et 18A sont préservés.

Aucune élévation de privilèges publiquement exploitable identifiée dans le périmètre inspecté. Le parcours manuel complet reste **bloqué par l'absence de compte administrateur DEMO et de réception locale des invitations**. Les limites ci-dessous interdisent de conclure à une traçabilité absolue ou à une absence universelle de fuite.

## Règles vérifiées et références

| Règle | Source / preuve existante |
| --- | --- |
| Guard professionnel `web`, compte ACTIVE, store_id nul, SUPER_ADMIN, refus des quatre rôles marchands | `routes/web.php` groupe admin ; `Admin/Auth/AdminSessionController::store` ; `EnsureSuperAdmin::handle` ; `SuperAdminAuthenticationTest`, `AdminInterfaceTest` |
| Session buyer seule refusée, non-administrateurs et comptes désactivés refusés | `auth:web` puis `admin` sur toutes les routes administratives métier ; tests négatifs ci-dessus |
| Inscription publique sans attribution de rôle | `RegisterBuyerRequest`, `RegisterBuyer::execute` : champs explicitement choisis, aucun attach/sync de rôle ; User fillable n'inclut ni rôles ni store_id/is_buyer |
| Gestion employés sans élévation SUPER_ADMIN | `EmployeeController` : liste fermée de trois rôles employés, protection des propriétaires et SUPER_ADMIN, contrôle tenant/propriétaire |
| Boutique PENDING et propriétaire INVITED, rôle STORE_OWNER uniquement | `Admin/StoreController::store` : validation, champs serveur, transaction et rôle préexistant ; tests d'injection et de rollback |
| Slug et e-mail uniques | Validations + contraintes SQL des migrations stores/users ; précontrôle e-mail insensible à la casse ; aucun rattachement d'un utilisateur existant |
| Invitation liée à un propriétaire unique de la boutique ciblée | `SendOwnerInvitationController::__invoke` : store_id serveur, statut boutique PENDING/ACTIVE, propriétaire INVITED non vérifié, aucun rôle autre que STORE_OWNER |
| Jeton aléatoire, haché, expirant et supprimé après reset | Broker `users` dans `config/auth.php` (60 minutes) ; `OwnerInvitationController` ; `PasswordBroker` et `DatabaseTokenRepository` installés ; tests usage unique/expiration/renvoi |
| Mot de passe défini par le destinataire | `OwnerInvitationController::accept` : confirmation et minimum 12, nouvelle vérification sous verrou utilisateur, cast hashed du modèle User ; aucun mot de passe dans les réponses de création |
| Activation conditionnelle et désactivation contrôlée | `Admin/StoreController::updateStatus` : ACTIVE/INACTIVE uniquement, motif 10–500, propriétaire unique ACTIVE vérifié et exclusivement OWNER pour activer |
| Historique et statut atomiques | Même transaction avec verrou boutique et insert store_status_events ; test `test_audit_failure_rolls_back_store_status` avec trigger d'échec en mémoire |
| Pas d'effacement/édition de l'historique via HTTP | Recherche routes/contrôleurs : lecture seule des événements ; aucune route d'édition/suppression. Pas de route alternative identifiée permettant au SUPER_ADMIN de modifier le statut sans motif |
| Réponses administratives privées | `EnsureSuperAdmin` : private, no-store et no-referrer ; échappement Blade ; collections JSON explicitement limitées sans mot de passe/jeton |
| Dashboard marchand isolé | `Professional/DashboardController`, `OrderPolicy`, `SetTenantContext`, scope `BelongsToStore` ; `ProfessionalDashboardTest` |

Les contrôleurs administratifs utilisent leur middleware RBAC existant, pas une nouvelle StorePolicy. Les décisions métier sont encore dans StoreController : ne pas prétendre réutiliser une action CreateStore séparée qui n'existe pas.

## Problèmes et limites classés

### Importante — invitation DEMO non récupérable actuellement

`bootstrap/demo.php` impose `mail.default=array` et un unique transport array, indépendamment d'une éventuelle valeur MAIL_* de `.env.demo`. Le message reste en mémoire dans la requête et disparaît ensuite. Un succès d'envoi ne signifie donc pas que le propriétaire peut recevoir/ouvrir son lien. Ne pas remplacer cela par un e-mail externe ou des jetons publiés dans les logs.

Solution à autoriser séparément : boîte mail de capture locale telle que Mailpit, sans relais externe, SMTP sur 127.0.0.1:1025 et interface uniquement 127.0.0.1:8025 si disponibles. Adapter explicitement le seul bootstrap DEMO, sans repli vers SMTP externe ni mailer log. La boîte contient des invitations sensibles : accès local seulement, pas de captures/export de leurs URL. Le mécanisme Laravel de notification et d'acceptation reste inchangé. Disponibilité/installation de Mailpit non vérifiée dans cette revue.

### Moyenne — journal de décisions, pas journal d'audit universel immuable

Les transitions ACTIVE/INACTIVE HTTP ne peuvent pas ignorer le motif ou réussir si l'écriture d'historique échoue. En revanche, création de boutique, invitation, authentification et acceptation ne produisent pas tous un événement dans ce journal. Aucun mécanisme SQL append-only ou stockage externe inviolable n'est prévu. Un rôle applicatif SUPER_ADMIN n'accorde aucun accès SQL ; un opérateur ayant séparément accès à la base peut la modifier hors de ces routes. Ne pas présenter cet historique comme une preuve anti-altération globale.

### Moyenne — confidentialité des URL d'invitation à durcir avant production

La page publique `owner-invitations.accept` et son contrôleur n'imposent pas explicitement les en-têtes no-store/no-referrer appliqués aux pages admin. Le jeton et l'e-mail figurent dans l'URL normale d'invitation ; historique navigateur, journaux HTTP ou captures peuvent les contenir. Aucune fuite effective n'a été recherchée dans les logs privés ou observée ici. Avant essai partagé/production : ajouter les protections adaptées et vérifier la suppression/masquage des chemins d'invitation dans les journaux d'accès, HTTPS en production, aucune ressource tierce ni export d'URL. Le hachage SQL du jeton ne protège pas une URL copiée.

### Faible — exclusivité du rôle fondée sur la liste actuelle

L'authentification et le middleware refusent les quatre rôles marchands connus. Ils n'exigent pas littéralement que la relation roles ne contienne que SUPER_ADMIN : un futur rôle non listé ne serait pas rejeté. Les cinq rôles du RoleSeeder actuel sont couverts. Prévoir un refus de tout autre rôle si l'exclusivité doit rester une garantie générale lors de futures extensions. Aucune modification effectuée ici.

### Faible — concurrence et ergonomie des doublons

Les contraintes SQL protègent les doublons exacts et la transaction évite une boutique partielle. Une collision survenant après validation peut remonter en erreur SQL plutôt qu'en validation lisible. Le contrôle LOWER(email) n'est pas à lui seul un index SQL unique insensible à la casse sur SQLite ; les sémantiques de collation MySQL diffèrent. Ne pas déduire des tests séquentiels une validation de ces courses ou une compatibilité MySQL démontrée.

## Vérification Boost

Comparaison avec HEAD : seul `laravel/boost: *` est ajouté à require-dev. Aucune modification des scripts Composer existants ni de leurs commandes ; notamment aucun script de migration ajouté par Boost. Les scripts setup/post-create-project qui peuvent migrer existaient déjà et ne doivent pas être lancés pour cet audit.

Nouveaux paquets du lock, tous dans packages-dev :

| Paquet | Version |
| --- | --- |
| laravel/boost | v2.10.3 |
| laravel/mcp | v1.1.1 |
| laravel/roster | v1.0.0 |
| composer/semver | 3.5.2 |
| symfony/yaml | v7.4.21 |

`AGENTS.md` et `CLAUDE.md` ont reçu les mêmes guidelines ; `boost.json` active uniquement leur génération. Aucun serveur MCP distant, identifiant ou configuration de navigateur personnel ajouté. Aucune modification bootstrap/app.php ou bootstrap/demo.php par cette installation.

`config/boost.php` : enabled seulement si APP_ENV vaut exactement local et BOOST_ENABLED l'autorise ; browser_logs_watcher=false. Le provider installé retourne avant d'enregistrer MCP/outils/routes quand enabled=false ; la route de collecte et l'injection navigateur sont aussi conditionnées au watcher. Le bootstrap DEMO force APP_ENV=demo, donc Boost y reste désactivé. Aucun cache config standard/DEMO n'était présent lors du contrôle. En production correctement configurée APP_ENV=production, Boost est désactivé, même avec debug=true ; il faut aussi déployer sans dépendances dev et générer le cache sous l'environnement de production. Ce contrôle de code ne vaut pas inspection d'un serveur de production.

Ces dépendances ne sont pas nécessaires au fonctionnement marchand : elles proviennent de l'installation imposée par l'ancien AGENTS.md. Elles peuvent être séparées du commit fonctionnel lors d'une future revue Git. La contrainte `*` est trop large pour une future mise à jour volontaire ; proposer de la borner dans une intervention autorisée, sans modifier ici Composer ni le lock.

## Provisionnement SUPER_ADMIN DEMO proposé — NON EXÉCUTÉ

Il n'existe pas de commande de provisionnement administrateur dans `tools/demo.php` (help/check/key/create/migrate/seed/serve seulement). Le seeder DEMO ne doit pas être détourné, et l'invitation propriétaire n'est pas un outil de création SUPER_ADMIN. Le constat antérieur est l'absence de compte qualifié ; aucune nouvelle interrogation de base dans cette revue.

Après autorisation explicite distincte :

1. Arrêter les processus susceptibles d'écrire dans DEMO. Ne pas arrêter d'autres services ni supprimer WAL/SHM.
2. Vérifier via les gardes existantes le chemin absolu `C:/laragon/www/zemboa/database/zemboa_demo.sqlite`, les liens et la connexion unique sqlite_demo. Refuser si une connexion alternative est disponible, si une garde échoue ou si une migration serait nécessaire.
3. Faire une sauvegarde SQLite cohérente avec l'API backup, tenant compte du WAL ; vérifier integrity_check et foreign_key_check, préserver la sauvegarde hors Git. Une simple copie du fichier actif ne suffit pas.
4. Préparer un outil CLI ciblé reposant exclusivement sur bootstrap/demo.php et DemoDatabase::assertIsolated(), avec assertion de cible répétée juste avant écriture. Aucun nouvel outil exécutable de provisionnement n'est créé dans cet audit.
5. Utiliser une identité fictive distincte, par exemple nom « DEMO — Super administrateur », e-mail réservé `super-admin-demo@example.test`, jamais celui de l'acheteur. Vérifier que l'adresse est libre sans lire/afficher les données privées des autres comptes. En cas de collision, arrêter : ne pas réutiliser, convertir, réinitialiser ou enrichir un compte existant.
6. Vérifier qu'un unique rôle SUPER_ADMIN existe. Le RBAC actuel autorise les actions admin par rôle, sans permission granulaire supplémentaire : attacher uniquement ce rôle, aucune permission/rôle marchand additionnel, aucun changement des associations globales du rôle.
7. Saisir et confirmer un mot de passe robuste local via une invite masquée, au moins 12 caractères (20+ recommandés), jamais via arguments CLI, variable exportée, fichier partagé ou rapport. Ne pas réutiliser le secret des propriétaires DEMO. Le cast hashed du User réalise le stockage sécurisé.
8. Dans une seule transaction, créer uniquement le nouveau User : store_id=null, status=ACTIVE, is_buyer=false, phone=null, preferred_locale choisi ; définir explicitement la date de vérification technique si elle est retenue pour ce compte administré fictif (cela ne prouve aucune vérification réelle par e-mail). Attacher exactement SUPER_ADMIN. Ne créer ni Customer ni boutique, ni jeton d'invitation.
9. Vérifier après écriture les seuls invariants de ce compte, la cohérence et l'absence de modifications étrangères, sans afficher mot de passe/hash/cookie. Ne pas recréer ou modifier le compte lors d'une seconde exécution ; arrêter et demander une décision.
10. Redémarrer le lanceur DEMO existant sur 127.0.0.1:8016 et utiliser le formulaire normal `/admin/login`. Aucune session préchargée ni désactivation de middleware. Conserver les secrets uniquement dans le gestionnaire local du propriétaire.

Autorisation requise : sauvegarde/provisionnement ciblé et validations associées ; aucune de ces étapes n'est réalisée ici.

## Parcours manuel après autorisations

Prérequis distincts : compte DEMO provisionné ; réception des invitations en boîte locale autorisée ; création/acceptation/activation de données fictives explicitement autorisée. Aucun envoi extérieur ni mailer log. Ouvrir deux profils temporaires séparés (administrateur / propriétaire) afin de ne pas confondre le guard web partagé.

1. Profil administrateur : ouvrir `http://127.0.0.1:8016/admin/login`, se connecter avec le compte fictif, vérifier `/admin`.
2. Ouvrir Boutiques > Créer ; utiliser un nom DEMO, un slug neuf, une adresse propriétaire fictive disponible. Ne pas utiliser le compte acheteur ou son e-mail.
3. Confirmer la création ; constater PENDING et INVITED, aucune activation ni commande créée.
4. Envoyer l'invitation une seule fois. Ouvrir la boîte de capture locale uniquement sur loopback. Ne copier le lien que dans le profil propriétaire ; ne pas le transmettre dans un rapport/capture.
5. Profil propriétaire : ouvrir l'invitation dans les 60 minutes et définir un mot de passe personnel DEMO d'au moins 12 caractères. Constater le compte activé mais la boutique encore PENDING. Le lien ne doit plus être réutilisable.
6. Profil administrateur : revenir au détail ; choisir ACTIVE et saisir un vrai motif de démonstration (10–500 caractères). Vérifier le changement et l'événement old/new, acteur et date via l'API d'historique existante. L'UI historique affiche actuellement statuts, motif et date ; l'identifiant acteur est conservé en base/API.
7. Profil propriétaire : ouvrir `/login` (et non `/admin/login` ni `/client/connexion`), se connecter par e-mail et mot de passe définis.
8. Vérifier `/espace-professionnel`, le nom de la nouvelle boutique et les indicateurs vides attendus. Aucune permission n'est ajoutée au rôle OWNER : ses permissions existantes doivent inclure orders.view pour ce dashboard ; sinon arrêter sans corriger globalement les rôles.
9. Éventuel contrôle négatif : tentative d'activation prématurée, rôle non autorisé, invitation expirée, désactivation avec historique seulement si ces écritures/essais sont aussi autorisés. Aucun paiement, achat ou modification de stock.

La restitution du mail en boîte locale et son lien d'acceptation doivent être opérationnels avant de créer la boutique de test ; sinon arrêter pour éviter des données DEMO inutiles. Aucun nettoyage ni restauration automatique n'est proposé.

## Archive de seconde revue

Archive locale prévue sous `storage/demo/audits/final-18b/`, exclue de Git. Sélection explicite de sources actuelles, dépendances de vues, configurations sans secrets, manifestes, migrations/factories et seeders RBAC de test. Le ZIP est un lot de revue ciblé, pas un export de données ni une installation autonome complète. Aucun vendor/node_modules/build, `.env`, base, sauvegarde, session, log, rendu HTML d'audit, capture contenant des identifiants, upload ou secret n'y figure. Les mots de passe fictifs explicites des tests ne sont pas des identifiants de comptes persistants.

Un manifeste SHA-256 permet de rattacher chaque entrée aux sources exactes contrôlées. Le script d'archivage refuse les liens/reparse points, les chemins hors dépôt et les extensions interdites ; il signale les motifs de secrets sans afficher leurs valeurs.
