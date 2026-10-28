# Phase 19C — espace livreur professionnel

## Contrat existant et raccordement

Le backend possède déjà Delivery, AssignDeliveryAgent, UpdateDeliveryStatus et ConfirmDeliveryReturn, ainsi que les routes JSON deliveries.*. Les autorisations reposent sur le guard professionnel web, le TenantContext et les permissions deliveries.view/update ; aucune DeliveryPolicy séparée n'existait. Les statuts opérationnels utilisés restent ASSIGNED, PICKED_UP, DELIVERED et FAILED. Aucun deuxième moteur de livraison n'est ajouté.

L'espace HTML est accessible après connexion professionnelle à `/espace-livreur` : indicateurs réels, recherche par référence/destinataire, filtre de statut, pagination de 12 missions, détail et historique. La connexion du livreur redirige vers cette page. Les routes sont `courier.index`, `courier.history`, `courier.show` et `courier.update` ; les anciennes routes JSON sont préservées. Le middleware tenant existant refuse un visiteur sans session par HTTP 401 ; aucun formulaire de mission n'est rendu dans ce cas.

La présentation utilise une sidebar de 252 px, des accents verts et des cartes blanches. Le menu mobile est un élément details natif, utilisable au clavier, avec fermeture par Échap via professional.js. Les liens actifs utilisent aria-current. Les formulaires restent utilisables sans JavaScript ; courier.js ajoute seulement l'état de chargement et la prévention des doubles clics.

## Autorisations et confidentialité

- Compte ACTIVE, boutique ACTIVE, adresse e-mail d'employé vérifiée et rôle unique DELIVERY_AGENT. Tout rôle supplémentaire, même inconnu, est refusé.
- Guard web et tenant de l'acteur vérifiés côté serveur ; le seul guard buyer ne donne pas accès.
- Chaque lecture ou mutation HTML filtre explicitement store_id, assigned_to et une commande de type DELIVERY appartenant à la même boutique. Les commandes PICKUP ne deviennent jamais des missions livreur.
- Les actions et les routes JSON reçoivent aussi les contrôles d'identité renforcés. Le propriétaire et le gestionnaire conservent leur lecture/attribution selon leurs permissions. Ils ne peuvent pas exécuter les transitions réservées au livreur. Le retour physique reste réservé au propriétaire autorisé ; un cumul de rôles ne le contourne pas.
- Le livreur ne s'attribue pas de mission et ne modifie ni prix, ni frais, ni acteur assigné. Le formulaire HTML refuse explicitement les champs sensibles injectés.
- Le détail affiche les coordonnées utiles à la mission, les instructions et les articles/quantités. Il n'affiche ni e-mail client, ni détails de paiement, ni données d'autres clients. Le lien téléphonique est construit seulement à partir d'un numéro reconnu par BuyerIdentity.
- Réponses HTML privées : Cache-Control private/no-store et Referrer-Policy no-referrer. Les formulaires utilisent CSRF et la mutation HTML est limitée à 20 requêtes/minute. Aucun journal de données personnelles n'a été ajouté.

## Transitions, paiement et stock

Les mutations délèguent à UpdateDeliveryStatus, dans sa transaction et son ordre de verrouillage existant : commande puis livraison. Une confirmation explicite est obligatoire dans le formulaire HTML. Les doubles étapes, transitions inverses et états terminaux sont refusés côté serveur.

La récupération d'une commande impayée conserve les contrôles existants de réservation. Une livraison sans adresse ou sans frais strictement positifs ne peut pas être attribuée ou récupérée/remise. Aucun montant n'est corrigé automatiquement et aucune configuration commerciale persistante n'est activée.

La remise DELIVERED exige une commande encore PENDING et payment_status PAID, une récupération datée et aucun retour enregistré. La livraison ne confirme aucun paiement et ne transforme pas le statut global de commande : le contrat existant sépare Order.status et Delivery.status. Le stock et les paiements ne sont pas modifiés par la transition de livraison.

Un échec exige un motif réel de 1 à 1000 caractères, est terminal et ne libère pas arbitrairement le stock. Le retour physique passe toujours par ConfirmDeliveryReturn, côté propriétaire.

**Le livreur ne collecte aucun argent.** Aucun formulaire d'encaissement, aucune simulation de paiement, aucun compte marchand ou transfert Mobile Money n'est ajouté. Seul le moteur financier et ses acteurs autorisés produisent l'état PAID ; les paiements Mobile Money existants restent simulés.

## Historique et preuve

Les dates existantes assigned_at, picked_up_at, delivered_at, failed_at et returned_at alimentent la chronologie. Le motif d'échec réel est conservé. L'acteur de mission est assigned_to ; le retour dispose déjà de return_confirmed_by. L'action d'attribution ne permet pas la réattribution d'une livraison existante.

