# Mailpit local — ZEMBOA DEMO

## Installation vérifiée et état local

Mailpit **1.32.0**, Windows amd64, installé dans `storage/demo/tools/mailpit/v1.32.0/mailpit.exe` (ignoré par Git). Source : [release officielle](https://github.com/axllent/mailpit/releases/tag/v1.32.0).

Archive officielle `mailpit-windows-amd64.zip`, téléchargée par HTTPS : SHA-256 `16915767a6c95dca569336e8f561f91cf355e9a33c57b91deedcc10a4bba7fd4`, vérifié contre le digest publié par GitHub. Le binaire n'est pas signé Authenticode : il s'agit d'une vérification d'intégrité et de provenance HTTPS, pas d'une signature indépendante de l'éditeur.

L'ancienne instance Laragon 1.22.3 utilisait `::` pour son interface Web. Son processus a été arrêté après identification, sans modifier Laragon ni installer de service. La nouvelle instance utilise exclusivement `127.0.0.1:1025` (SMTP) et `127.0.0.1:8025` (Web). Ne pas redémarrer simultanément le Mailpit intégré à Laragon.

## Démarrage et activation explicite

Depuis la racine : `powershell -NoProfile -File tools/demo-mailpit.ps1`. Le script refuse les ports occupés, les variables héritées `MP_*` et un binaire différent de celui vérifié. Il ne télécharge rien et ne tue aucun processus. La lecture des ports peut nécessiter des droits Windows supplémentaires ; un refus arrête le script.

Les [options officielles](https://mailpit.axllent.org/docs/configuration/runtime-options/) sont fixées dans le script : loopback, liste de destinataires `@example.test`, contrôle Host, pas de recherche DNS inverse, ni vérification automatique de version. Aucun relais, forwarding, webhook, authentifiant ou fichier de configuration externe n'est transmis.

Dans le seul `.env.demo`, mettre `DEMO_MAIL_TRANSPORT=mailpit`. Ce sélecteur a été activé localement pour le contrôle autorisé. Absence du sélecteur ou `array` = transport mémoire ; toute autre valeur est refusée. `.env.demo.example` conserve `array`. Ne pas modifier `.env`, `config/mail.php` ou les paramètres SMTP normaux. Redémarrer uniquement le lanceur DEMO si son processus conserve une application déjà chargée.

Le bootstrap remplace intégralement la configuration mail : un seul transport, SMTP littéral `127.0.0.1:1025`, sans URL, credentials, TLS automatique, failover ou transport externe. L'expéditeur est fictif. Avant envoi, l'environnement DEMO, la configuration et tous les champs d'adresses To/Cc/Bcc/From/Reply-To/Sender/Return-Path sont contrôlés ; seuls les domaines exacts `example.test` sont acceptés. Une indisponibilité SMTP échoue sans secours externe. Le parcours métier des invitations reste inchangé.

## Vérifications effectuées

- Listeners effectifs uniquement `127.0.0.1` ; interface HTTP 200.
- Un destinataire synthétique hors `example.test` a été refusé en SMTP avec le code 550, avant toute commande DATA.
- Un message technique sans compte, invitation, donnée métier ou jeton a été envoyé via le vrai bootstrap DEMO et retrouvé une fois dans Mailpit. Pendant ce contrôle, toute création de connexion Laravel était explicitement interdite par une factory de vérification en mémoire.
- Tests ciblés de configuration, mode invalide, destinataire caché externe, environnement interdit, configuration altérée et transport array : 7 tests, 14 assertions ; aucune connexion persistante nécessaire.

## Limites et arrêt

Aucun service permanent : le processus doit être relancé après arrêt/redémarrage Windows. Mailpit utilise son stockage temporaire propre, jamais une base ZEMBOA. Sa boîte peut perdre ses messages à l'arrêt. L'accès loopback reste accessible aux autres programmes/utilisateurs locaux ; ce n'est pas un coffre-fort multi-utilisateur.

Les futurs liens d'invitation seront sensibles : ne pas exporter la boîte, copier les liens dans un rapport ou capturer leur contenu. Ne pas utiliser de vérification de liens, captures distantes, relais ou forwarding. Le blocage CSS/polices distantes ne bloque pas toutes les images externes : utiliser uniquement les modèles DEMO locaux et ne pas cliquer de liens externes.

Pour désactiver Laravel Mailpit : remettre uniquement `DEMO_MAIL_TRANSPORT=array` dans `.env.demo`. Pour arrêter Mailpit, identifier à nouveau son PID et son chemin avant d'arrêter ce seul processus. Aucun compte privilégié, invitation réelle ou parcours d'écriture DEMO n'est autorisé par cette installation.
