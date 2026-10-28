# Provisionnement local du premier SUPER_ADMIN DEMO

## État de livraison

Outil préparé uniquement : **ne pas exécuter avant validation du propriétaire**. Aucun accès ni provisionnement sur SQLite DEMO n'est réalisé pendant cette préparation. Aucun mécanisme d'authentification nouveau : modèle User, cast Laravel `hashed`, relation roles et contrat `canUseSuperAdminAccount()` existants.

## Commande ultérieure

Ouvrir **son propre Windows PowerShell interactif**, sans profil, transcription, redirection ou capture, puis :

```powershell
cd C:\laragon\www\zemboa
powershell.exe -NoProfile -File .\tools\demo-super-admin.ps1
```

Ne pas modifier globalement la politique d'exécution. Si Windows bloque un script local, demander une procédure approuvée plutôt que contourner la protection. Aucun mot de passe dans la commande.

Arrêter d'abord le serveur DEMO depuis son terminal avec Ctrl+C, ainsi que les workers, schedulers, autres serveurs PHP et éditeurs SQLite. Le script refuse tout processus `php`/`php-cgi` et toute écoute sur 8016 ; il ne les termine pas. Il demande aussi une confirmation explicite de fermeture des autres écrivains. Une erreur de lecture des processus/ports interrompt l'opération.

## Canal privé

Deux saisies `Read-Host -AsSecureString`, minimum 12 caractères (même règle existante), confirmation exacte ; maximum technique 1024. Les SecureString sont convertis temporairement en mémoire pour alimenter une entrée standard anonyme redirigée vers le seul PHP Laragon 8.3.30. Aucun secret en argv, variable d'environnement, fichier, historique ou sortie. La sortie PHP est générique en cas d'erreur : aucune exception détaillée ou binding SQL n'est imprimé/journalisé par cet outil.

Le script refuse les consoles redirigées, les politiques de capture PowerShell détectées et un debugger .NET attaché ; il désactive la trace PowerShell. Il ne faut pas l'utiliser dans une console enregistrée ou sous débogage. Les BSTR et le tampon d'octets sont effacés en finally et les SecureString libérés. Les chaînes managées .NET/PHP peuvent subsister jusqu'au ramasse-miettes : aucun outil ne garantit la confidentialité contre un administrateur local malveillant, un enregistreur clavier ou un dump mémoire. En cas de doute sur le poste, ne pas poursuivre. La saisie interactive réelle doit encore être validée par l'opérateur ; le terminal Codex n'en permet pas l'essai fiable.

## Cible et transaction

Le script PHP n'est pas une commande Artisan générale et ne doit pas être lancé directement. Il charge exclusivement `bootstrap/demo.php`. Les gardes existantes imposent environnement DEMO, connexion unique sqlite_demo, SQLite, absence d'URL alternative, chemin et liens vérifiés. La cible absolue doit être `C:/laragon/www/zemboa/database/zemboa_demo.sqlite`.

Les migrations et seeders ne sont jamais appelés. Une transaction Laravel autonome, sans retry, est obligatoire. Sur PHP 8.3, PDO ignore le mode de transaction Laravel : `UPDATE roles SET code = code WHERE 0` acquiert le verrou écrivain sans changer de ligne. Le verrou est retenu jusqu'au commit/rollback. Un écrivain concurrent entraîne attente bornée par la configuration DEMO puis arrêt, sans retry.

Contrôles préalables : intégrité, clés étrangères, rôle SUPER_ADMIN existant et unique ; aucun utilisateur portant déjà ce rôle (même inactif ou non qualifié), aucune collision d'e-mail insensible à la casse. Aucun rôle n'est créé et aucun utilisateur existant n'est promu.

## Sauvegarde cohérente

Sous verrou écrivain et avant toute création, l'API native `SQLite3::backup()` copie la source ouverte en lecture seule, y compris ses pages WAL visibles, vers un nouveau fichier exclusif :

`storage/demo/backups/before-super-admin-<dateUTC>-<suffixe-aléatoire>.sqlite`

Les chemins sont contrôlés par DemoDatabase ; aucun fichier existant n'est écrasé. La sauvegarde est vérifiée avec `integrity_check` et `foreign_key_check`, puis son chemin est affiché. Aucun WAL/SHM n'est supprimé ou copié à l'aveugle. L'extension sqlite3, en plus de pdo_sqlite, est requise. Sauvegardes et données restent sous storage/demo ignoré par Git et doivent rester privées sur le poste.

## Création autorisée lors de l'exécution future

Identifiant fixe fictif : **super-admin.demo@example.test**. Nom DEMO explicite, ACTIVE, is_buyer=false, store_id=null, phone=null, un seul rôle SUPER_ADMIN préexistant. Aucune permission ajoutée ou modifiée, aucune notification/invitation envoyée.

Avant commit : vérification du contrat commun de connexion/middleware, rôle unique, hash Laravel et empreintes en mémoire de toutes les tables applicatives, excluant uniquement le nouvel utilisateur et son association rôle. Les anciens utilisateurs, boutiques, commandes, invitations, permissions et autres enregistrements doivent être inchangés. Les tables internes SQLite (dont les compteurs de séquence) sont exclues des empreintes. Aucun contenu ou hash de ces données n'est affiché.

Sur échec, la transaction est annulée ; une éventuelle sauvegarde, complète ou incomplète, est conservée. Ne pas restaurer/rejouer automatiquement. Un échec système pendant le commit peut rendre le résultat incertain : demander un diagnostic avant toute nouvelle tentative. La restauration exige une décision distincte et les processus arrêtés ; préserver l'original et ses sidecars pour diagnostic.

## Connexion après succès

Relancer uniquement le lanceur DEMO existant avec le PHP Laragon prévu, ouvrir `http://127.0.0.1:8016/admin/login`, saisir soi-même l'e-mail ci-dessus et le mot de passe choisi. Accéder au dashboard puis utiliser sa déconnexion normale. Aucun utilisateur n'est injecté dans une session. Ne pas créer de boutique ou d'invitation sans autorisation séparée. Aucun paramètre Mailpit n'est modifié.

## Vérifications de préparation

Huit tests ciblés réussis, 17 assertions, exclusivement SQLite `:memory:` après contrôle de phpunit.xml et de l'absence de cache config. Cas : création unique conforme et préservation des anciens enregistrements/permissions ; compte SUPER_ADMIN existant même inactif ; collision d'identité ; échec de sauvegarde sans création ; environnement non DEMO ; rollback après invariant final invalide ; mot de passe trop court ; API backup entre deux bases en mémoire. Les tests antérieurs du contrat d'accès administrateur ne sont pas répétés.

Le transfert SecureString → BSTR temporaire → JSON UTF-8 → stdin PHP a été vérifié avec une valeur fictive non sensible, sans bootstrap ni base. Lint PHP, formatage ciblé, analyse syntaxique PowerShell et git diff --check réussis. Le masquage interactif réel, la sauvegarde WAL sur le fichier DEMO et le provisionnement persistant restent volontairement non exécutés ; ils seront contrôlés lors de l'étape autorisée ultérieure.