Le schéma ne contient pas un journal immuable distinct de chaque transition avec son propre actor_id. Aucun historique complet d'événements supplémentaire n'est prétendu. Aucune signature, photo, preuve certifiée ou code de remise n'est fabriqué. Ces mécanismes demanderaient un contrat et un stockage dédiés.

Cartes, GPS, ETA, distances, itinéraires optimisés, disponibilité en ligne et notifications de la maquette ne sont pas implémentés dans le backend actuel : aucun contrôle fictif n'est présenté pour ces fonctions. Aucun service externe n'est ajouté.

## Vérifications et limites

PHPUnit est configuré sur SQLite :memory:, sans cache de configuration. Le nouveau test vérifie ces conditions avant RefreshDatabase et supprime les autres connexions dans l'application de test.

- Tests livraison : 59 tests et 297 assertions réussis (nouvelle interface et AssignDeliveryAgentTest historique).
- Test CSRF supplémentaire ciblé : 1 test et 3 assertions réussis ; HTML et JSON sans jeton refusés par HTTP 419, sans transition enregistrée. Total des scénarios livraison validés : 60 tests, 300 assertions, sur deux exécutions complémentaires.
- Régression exécutée avec ProfessionalTeamTest, PosInterfaceTest et PublicCheckoutTest : leurs 43 tests ont réussi. Une erreur de méthode HTTP dans un nouveau test de retour a été corrigée de POST vers PATCH, puis le test a réussi ; le backend n'a pas été assoupli pour cela.
- Chrome installé, profil temporaire : 36 contrôles = 3 pages × 2 langues × 6 largeurs (360, 390, 768, 1024, 1280, 1440). Pas de débordement horizontal ni d'erreur JS ; menu Échap, navigation clavier et états de soumission vérifiés. Captures desktop/mobile inspectées.
- Ces contrôles Chrome utilisent les véritables rendus Blade produits par les tests en mémoire. Toutes les requêtes réseau sont bloquées. Ils ne constituent pas un test connecté à SQLite DEMO ; les transactions métier sont validées par les tests HTTP en mémoire.
- Build Vite réussi ; avertissements non bloquants sur fontaine optionnel absent et durée des callbacks du plugin Tailwind. Aucune dépendance ajoutée.
- Syntaxe PHP/JS, formatage limité aux fichiers concernés et git diff --check vérifiés.

Les captures, résultats et outil local sont dans `storage/demo/phase19c-preview/`, ignoré par Git. Aucun secret ni donnée réelle n'y est copié.

La concurrence entre deux connexions MySQL n'est pas testée en exécution : les tests SQLite prouvent les transitions répétées, refus et rollback, pas une contention réelle multi-connexion. Aucun test n'utilise MySQL.

## Activation future en DEMO

Aucune migration ni nouvelle dépendance n'est nécessaire pour 19C. La migration POS 19B précédemment préparée reste distincte et n'est pas appliquée par cette livraison.

La DEMO demeure inchangée : aucune livraison activée, aucun compte ni mission créé. Une validation connectée exigera une autorisation séparée pour préparer, avec sauvegarde et mécanismes DEMO sécurisés, un compte employé vérifié DELIVERY_AGENT, une véritable zone/frais et une commande DELIVERY attribuée. Le retrait existant ne devra jamais être transformé arbitrairement en livraison. Les confirmations financières resteront sous le contrôle des acteurs autorisés, sans argent réel.

## Fichiers de cette phase

Nouveaux fichiers :

- app/Support/DeliveryAccess.php
- app/Http/Controllers/Professional/DeliveryAgentController.php
- lang/fr/courier.php
- lang/en/courier.php
- resources/views/professional/courier/layout.blade.php
- resources/views/professional/courier/navigation.blade.php
- resources/views/professional/courier/index.blade.php
- resources/views/professional/courier/show.blade.php
- resources/css/courier.css
- resources/js/courier.js
- tests/Feature/DeliveryAgentInterfaceTest.php
- docs/DELIVERY_AGENT_PHASE19C.md

Fichiers existants adaptés :

- app/Actions/Deliveries/AssignDeliveryAgent.php
- app/Actions/Deliveries/UpdateDeliveryStatus.php
- app/Actions/Deliveries/ConfirmDeliveryReturn.php
- app/Http/Controllers/DeliveryController.php
- app/Http/Controllers/Auth/SessionController.php
- routes/web.php
- resources/css/app.css
- resources/js/app.js
- resources/views/professional/navigation.blade.php

Les travaux locaux antérieurs 17.4A/18A/18B/19A/19B sont conservés. Aucun fichier n'est indexé, committé ou poussé dans cette intervention. Aucune lecture MySQL, écriture SQLite DEMO, migration persistante, invitation, paiement réel, SMS ou WhatsApp n'est effectué.
