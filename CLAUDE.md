# Rocket PMS

Gestion de locations courte durée (logements, réservations Lodgify), **application métier** Rocket (pas une brique de rocket-middlewares), cliente de **Rocket Place** (`../rocket-place`, serveur : ne pas le modifier depuis ici) pour tout le physique : serrures et accès, domotique, documents, stock. Socle commun : [rocket-core](https://github.com/fayouz/rocket-core) (bundle Symfony `rocket/core-bundle` + layer Nuxt `@rocket/core`), à lire avant de modifier les comptes, le SSO, les applications, le tableau de bord ou la mise en page : ce code n'est pas ici. Issu de LoussaHousing (Nuxt + SQLite), qui deviendra un client de cette API.

## Repères
- `app_id` `pms`, jetons d'application `rpm_…`, ports front 3700 · api 8700 · docs 3701.
- Domaine : `Property` (logement lié à un logement Lodgify et, via `placeId`, à un lieu Rocket Place). Les réservations, messages et devis ne sont **jamais stockés** : lus chez Lodgify (`Lodgify/LodgifyClient` + `DemoLodgify`, cache 5 min ; `Lodgify/BookingProviderRegistry` : connecteur Lodgify du logement, sinon `LODGIFY_API_KEY`).
- Rocket Place : `Place/PlaceClient` (`ROCKET_PLACE_URL` + `ROCKET_PLACE_TOKEN` `rpl_…`, Bearer, réponses streamées plafonnées, erreurs 4xx relayées, 401/403/5xx/réseau → 502 ; en mode suite, jeton Rocket Auth `ServiceTokenProvider` audience `rocket-place`, jeton statique en repli) ; vides : `Place/DemoPlace` (fichier `var/demo-place-<env>.json`, aucun réseau, `reset()` en test). `Controller/PlaceProxyController` relaie `/api/properties/{id}/locks|codes|access-grants|domotique|documents…|stock` vers `/api/places/{placeId}/…` (409 sans `placeId`) ; `Controller/PlaceLinkController` (admin) : `/api/places`, `PUT|POST /api/properties/{id}/place`, `/api/locks`.
- Accès : `Code/AccessCodePlanner` calcule un accès par séjour à venir (1 h avant l'arrivée / après le départ, fuseau `PMS_TIMEZONE`) et le prévoit dans Place avec `externalRef` = id de réservation (idempotent, verrou Symfony ; dates changées avant envoi : révoqué puis re-prévu) ; envoi à la serrure uniquement sur clic (`POST …/access-grants/{grantId}/send`). Timeline : `Timeline/TimelineBuilder`. Tableau de bord : `Dashboard/PropertiesSection`. Sondes : `Health/LodgifyProbe`, `Health/PlaceProbe`.
- Connecteurs PMS : seul `Domotique/LodgifyPlugin` (entité `Connector`, `Domotique/PluginRegistry`, secrets = nom de variable `.env` `CONNECTOR_…`, `Domotique/SecretEnv`) ; `Controller/ConnectorController`.
- Livret / écran TV : entité `WelcomeBook` (1 par logement, contenu JSON, `tvToken` 256 bits, `guestSalt`), `Controller/WelcomeBookController`, `WelcomeBook/GuestLinkSigner` (jeton voyageur sans état : uuid logement + id réservation + HMAC 128 bits), `WelcomeBook/WelcomeBookViews` (prénom seul, code seulement si l'accès Place est `created`, jamais sur la TV), `WelcomeBook/PublicRateLimiter` (cache.app, pas de paquet rate-limiter). Pages publiques front `/g/[token]`, `/tv/[token]` (`publicPaths`), onglet `WelcomeBookTab.vue`.
- E-mails : `Mailer/MailerClient` (Rocket Mailer, `ROCKET_MAILER_URL`/`TOKEN`/`INBOX`/`MAILBOX`, `X-Impersonate-User` = utilisateur connecté, audience suite `rocket-mailer`) + `Mailer/DemoMailer` (`var/demo-mailer-<env>.json`, `reset()`) ; `Controller/BookingEmailController` (conversations liées : participant = e-mail voyageur ou n° dans l'objet ; envoi idempotent par `messageId`, cache.app). Boîte partagée refusée aux applications par Mailer 0.7 → `InboxUnavailable` (affiché, pas d'erreur).
- Bilan : entité `Expense` (montants en centimes, `CATEGORIES`), `Bilan/BilanBuilder` (revenus nuit par nuit, CSV `;` BOM, formules neutralisées), `Controller/BilanController` ; onglet `BilanTab.vue`.
- Front : `pages/properties/[id].vue` (onglets Réservations, Infos, Serrures, Domotique, Documents, Stock, Timeline), `components/PropertyInfoTab.vue` (lieu Place + connecteurs Lodgify), `BookingsInbox.vue`, `LocksInbox.vue`, `DomotiqueTab.vue`, `DocumentsTab.vue`, `StockTab.vue`, `EventTimeline.vue` ; `pages/locks.vue` (admin : serrure → lieu) ; aides dans `utils/pms.ts`.

## Vérifier avant de pousser
```bash
cd backend && php bin/console lint:container && php bin/console doctrine:schema:validate && php bin/phpunit
cd frontend && npm run lint && npm run typecheck
cd docs && npm run lint && npm run typecheck && npm run generate   # si docs/ a changé
```

## Suite
`compose.suite.yaml` : Auth + Cloud (dépôts rocket-middleware voisins, `ROCKET_AUTH_DIR`, `ROCKET_CLOUD_DIR`) + Place (`ROCKET_PLACE_DIR`) + PMS en mode suite ; `suite-link` lie les clients `rocket-place` (dans Cloud) et `rocket-pms` (dans Place). Ne jamais modifier les dépôts rocket-middleware depuis ici.

## Pièges connus
- Envoi de messages aux voyageurs et envoi de codes aux serrures : actions réelles chez Lodgify / Rocket Place, jamais en test ni sans clic de l'utilisateur.
- Rocket Place donne au jeton d'application seul `ROLE_APPLICATION` (rocket-core) : ses routes `ROLE_USER`/`ROLE_ADMIN` doivent l'accepter côté Place, sinon PMS reçoit 403 (→ 502 « Rocket Place refuse… »).
- Cache Symfony de test périmé après un changement d'entité : `php bin/console cache:clear --env=test`.
- API Platform répond en JSON-LD par défaut : envoyer `Accept: application/json`.
- Migrations : lancer d'abord celles du socle, puis `doctrine:migrations:diff`.
- Pas de Composer sur le Mac de Faez : `docker run --rm -v "$PWD":/app -w /app composer:2 install --ignore-platform-reqs`. Cache npm global en erreur de droits : `npm ci --cache <dossier temporaire>`.

## Feuille de route
LoussaHousing client de l'API (jeton `rpm_…`), ménage ; livret : multilingue, fonds/mise en page, QR code, statistiques de visite (non repris) ; connexion unique via Rocket Auth (rocket-middlewares).
