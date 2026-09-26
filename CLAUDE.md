# Rocket PMS

Gestion de locations courte durée (logements, réservations Lodgify, serrures Nuki), sur la stack des briques Rocket. Socle commun : [rocket-core](https://github.com/fayouz/rocket-core) (bundle Symfony `rocket/core-bundle` + layer Nuxt `@rocket/core`), à lire avant de modifier les comptes, le SSO, les applications, le tableau de bord ou la mise en page : ce code n'est pas ici. Issu de LoussaHousing (Nuxt + SQLite), qui deviendra un client de cette API.

## Repères
- `app_id` `pms`, jetons d'application `rpm_…`, ports front 3700 · api 8700 · docs 3701.
- Domaine : `Property` (logement, lié à un logement Lodgify), `SmartLock` (serrure Nuki → logement), `AccessCode` (code clavier d'une réservation : prévu, créé, erreur). Les réservations, messages et devis ne sont **jamais stockés** : lus chez Lodgify (`Lodgify/LodgifyClient`, cache 5 min).
- Intégrations : `Lodgify/LodgifyClient` (+ `DemoLodgify`), `Nuki/NukiClient` (+ `DemoNuki`) ; sans clé ou jeton : démo. Codes : `Code/AccessCodePlanner` (1 h avant l'arrivée / après le départ, fuseau `PMS_TIMEZONE`, jamais sur un séjour commencé, envoi à Nuki seulement sur action utilisateur). Timeline : `Timeline/TimelineBuilder`. Tableau de bord : `Dashboard/PropertiesSection`. Sondes : `Health/LodgifyProbe`, `Health/NukiProbe`.
- Front : `pages/properties/[id].vue` (onglets), `components/BookingsInbox.vue`, `LocksInbox.vue`, `EventTimeline.vue` ; aides dans `utils/pms.ts`.

## Vérifier avant de pousser
```bash
cd backend && php bin/console lint:container && php bin/console doctrine:schema:validate && php bin/phpunit
cd frontend && npm run lint && npm run typecheck
cd docs && npm run lint && npm run typecheck && npm run generate   # si docs/ a changé
```

## Pièges connus
- Envoi de messages aux voyageurs et création de codes sur les serrures : actions réelles chez Lodgify / Nuki, jamais en test ni sans clic de l'utilisateur.
- Dates des codes en `DATETIMETZ` (instants justes quel que soit le fuseau du serveur).
- API Platform répond en JSON-LD par défaut : envoyer `Accept: application/json`.
- Migrations : lancer d'abord celles du socle, puis `doctrine:migrations:diff`.
- Pas de Composer sur le Mac de Faez : `docker run --rm -v "$PWD":/app -w /app composer:2 install --ignore-platform-reqs`. Cache npm global en erreur de droits : `npm ci --cache <dossier temporaire>`.

## Feuille de route
v0.2 : documents par logement dans **Rocket Cloud** (dossier par logement, explorateur `@rocket/file-explorer`), ajout à rocket-suite avec Rocket Auth ; puis LoussaHousing client de l'API (jeton `rpm_…`), stock, livret d'accueil / écran TV, ménage, domotique, connecteurs.
