# Phase 18B — durcissement et accès DEMO préparés

> Mise à jour ultérieure autorisée : Mailpit est désormais installé et activé localement. Voir `docs/DEMO_MAILPIT_SETUP.md` pour l'état effectif, les contrôles et le mode de désactivation. Les propositions non activées décrites plus bas constituent l'état historique de cette revue.

## Changements réellement appliqués

- `User::canUseSuperAdminAccount()` : ACTIVE, store_id nul, SUPER_ADMIN et aucun autre rôle. La connexion admin utilise ce contrat ; `EnsureSuperAdmin` refuse désormais tout code différent de SUPER_ADMIN, pas seulement les quatre rôles marchands actuels. Le guard reste web. Aucune identité existante n'est modifiée.
- `ProtectInvitationResponse` + `InvitationPrivacy` : Cache-Control private/no-store et Referrer-Policy no-referrer pour les invitations propriétaires et employés. Les routes et les contrôleurs métier sont conservés.
- `bootstrap/app.php` : mêmes en-têtes sur les réponses d'exception, erreurs d'invitation génériques même avec debug actif, préservation des statuts et en-têtes HTTP (notamment Retry-After), validation normale conservée. Les mots de passe et jetons ne sont pas recopiés dans les anciennes saisies de session.
- `RedactInvitationSecrets` et `config/logging.php` : filtres Monolog sur les canaux configurés, masquage des URL d'invitation et des champs sensibles, protection des valeurs connues dans la requête. Les exceptions conservent classe, message filtré et emplacements de pile sans arguments ni objets arbitraires. Pour les QueryException, le SQL et ses bindings sont entièrement omis afin de ne pas exposer les hashes et coordonnées interpolés. Le signalement des exceptions d'invitation reçoit des diagnostics déjà filtrés avant l'appel au logger, y compris si celui-ci devait tomber en secours.

Les règles d'éligibilité des destinataires, expiration, usage unique, stockage haché et définition du mot de passe n'ont pas changé. Aucun transport mail, provisionnement ou migration n'est activé.

## Vérifications

PHP Laragon 8.3.30 explicite. phpunit.xml vérifié : SQLite :memory:, DB_URL vide, sessions/cache de tests en mémoire ; bootstrap/cache/config.php absent. Les deux nouvelles classes de tests refusent tout autre environnement et retirent les autres connexions de leur configuration.

Exécution ciblée : `SuperAdminHardeningTest`, `InvitationPrivacyTest`, `SuperAdminAuthenticationTest`, `OwnerInvitationFlowTest`, `EmployeeInvitationFlowTest`, `EmployeeAuthenticationTest` : **55 tests, 352 assertions réussis**. Après ajout des contrôles Retry-After, de récursion et de confidentialité des erreurs SQL, `InvitationPrivacyTest` a été revérifié : **7 tests, 69 assertions réussis**. Ces résultats ne s'additionnent pas : cette relance recouvre des tests de la première exécution.

Cas couverts : rôle futur inconnu et combinaisons de rôles, accès direct et connexion, comptes inactifs/invités/suspendus, compte rattaché à une boutique, session buyer seule, invitation valide/invalide, erreurs de validation et erreur interne avec debug actif, absence de secret dans les anciennes saisies et journaux de test en mémoire, conservation de la limitation HTTP, non-régression expiration/renvoi/usage unique et comptes professionnels.

Lint PHP sur les onze fichiers PHP concernés, Pint ciblé et git diff --check réussis. Aucun frontend compilé n'est modifié, donc aucun build Vite ou audit Chrome répété. Aucun test MySQL, seeder persistant, migration persistante ou opération sur SQLite DEMO. Les fixtures et migrations de RefreshDatabase restent exclusivement en mémoire.

## Limites à conserver dans la revue

