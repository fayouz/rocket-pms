# Changelog

Toutes les évolutions notables de Rocket PMS. Format [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/), versions [SemVer](https://semver.org/lang/fr/).

## [Non publié]

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
