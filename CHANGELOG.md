# Changelog

Toutes les évolutions notables de Rocket PMS. Format [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/), versions [SemVer](https://semver.org/lang/fr/).

## [Non publié]

### Ajouté
- Logements synchronisés depuis Lodgify, avec couleur repère.
- Réservations d'un logement façon client mail : recherche, filtre, tri, conversation Lodgify et réponse au voyageur, valeur et détail du prix.
- Serrures Nuki : état, batterie, historique, lien serrure → logement (administration), codes clavier prévus par séjour et envoyés sur clic.
- Timeline d'un logement et de tous les logements.
- Tableau de bord (arrivées, départs, ménages, occupation, revenus, prochaines arrivées) et état des services Lodgify et Nuki.
- Mode démo sans clé Lodgify ni jeton Nuki.
- Domotique : catalogue de plugins intégré (Homey, Service web), connecteurs par logement (plusieurs autorisés, ex. deux Homey), onglet « Domotique » en lecture seule (administration : ajout/modification/suppression/test), secrets uniquement en noms de variables `.env` préfixées `CONNECTOR_`. Page Administration → Plugins.