- Les logs d'accès de PHP, Nginx, Apache, proxy, APM et les anciennes traces ne passent pas tous dans Monolog. Ne pas activer le parcours partagé/production avant d'avoir vérifié leur masquage des chemins d'invitation et des paramètres sensibles. Aucune ancienne trace n'est lue, effacée ou réécrite ici.
- Le lien d'activation doit toujours arriver au destinataire et contient un jeton : historique navigateur et boîte de capture restent sensibles. Pas de lien complet dans les captures, rapports ou exports. Les en-têtes ne retirent pas une URL déjà copiée ailleurs.
- Les canaux de logging ajoutés dynamiquement à l'avenir doivent reprendre le tap ; les services tiers/handlers externes nécessitent leur propre validation. Le filtrage est préventif, pas un outil garantissant de reconnaître tout secret arbitraire.
- L'historique transactionnel des décisions de statut reste distinct d'un journal d'audit universel immuable. Ce périmètre n'est pas étendu.
- L'ancien ZIP de revue final-18b est un instantané antérieur à ce durcissement ; il ne représente pas ces nouvelles sources. Aucun nouveau ZIP n'est demandé/créé ici.

## Mailpit : installation Windows proposée, NON EXÉCUTÉE

Le bootstrap DEMO impose actuellement array pour empêcher tout envoi externe. Une variable MAIL_MAILER dans .env.demo ne remplace pas cette garde. Aucun service, binaire ou transport n'est installé/activé dans cette intervention.

La procédure suivante vise Windows x64. Version officielle examinée : v1.32.0. Vérifier l'architecture de la machine et le SHA publié avant tout lancement ; ne pas appliquer le binaire amd64 à une installation ARM64.

Après autorisation d'installation seulement, depuis PowerShell :

```powershell
$mailpitDir = 'C:\laragon\www\zemboa\storage\demo\tools\mailpit\v1.32.0'
if (Test-Path -LiteralPath $mailpitDir) { throw 'Dossier déjà présent : examiner sans écraser.' }
New-Item -ItemType Directory -Path $mailpitDir | Out-Null
$mailpitArchive = Join-Path $mailpitDir 'mailpit-windows-amd64.zip'
Invoke-WebRequest -Uri 'https://github.com/axllent/mailpit/releases/download/v1.32.0/mailpit-windows-amd64.zip' -OutFile $mailpitArchive
$expectedSha = '16915767a6c95dca569336e8f561f91cf355e9a33c57b91deedcc10a4bba7fd4'
if ((Get-FileHash -LiteralPath $mailpitArchive -Algorithm SHA256).Hash -ne $expectedSha) {
    throw 'Empreinte incorrecte : ne pas extraire ni exécuter.'
}
Expand-Archive -LiteralPath $mailpitArchive -DestinationPath $mailpitDir
& (Join-Path $mailpitDir 'mailpit.exe') --version
```

