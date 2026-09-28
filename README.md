# Rocket PMS

Gestion de locations courte durée (Property Management System) : **logements**, **réservations Lodgify** (liste façon boîte mail, conversation avec le voyageur et réponse, valeur et détail du prix), **serrures, domotique, documents et stock** de chaque logement via [Rocket Place](https://github.com/fayouz/rocket-place) (codes clavier par séjour), **timeline** et **tableau de bord**. Application métier Rocket, sur le socle [rocket-core](https://github.com/fayouz/rocket-core).

| Dossier | Stack |
|---|---|
| `backend/` | Symfony 8.1, API Platform, Doctrine (PostgreSQL), rocket-core (`rocket/core-bundle`) |
| `frontend/` | Nuxt 4, Nuxt UI 4, layer `@rocket/core` |
| `docs/` | Documentation (Nuxt UI + Nuxt Content), changelog sur `/changelog` |

Le socle commun (comptes, LDAP, SSO / Rocket Auth, applications externes, tableau de bord, mises à jour, modes autonome et suite) vient de rocket-core : ce dépôt ne contient que le métier.

## Démarrage rapide

```bash
docker compose up -d --build
```

- Application : http://localhost:3700 (configuration initiale : création de l'administrateur)
- API + OpenAPI : http://localhost:8700/api/docs
- Démo complète : `docker compose -f compose.yaml -f compose.demo.yaml up -d --build` (voir [demo/README.md](demo/README.md))

Sans `LODGIFY_API_KEY` ni `ROCKET_PLACE_URL`/`ROCKET_PLACE_TOKEN`, l'application tourne sur des **données de démo** (deux logements fictifs, leurs réservations, leurs lieux et serrures) : rien n'est lu ni écrit chez Lodgify ou Rocket Place.

### Développement sans Docker

```bash
# backend (PHP 8.4, PostgreSQL)
cd backend && composer install --ignore-platform-req=ext-ldap
php bin/console lexik:jwt:generate-keypair
php bin/console doctrine:migrations:migrate
echo 'MESSENGER_TRANSPORT_DSN=sync://' >> .env.local
php -S 127.0.0.1:8700 -t public
php bin/phpunit

# frontend
cd frontend && npm install && NUXT_PUBLIC_API_BASE=http://localhost:8700 npm run dev -- --port 3700
```

## Configuration

| Variable | Rôle |
|---|---|
| `LODGIFY_API_KEY` | Clé d'API Lodgify (réservations, messagerie, devis). Vide : démo. |
| `ROCKET_PLACE_URL` · `ROCKET_PLACE_TOKEN` | Adresse de l'API Rocket Place et jeton d'application (`rpl_…`) de PMS. Vides : Rocket Place de démo, sans réseau. |
| `ROCKET_AUTH_URL`, `ROCKET_AUTH_INTERNAL_URL`, `ROCKET_AUTH_CLIENT_ID` (`rocket-pms`), `ROCKET_AUTH_CLIENT_SECRET`, `ROCKET_AUTH_ADMIN_GROUP`, `ROCKET_PUBLIC_URL`, `ROCKET_INTERNAL_URL` | Mode suite (voir ci-dessous). `ROCKET_AUTH_URL` vide : mode autonome, inchangé. |
| `PMS_TIMEZONE` | Fuseau des logements (heures d'arrivée et de départ, codes clavier). `Europe/Paris` par défaut. |

## Mode suite (Rocket Auth)

Avec `ROCKET_AUTH_URL`, Rocket PMS rejoint la suite Rocket (mécanisme de rocket-core) : connexion par Rocket Auth uniquement, sélecteur des applications et « Mon compte » dans le menu, déconnexion propagée (RP-initiated logout, back-channel logout sur `ROCKET_INTERNAL_URL`). Rocket Auth déclare le client `rocket-pms`.

**PMS → Rocket Place** : jeton Rocket Auth obtenu par client credentials (`Rocket\Core\Suite\ServiceTokenProvider`, audience `rocket-place`) au lieu de `ROCKET_PLACE_TOKEN`, qui reste le repli (mode autonome ou Rocket Auth injoignable). Dans Rocket Place, un administrateur lie une application au client `rocket-pms` (Administration → Applications, « Client Rocket Auth »).

Environnement complet (Rocket Auth, Rocket Cloud, Rocket Place, Rocket PMS, démo) : [`compose.suite.yaml`](compose.suite.yaml), voir [docs/content/1.getting-started/4.suite.md](docs/content/1.getting-started/4.suite.md).

```bash
docker compose -f compose.suite.yaml up -d --build   # http://localhost:3700
```

## Fonctionnalités (v0.1)

- **Logements** : créés depuis Lodgify (« Synchroniser »), renommables, couleur repère.
- **Réservations** d'un logement, façon client mail : recherche, filtre (en cours, à venir, passées, annulées), tri ; conversation Lodgify avec le voyageur et **réponse** (poussée par Lodgify sur Airbnb, Booking.com ou par e-mail, sans double envoi) ; **valeur** et détail du prix (devis Lodgify, hors commission de la plateforme).
- **Rocket Place** : chaque logement est lié à un lieu (onglet Infos). Serrures (état, batterie, historique), domotique, documents et stock viennent de ce lieu, relayés par PMS. Un **code clavier** (accès Rocket Place) est prévu pour chaque séjour à venir (ouvert 1 h avant l'arrivée, fermé 1 h après le départ) et **envoyé à la serrure seulement sur un clic** confirmé ; un séjour commencé n'est jamais modifié.
- **Livret d'accueil et écran TV** (onglet Livret & TV) : livret par logement, lien voyageur secret par séjour (`/g/…`, actif de J-2 à J+1, prénom seul, code de la porte seulement une fois envoyé à la serrure), écran TV en mode kiosque (`/tv/…`, sans code).
- **Timeline** d'un logement ou de tous : séjours, codes, passages aux serrures.
- **Tableau de bord** : arrivées et départs du jour, ménages entre deux séjours, occupation et revenus sur 30 jours, prochaines arrivées ; état des services Lodgify et Rocket Place.
- **API** pour les applications externes (jeton `rpm_…`), par exemple LoussaHousing.

## Gitflow

`main` : production ; `develop` : intégration ; `feature/*` → `develop` (section `[Non publié]` du [CHANGELOG](CHANGELOG.md)) ; `release/*` et `hotfix/*` → `main`, puis tag `vX.Y.Z` créé depuis GitHub.
