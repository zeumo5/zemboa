# Corrections ciblées après revue indépendante — 18A/18B

Le rapport indépendant annoncé n'était pas joint à cette instruction. Cette intervention traite les trois points explicitement décrits et vérifie leurs correspondances dans les sources actuelles, sans audit général.

## Contrat SUPER_ADMIN unique

`User::canUseSuperAdminAccount()` exige désormais ACTIVE, **is_buyer strictement false**, store_id nul, présence de SUPER_ADMIN et absence de tout autre rôle. La connexion `AdminSessionController` utilisait déjà ce contrat. `EnsureSuperAdmin` l'appelle maintenant aussi, après résolution explicite de l'identité sur le guard web et refus d'une identité absente. La logique d'autorisation n'est plus dupliquée dans le middleware.

Les rôles futurs sont refusés. Un SUPER_ADMIN porteur du drapeau acheteur est refusé à la connexion, sur les pages administratives et sur la création de boutique ; son compte n'est pas automatiquement corrigé ou converti. Un attribut absent/null ne vaut pas false : la garde échoue en mode fermé. Les migrations d'identité doivent être présentes dans tout environnement destiné à cet accès ; aucune migration persistante n'est appliquée ici.

Dans quatre fixtures de tests admin, is_buyer=false est désormais explicite, conformément au schéma existant. Les modèles tout juste créés en mémoire ne rechargent pas automatiquement toutes les valeurs par défaut SQL ; cela ne justifie pas un assouplissement du contrôle de production. La factory globale et les comptes existants ne sont pas modifiés.

## Invitations : protections déjà présentes, revérifiées

Les routes propriétaires/employés passent déjà par ProtectInvitationResponse. InvitationPrivacy et le gestionnaire d'exceptions appliquent private/no-store et no-referrer aux succès et aux erreurs, y compris validation et erreurs avec debug actif. Les réponses d'erreur ne présentent pas les traces internes ; Retry-After est conservé.

RedactInvitationSecrets filtre les URL, champs sensibles, erreurs SQL/bindings et arguments de pile. Aucun nouveau code parallèle n'est ajouté dans OwnerInvitationController. Les règles existantes de destinataire, statut, rôle, expiration et usage unique restent inchangées.

Limites maintenues : logs d'accès HTTP/proxy et historiques navigateur hors du contrôle de Monolog, anciennes traces non réécrites, lien d'activation sensible par nature. Les configurations de production restent à vérifier ; aucune absence universelle de fuite n'est revendiquée. Mailpit et le provisionnement DEMO restent non activés.

## Réservations du dashboard

`Professional/DashboardController` effectue un agrégat de lecture sur les réservations de la boutique au statut **ACTIVE**, sous les scopes et permissions existants. Un seul instant de référence est utilisé pour les échéances :

| Indicateur | Lignes comprises |
| --- | --- |
| Réservations retenues | Toutes les lignes ACTIVE, y compris échues et sans échéance |
| Non échues | ACTIVE, expires_at strictement supérieur à l'instant de référence |
| Échues, non libérées | ACTIVE, expires_at inférieur ou égal à cet instant |
| Sans échéance | ACTIVE, expires_at null |

La décomposition présente à la fois le nombre de **lignes** et leurs **unités**, sans confondre l'indicateur principal avec une quantité de stock. EXPIRED, RELEASED et CONVERTED sont exclus de ces comptes. Le total de stock physique/réservé/disponible continue de provenir des StockLevel réels. Aucun compteur n'est calculé depuis le navigateur.

`ExpireStockReservation` et `ExpireOrder` ne libèrent pas automatiquement le stock au simple dépassement d'une date : ils contrôlent l'état métier, les liens, la livraison déjà récupérée et les quantités, puis modifient la réservation et le stock dans leurs transactions. Le dashboard ne les appelle pas. Une échéance dépassée n'affirme donc ni qu'une libération est autorisée, ni qu'un traitement automatique a échoué. Les libellés FR/EN reflètent cette distinction.

## Vérifications exécutées

phpunit.xml vérifié : SQLite :memory:, DB_URL vide, sessions/cache de tests isolés ; bootstrap/cache/config.php absent. Les tests nouveaux utilisent leur garde de connexion et retirent les connexions alternatives avant RefreshDatabase. Les fixtures RBAC des anciennes classes sont utilisées uniquement en mémoire, pas par un seeder persistant.

- `SuperAdminHardeningTest`, `ProfessionalDashboardTest`, `InvitationPrivacyTest`, `SuperAdminAuthenticationTest` : **33 tests, 186 assertions réussis**.
- `AdminInterfaceTest`, `AdminStoreManagementTest`, `AdminStoreStatusTest`, `OwnerInvitationFlowTest` : **40 tests, 294 assertions réussis** après ajustement des seules fixtures admin.
- Total distinct de cette intervention : **73 tests, 480 assertions**.
- Régression du dashboard : cas futur, échéance exacte, passée, absente, états libérés/convertis/expirés, autre boutique et permission manquante. Comparaison de toutes les lignes des tables de stock et réservations avant/après consultation en mémoire : identiques.
- Lint PHP réussi sur les onze fichiers PHP concernés ; Pint ciblé et git diff --check réussis. Le changement de texte explicatif final n'ajoute aucune logique. Pas de nouvelle CSS/JS ni de classes utilitaires : aucun build Vite/audit Chrome répété.

## Fichiers de cette correction

Application :

- app/Models/User.php
- app/Http/Middleware/EnsureSuperAdmin.php
- app/Http/Controllers/Professional/DashboardController.php
- resources/views/professional/dashboard.blade.php
- lang/fr/professional.php
- lang/en/professional.php

Tests :

- tests/Feature/SuperAdminHardeningTest.php
- tests/Feature/ProfessionalDashboardTest.php
- tests/Feature/AdminInterfaceTest.php
- tests/Feature/AdminStoreManagementTest.php
- tests/Feature/AdminStoreStatusTest.php
- tests/Feature/OwnerInvitationFlowTest.php

Ce document est ajouté. Les contrôleurs et protections d'invitation sont réutilisés sans modification dans cette intervention. Aucun nouveau compte, e-mail, accès MySQL, traitement d'expiration, modification SQLite DEMO, commit, push, fusion ou indexation Git. Tous les travaux locaux antérieurs restent présents. Les archives d'audit précédentes sont des instantanés antérieurs à ces corrections.