Sources primaires : [installation](https://mailpit.axllent.org/docs/install/), [release v1.32.0 et empreintes](https://github.com/axllent/mailpit/releases/tag/v1.32.0). Le téléchargement n'a pas été exécuté par l'agent.

### Démarrage isolé, après autorisation distincte

Refuser d'hériter de variables MP_* plutôt que de les afficher ou de les effacer silencieusement. Vérifier que 1025 et 8025 ne sont pas occupés et ne pas arrêter d'autres processus pour libérer les ports.

```powershell
if (@(Get-ChildItem Env: | Where-Object { $_.Name -like 'MP_*' }).Count) {
    throw 'Configuration Mailpit héritée : examen nécessaire, sans afficher les valeurs.'
}
if (@(Get-NetTCPConnection -State Listen -LocalPort 1025,8025 -ErrorAction SilentlyContinue).Count) {
    throw 'Un port est occupé : ne pas démarrer.'
}
$mailpitExe = 'C:\laragon\www\zemboa\storage\demo\tools\mailpit\v1.32.0\mailpit.exe'
$mailpitArgs = @(
    '--listen','127.0.0.1:8025',
    '--smtp','127.0.0.1:1025',
    '--allowed-hosts','127.0.0.1:8025',
    '--smtp-allowed-recipients','@example\.test$',
    '--smtp-disable-rdns',
    '--disable-version-check',
    '--block-remote-css-and-fonts',
    '--quiet'
)
$mailpitProcess = Start-Process -FilePath $mailpitExe -ArgumentList $mailpitArgs -WindowStyle Hidden -PassThru
```

Contrôler ensuite les deux listeners : adresse strictement 127.0.0.1, PID du processus lancé. Ouvrir uniquement `http://127.0.0.1:8025`. Aucun service Windows, relais SMTP, forwarding, webhook ou exposition réseau. Le stockage par défaut est temporaire ; ne pas donner de chemin de base ZEMBOA à Mailpit. Ne pas activer de fonctions de vérification/capture de liens.

Ces paramètres sont documentés par les [options officielles](https://mailpit.axllent.org/docs/configuration/runtime-options/) et la [protection HTTP](https://mailpit.axllent.org/docs/configuration/http/). Le [relais SMTP](https://mailpit.axllent.org/docs/configuration/smtp-relay/) ne doit recevoir aucune configuration. Le démarrage et les ports n'ont pas été testés localement ici. La boîte contient des secrets d'activation : ne pas l'exporter.

### Adaptation Laravel minimale à autoriser

Proposition de modification future du **seul bootstrap DEMO** :

1. Introduire un sélecteur dédié, par exemple DEMO_MAIL_TRANSPORT. Absence = array ; valeurs autorisées array/mailpit seulement ; toute autre valeur fait échouer le démarrage.
2. Lire ce sélecteur uniquement depuis la configuration DEMO dédiée. Ne pas rendre modifiables l'hôte, l'URL SMTP, le port ou les credentials via les variables SMTP normales.
3. En mode mailpit explicitement autorisé, remplacer la liste des mailers par un unique SMTP littéral : scheme smtp, host 127.0.0.1, port 1025, username/password nuls, url nulle et aucun fallback. Définir un expéditeur fictif noreply@example.test. En cas d'indisponibilité, afficher l'échec d'envoi ; aucun relais vers un autre serveur ou mailer log.
4. Ajouter une vérification DEMO avant envoi des destinataires To/Cc/Bcc : uniquement des adresses fictives du domaine example.test. Mailpit applique aussi sa liste fermée de réception. Ne jamais reprendre les vraies coordonnées d'un compte existant.
5. Préserver toutes les assertions DemoDatabase, sessions/cache/disques isolés et absence de MySQL. Couvrir en mémoire l'état par défaut, le rejet d'une valeur invalide, la cible SMTP fixe et les destinataires interdits avant activation.
6. Redémarrer le seul serveur DEMO après validation. Tester d'abord un message fictif sans jeton ; ne pas créer de boutique avant que sa réception locale soit confirmée et autorisée.

Le sélecteur proposé n'existe pas encore : ne pas prétendre activer Mailpit en ajoutant simplement cette variable aujourd'hui. Aucun changement de .env ou .env.demo.example n'est fait ici.

## Provisionnement et autorisations attendues

La procédure de `SUPER_ADMIN_DEMO_ACCESS_REVIEW.md` reste valable pour la sauvegarde et les assertions de cible. Il faut une autorisation explicite pour :

1. Télécharger/exécuter Mailpit et préparer l'adaptation DEMO ci-dessus, puis l'activer uniquement après ses contrôles.
2. Sauvegarder SQLite DEMO de façon cohérente avec WAL, vérifier l'intégrité et préparer un outil CLI ciblé, sans migrations/seeders.
3. Créer transactionnellement un seul nouveau compte fictif, ACTIVE, store_id nul, is_buyer=false et rôle exclusivement SUPER_ADMIN. Arrêter sur collision ; ne pas convertir de compte existant. Mot de passe local robuste par saisie masquée et confirmation, jamais en argument, fichier en clair ou rapport ; cast hashed du User. Aucune session préchargée.
4. Autoriser séparément les écritures du parcours boutique/propriétaire : création PENDING/INVITED, envoi uniquement en boîte locale, acceptation normale puis activation motivée. Connexion admin sur /admin/login ; propriétaire sur /login, puis /espace-professionnel. Deux profils temporaires distincts, aucun paiement.

Aucun compte n'est créé et aucun transport n'est activé ici. MySQL, SQLite DEMO persistante, rôles/permissions et utilisateurs existants restent intacts. Aucun commit/push/indexation ni changement de branche.

## Fichiers de cette intervention

Modifiés : `app/Models/User.php`, `app/Http/Middleware/EnsureSuperAdmin.php`, `app/Http/Controllers/Admin/Auth/AdminSessionController.php`, `bootstrap/app.php`, `config/logging.php`, `routes/web.php`.

Créés : `app/Http/Middleware/ProtectInvitationResponse.php`, `app/Support/InvitationPrivacy.php`, `app/Logging/RedactInvitationSecrets.php`, `tests/Feature/SuperAdminHardeningTest.php`, `tests/Feature/InvitationPrivacyTest.php`, ce document.
