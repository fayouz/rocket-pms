# Changelog

Toutes les évolutions notables de Rocket PMS. Format [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/), versions [SemVer](https://semver.org/lang/fr/).

## [Non publié]

### Ajouté
- **Planification** des codes et ménages : tâche récurrente rocket-core toutes les 15 minutes (`App\Planning\PlanningSchedule`, message `RunPlanning`) et `POST /api/planning/run` (PMS_MANAGE, ouvert aux applications) ; bouton « Lancer la planification » (administrateur) sur la page Timeline.
- **Lien ménage** : `GET /api/properties/{id}/cleanings` et `GET /api/properties/{id}/cleanings/{cleaningId}/link` (PMS_MANAGE, ouverts aux applications), `PlaceClient::cleaningLink`, Rocket Place de démo étendu ; « Copier le lien ménage » dans les ménages de la timeline.
- **Liaisons Place** (Administration) : chaque logement avec son id Lodgify, son lieu Rocket Place (lien vers le front Place si `ROCKET_PLACE_FRONT_URL`), l'état de la liaison (lié, non lié, lieu introuvable, Place injoignable), serrures, accès à venir, ménages ouverts, stock bas ; lier, changer, délier, créer le lieu depuis le logement. `GET /api/place-links` (PMS_MANAGE, ouvert aux applications).
- **Démo** : chaque réservation active a une adresse e-mail et une conversation Rocket Mailer de démo correspondante.
- **Livret multilingue** : français par défaut, anglais, espagnol, allemand, italien facultatifs par rubrique (repli français) ; langue de la page voyageur et de l'écran TV d'après `?lang=` puis `Accept-Language`, sélecteur de langue sur la page.
- **Apparence du livret** par logement : couleur d'accent, image de couverture (https ou document image du lieu Rocket Place, route publique `/cover`), disposition onglets ou colonnes.
- **QR codes** du lien TV et des liens voyageurs, dessinés dans le navigateur (`uqr`).
- **Envoyer le livret** depuis une réservation : Message Lodgify ou e-mail (Rocket Mailer), seulement sur clic confirmé, idempotent (`POST …/guest-link/send`).
- **Statistiques de visite** du livret et de l'écran TV (par lien et par jour, sans IP ni donnée visiteur). Migration : table `welcome_book_visit`, colonnes `welcome_book.translations`, `welcome_book.style`.
- **Écran TV** rechargé 30 minutes avant la prochaine arrivée (`reloadAt`).
- **Ménage après départ** : tâche Rocket Place par départ (`externalRef` `booking:<id>:checkout`, due à l'arrivée suivante), déplacée si les dates changent, annulée avec la réservation ; affichée dans la timeline. `PlaceClient::cleanings|createCleaning|updateCleaning`, Rocket Place de démo étendu.
- **Bilan** : justificatif choisi avec l'explorateur de documents ; **import de relevés de plateformes** en CSV (commissions, taxe de séjour, remboursements ; reversements ignorés), sans doublon par plateforme + `externalId`. Catégorie « Taxe de séjour ». Migration : colonnes `expense.source`, `expense.external_id`.
- **E-mails des réservations** (repris de LoussaHousing, par **Rocket Mailer**) : conversations de la boîte partagée `ROCKET_MAILER_INBOX` liées à la réservation (adresse du voyageur, ou numéro de réservation dans l'objet), fil en texte, composeur hybride **Message Lodgify / E-mail**, e-mail au voyageur envoyé seulement sur clic et une seule fois (`messageId`). Client `Mailer/MailerClient` (impersonation de l'utilisateur, jeton Rocket Auth en mode suite), Rocket Mailer de démo sans réseau. Variables `ROCKET_MAILER_URL`, `ROCKET_MAILER_TOKEN`, `ROCKET_MAILER_INBOX`, `ROCKET_MAILER_MAILBOX`.
- **Bilan** d'un logement (repris de LoussaHousing) : onglet **Bilan**, revenus Lodgify répartis nuit par nuit, occupation, écritures comptables (charges et autres recettes, CRUD administrateur, référence facultative d'un document Rocket Place), totaux par mois et par catégorie, résultat, **export CSV**. Migration : table `expense`. Données de démo : quelques écritures par logement.
- **Livret d'accueil et écran TV** (repris de LoussaHousing) : onglet **Livret & TV** d'un logement (rubriques Wi-Fi, arrivée, départ, accès, règlement, contacts, bonnes adresses, FAQ), lien voyageur secret par réservation `/g/<jeton>` (signé HMAC, actif de 2 jours avant l'arrivée au lendemain du départ, prénom seul, code de la porte affiché seulement une fois envoyé à la serrure), écran TV kiosque `/tv/<jeton>` (accueil, météo, Wi-Fi, départ ; jamais de code). Routes publiques `/api/public/guest|tv/…` limitées en débit. Migration : table `welcome_book`.
- Mode suite documenté (Rocket Auth : connexion, sélecteur d'applications, déconnexion ; variables `ROCKET_AUTH_*`, `ROCKET_PUBLIC_URL`, `ROCKET_INTERNAL_URL`).
- Rocket Place appelé avec un jeton Rocket Auth en mode suite (client credentials, audience `rocket-place`) ; `ROCKET_PLACE_TOKEN` reste le repli.
- `compose.suite.yaml` : Rocket Auth, Rocket Cloud (depuis les dépôts rocket-middleware voisins), Rocket Place et Rocket PMS en mode suite, avec données de démo et applications liées aux clients Rocket Auth.

### Modifié
- La **timeline** ne fait plus que lire (plus aucun accès ni ménage créé dans Rocket Place en l'affichant).

## [0.2.0] - 2026-09-28

### Modifié
- **Rocket PMS devient un client de Rocket Place** : serrures et codes clavier (désormais des *accès* Rocket Place, prévus par séjour avec `externalRef` = identifiant de réservation, idempotents, envoyés à la serrure seulement sur clic), domotique, documents et stock sont gérés par Rocket Place et relayés par l'API de PMS (le navigateur ne parle qu'à PMS). Un logement se lie à son lieu (`placeId`) dans le nouvel onglet **Infos** (choix du lieu ou création depuis le logement) ; sans lieu, ces onglets répondent 409 avec un message clair. Nouvel onglet **Stock**. Variables `ROCKET_PLACE_URL` et `ROCKET_PLACE_TOKEN` (vides : Rocket Place de démo, sans réseau).

### Retiré
- Serrures, codes, connecteurs Homey / Home Assistant / Nuki / service web / Rocket Cloud et leurs clients, sondes Nuki et Homey, page Administration → Plugins, variables `NUKI_API_TOKEN`, `ROCKET_CLOUD_URL`, `ROCKET_CLOUD_TOKEN`. Seul Lodgify reste un connecteur de PMS. La migration supprime les tables `smart_lock` et `access_code` et les connecteurs non Lodgify (à recréer sur le lieu dans Rocket Place).

### Ajouté
- Logements synchronisés depuis Lodgify, avec couleur repère.
- Réservations d'un logement façon client mail : recherche, filtre, tri, conversation Lodgify et réponse au voyageur, valeur et détail du prix.
- Serrures Nuki : état, batterie, historique, lien serrure → logement (administration), codes clavier prévus par séjour et envoyés sur clic.
- Timeline d'un logement et de tous les logements.
- Tableau de bord (arrivées, départs, ménages, occupation, revenus, prochaines arrivées) et état des services Lodgify et Nuki.
- Mode démo sans clé Lodgify ni jeton Nuki.
- Domotique : catalogue de plugins intégré (Homey, Service web), connecteurs par logement (plusieurs autorisés, ex. deux Homey), onglet « Domotique » en lecture seule (administration : ajout/modification/suppression/test), secrets uniquement en noms de variables `.env` préfixées `CONNECTOR_`. Page Administration → Plugins.
- Lodgify et Rocket Cloud (documents) deviennent eux aussi des connecteurs pluggables (capacités `bookings`/`pricing`/`conversation` et `documents`) : un logement peut avoir son propre compte Lodgify ou sa propre instance Rocket Cloud, sinon la clé `LODGIFY_API_KEY` / les variables `ROCKET_CLOUD_URL`+`ROCKET_CLOUD_TOKEN` globales servent de repli (comportement inchangé pour les logements sans connecteur). Catalogue et formulaire de connecteur affichent les capacités de chaque plugin.
