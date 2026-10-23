# SQLite DEMO — application autorisée du 23 octobre 2026

Branche feat/customer-auth. Périmètre exclusif :
C:/laragon/www/zemboa/database/zemboa_demo.sqlite. Aucun accès MySQL.

## Précontrôles et sauvegarde

- Serveur DEMO identifié sur 127.0.0.1:8016 puis arrêté ; absence de processus PHP
  DEMO et de listener confirmée avant application. Aucun WAL/SHM/journal résiduel.
- Chemins et liens vérifiés ; espace disque confirmé via DriveInfo (Get-PSDrive ne
  renseignait pas Free dans cet environnement).
- Lecture sous garde Windows interdisant les écrivains : 50 migrations appliquées,
  exactement les deux autorisées absentes, intégrité ok et zéro violation FK.
- Précontrôle agrégé des identités acheteurs : aucune collision téléphonique empêchant
  la migration. Aucun contact, mot de passe ou secret affiché dans les résultats.
- Image à froid cohérente produite sous garde, puis sauvegarde autonome créée via
  l'API backup SQLite, vérifiée contre les empreintes de toutes les colonnes historiques
  des 42 tables de données (hors table technique migrations).

Sauvegarde vérifiée, 577 536 octets :

```text
C:/laragon/www/zemboa/storage/demo/backups/checkout-20261023-fee4b19b815a/zemboa_demo.pre-migration.sqlite
```

Le même dossier conserve snapshot.sqlite, validation.sqlite, empreintes de comparaison,
scripts locaux et preuves JSON. Il est exclu de Git ; ne pas l'inclure dans une archive
publique de code, car les bases contiennent des comptes et leurs données.

## Validation puis application

La copie validation.sqlite a été migrée via un bootstrap indépendant qui ne charge aucun
fichier d'environnement et n'autorise que cette connexion SQLite. Les garde-fous DEMO
n'ont pas été détournés pour lui faire accepter une copie à un autre chemin.

Ordre exécuté sur copie, puis sur DEMO :
1. 2026_10_22_010000_allow_phone_buyer_identity_on_users_table
2. 2026_10_23_000000_add_buyer_checkout_identity_to_orders_table

is_buyer n'a pas été rejouée. Sur DEMO, bootstrap/demo.php, DemoDatabase et
DemoConnectionFactory ont été utilisés inchangés. La cible a été vérifiée dans la
configuration et PRAGMA database_list juste avant l'exécution. Un verrou SQLite
EXCLUSIVE a été conservé entre les transactions pendant la migration ; aucune transaction
englobante n'a contourné la gestion des FK de la migration téléphonique. Le migrateur
Laravel a reçu exclusivement sqlite_demo et les deux chemins approuvés.

Un contrôle préalable s'est arrêté avant connexion car PowerShell avait enregistré le
SHA-256 en majuscules et PHP le produisait en minuscules. L'égalité du fichier a été
confirmée indépendamment ; seule la comparaison hexadécimale du script local a été
corrigée. Aucune migration échouée n'a été relancée et aucune restauration exécutée.

Résultats sur copie ET DEMO :
- 52 migrations appliquées, 0 en attente ; historique précédent conservé.
- integrity_check = ok ; foreign_key_check vide ; FK actives après migration.
- Email nullable, projection virtuelle et index UNIQUE acheteur vérifiés.
- Colonnes checkout, index UNIQUE par boutique/clé et FK propriétaire RESTRICT vérifiés.
- Les empreintes des colonnes historiques des 42 tables sont identiques ; comptes,
  rôles/associations, boutiques, produits, variantes, commandes, stocks et autres données
  conservés. Les anciennes commandes n'ont reçu ni propriétaire ni clé checkout.
- Sauvegarde d'avant migration toujours valide et conservée à 50 migrations.

## Disponibilité du checkout sans création de commande

Serveur redémarré en arrière-plan avec tools/demo.php serve, uniquement en loopback.
Vérifications HTTP avec une session temporaire : boutique 200, checkout vide 200,
ajout d'une unité au panier de session 200, préparation JSON 200, page HTML checkout
200 avec formulaire et bouton de confirmation. Aucun POST de confirmation envoyé.
Les en-têtes Accept ont été explicitement séparés entre JSON et HTML pour le contrôle.

Le rendu invité et le rendu buyer prérempli ont également été contrôlés directement
dans l'application DEMO, avec session array éphémère et un buyer existant via setUser :
aucun login de navigateur ni mot de passe lu, aucun compte créé/modifié, aucune session
d'authentification usurpée laissée au navigateur. Ce contrôle de rendu ne constitue pas
une validation visuelle Chrome de la connexion. Un contrôle final des 42 tables confirme
qu'aucun Customer/ordre/paiement/réservation n'a été créé ou modifié.

L'expiration reste désactivée ; aucune commande d'expiration, aucun seeder ou paiement.
La validation MySQL/MariaDB reste distincte et non exécutée.

## Inspection manuelle autorisée sans confirmer

1. Ouvrir http://127.0.0.1:8016/boutiques/demo-douala-atelier/produits
2. Ajouter un produit au panier, ouvrir le panier puis « Votre commande ».
3. Checkout : http://127.0.0.1:8016/boutiques/demo-douala-atelier/commande
4. Pour le préremplissage buyer, utiliser un compte DEMO existant via /client/connexion.
5. Examiner le formulaire, ne pas confirmer de commande avant autorisation distincte.

Les services DEMO actifs observés permettent actuellement le retrait et non la livraison
dans les quatre boutiques : l'absence d'option livraison est conforme aux paramètres,
pas une panne du checkout. Aucun paramètre n'a été modifié pour inventer ce service.

Si le serveur est arrêté plus tard, relancer depuis la racine avec :

```powershell
& 'C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' tools/demo.php serve
```

Aucun commit, push, changement de branche, indexation ou modification métier/visuelle
effectué dans cette intervention. git diff --check réussi. Les scripts et sauvegardes
locaux restent hors Git ; ce compte rendu est le seul nouveau document versionnable.
