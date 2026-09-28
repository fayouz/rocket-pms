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

Sans `lodgify.api_key` (coffre) ni `ROCKET_PLACE_URL` + secret `rocket.place.token`, l'application tourne sur des **données de démo** (deux logements fictifs, leurs réservations, leurs lieux et serrures) : rien n'est lu ni écrit chez Lodgify ou Rocket Place.

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
| `lodgify.api_key` (coffre) | Clé d'API Lodgify (réservations, messagerie, devis). Vide : démo. |
| `ROCKET_PLACE_URL` · `rocket.place.token` (coffre) | Adresse de l'API Rocket Place et jeton d'application (`rpl_…`) de PMS. Vides : Rocket Place de démo, sans réseau. |
| `ROCKET_CLEAN_URL` · `rocket.clean.token` (coffre) | Rocket Clean (ménages après départ, occupation) : API et jeton d'application `rcl_…`. Vides : ménages de démo. |
| `ROCKET_STOCK_URL` · `rocket.stock.token` (coffre) | Rocket Stock (stock des lieux) : API et jeton `rst_…`. Vides : stock de démo. |
| `ROCKET_CAST_FRONT_URL` | Front de Rocket Cast (bouton de l'onglet Livret & TV), facultatif. |
| `ROCKET_MAILER_URL` · `rocket.mailer.token` (coffre) · `ROCKET_MAILER_INBOX` · `ROCKET_MAILER_MAILBOX` | Rocket Mailer : API, jeton d'application `rma_…` (impersonation), boîte partagée des voyageurs, boîte d'envoi (facultative). Vides : Rocket Mailer de démo, sans réseau. |
| `ROCKET_AUTH_URL`, `ROCKET_AUTH_INTERNAL_URL`, `ROCKET_AUTH_CLIENT_ID` (`rocket-pms`), `ROCKET_AUTH_CLIENT_SECRET`, `ROCKET_AUTH_ADMIN_GROUP`, `ROCKET_PUBLIC_URL`, `ROCKET_INTERNAL_URL` | Mode suite (voir ci-dessous). `ROCKET_AUTH_URL` vide : mode autonome, inchangé. |
| `FRONTEND_URL` | Adresse publique du front : liens absolus du livret envoyés aux voyageurs. |
| `PMS_TIMEZONE` | Fuseau des logements (heures d'arrivée et de départ, codes clavier). `Europe/Paris` par défaut. |

## Mode suite (Rocket Auth)

Avec `ROCKET_AUTH_URL`, Rocket PMS rejoint la suite Rocket (mécanisme de rocket-core) : connexion par Rocket Auth uniquement, sélecteur des applications et « Mon compte » dans le menu, déconnexion propagée (RP-initiated logout, back-channel logout sur `ROCKET_INTERNAL_URL`). Rocket Auth déclare le client `rocket-pms`.

**PMS → Rocket Clean / Rocket Stock** : de même, audiences `rocket-clean` et `rocket-stock`, `rocket.clean.token` (coffre) / `rocket.stock.token` (coffre) en repli.

**PMS → Rocket Mailer** : de même, audience `rocket-mailer`, `rocket.mailer.token` (coffre) en repli (non vérifié contre un Rocket Mailer réel).

**PMS → Rocket Place** : jeton Rocket Auth obtenu par client credentials (`Rocket\Core\Suite\ServiceTokenProvider`, audience `rocket-place`) au lieu de `rocket.place.token` (coffre), qui reste le repli (mode autonome ou Rocket Auth injoignable). Dans Rocket Place, un administrateur lie une application au client `rocket-pms` (Administration → Applications, « Client Rocket Auth »).

Environnement complet (Rocket Auth, Rocket Cloud, Rocket Place, Rocket PMS, démo) : [`compose.suite.yaml`](compose.suite.yaml), voir [docs/content/1.getting-started/4.suite.md](docs/content/1.getting-started/4.suite.md).

```bash
docker compose -f compose.suite.yaml up -d --build   # http://localhost:3700
```

## Fonctionnalités (v0.1)

- **Logements** : créés depuis Lodgify (« Synchroniser »), renommables, couleur repère.
- **Réservations** d'un logement, façon client mail : recherche, filtre (en cours, à venir, passées, annulées), tri ; conversation Lodgify avec le voyageur et **réponse** (poussée par Lodgify sur Airbnb, Booking.com ou par e-mail, sans double envoi) ; **valeur** et détail du prix (devis Lodgify, hors commission de la plateforme).
- **Rocket Place** : chaque logement est lié à un lieu (onglet Infos). Serrures (état, batterie, historique), domotique, documents et stock viennent de ce lieu, relayés par PMS. Un **code clavier** (accès Rocket Place) est prévu pour chaque séjour à venir (ouvert 1 h avant l'arrivée, fermé 1 h après le départ) et **envoyé à la serrure seulement sur un clic** confirmé ; un séjour commencé n'est jamais modifié.
- **Livret d'accueil et écran TV** (onglet Livret & TV) : livret par logement, lien voyageur secret par séjour (`/g/…`, actif de J-2 à J+1, prénom seul, code de la porte seulement une fois envoyé à la serrure), écran TV en mode kiosque (`/tv/…`, sans code, rechargé 30 min avant chaque arrivée) ; **multilingue** (fr par défaut, en/es/de/it facultatifs par rubrique), apparence par logement (couleur, couverture, onglets ou colonnes), **QR codes**, statistiques de visite sans donnée visiteur, bouton **Envoyer le livret** (Lodgify ou e-mail, sur clic confirmé).
- **E-mails** (par Rocket Mailer, jamais d'IMAP/SMTP dans PMS) : conversations de la boîte partagée liées à la réservation (adresse du voyageur ou numéro de réservation dans l'objet), composeur **Message Lodgify** ou **E-mail**, envoi seulement sur clic.
- **Bilan** (onglet Bilan) : revenus Lodgify nuit par nuit, charges et autres recettes saisies dans PMS, totaux par mois et par catégorie, occupation, résultat, **export CSV** ; justificatif choisi dans les documents du lieu, **import des relevés** de plateformes (commissions, taxe de séjour) en CSV sans doublon.
- **Ménage après départ** : une tâche Rocket Place par départ (jusqu'à l'arrivée suivante), déplacée ou annulée avec la réservation, visible dans la timeline.
- **Timeline** d'un logement ou de tous : séjours, codes, passages aux serrures.
- **Tableau de bord** : arrivées et départs du jour, ménages entre deux séjours, occupation et revenus sur 30 jours, prochaines arrivées ; état des services Lodgify et Rocket Place.
- **API** pour les applications externes (jeton `rpm_…`), par exemple LoussaHousing.

## Images Docker

Publiées par la CI (workflow réutilisable `docker-images.yml` de rocket-core) **uniquement** sur tag `vX.Y.Z` et lancement manuel (Actions → CI → Run workflow) :

| Image | Contenu |
| --- | --- |
| `ghcr.io/fayouz/rocket-pms-api` | API Symfony + worker (FrankenPHP Alpine, `composer --no-dev`, opcache, cible `prod` de `backend/Dockerfile`) |
| `ghcr.io/fayouz/rocket-pms-front` | Front Nuxt (`.output` seul, `node:22-alpine`, utilisateur `node`, cible `prod` de `frontend/Dockerfile`) |

- Tags : `vX.Y.Z`, `X.Y.Z`, `X.Y`, `latest` (dernier tag) et `sha-<commit>` ; multi-arch `linux/amd64` + `linux/arm64` ; labels OCI (source, version, révision), SBOM et provenance.
- Sur les PR et branches : build `linux/amd64` de validation + tests de fumée, jamais poussé.
- Le dépôt est privé : les images sont **privées** (visibilité par défaut, à garder). Plan GitHub Free : 500 Mo de stockage et 1 Go/mois de transfert pour les paquets privés (au-delà : facturé ou bloqué) — supprimer les anciennes versions (`sha-…`) et ne publier que sur tag. Pour tirer les images : `docker login ghcr.io` avec un jeton `read:packages`.
- Exemple de déploiement : [`compose.prod.yaml`](compose.prod.yaml) (base, API, worker, front, labels Traefik en commentaire).

## Gitflow

`main` : production ; `develop` : intégration ; `feature/*` → `develop` (section `[Non publié]` du [CHANGELOG](CHANGELOG.md)) ; `release/*` et `hotfix/*` → `main`, puis tag `vX.Y.Z` créé depuis GitHub.

## Secrets des intégrations (coffre)

Les jetons et clés des intégrations sont gardés **chiffrés en base** dans le coffre de rocket-core (Administration → **Secrets**), plus dans le `.env`. Le code les lit par `App\Secrets\IntegrationSecrets` ; l'API ne renvoie jamais leur valeur (aperçu masqué `••••1234`). Seule la clé maîtresse `ROCKET_SECRETS_KEY` (`php bin/console rocket:secrets:generate-key`) reste dans l'environnement : la sauvegarder hors de la base.

| Ancienne variable | Secret du coffre |
|---|---|
| `LODGIFY_API_KEY` | `lodgify.api_key` |
| `ROCKET_PLACE_TOKEN` | `rocket.place.token` |
| `ROCKET_CLEAN_TOKEN` | `rocket.clean.token` |
| `ROCKET_STOCK_TOKEN` | `rocket.stock.token` |
| `ROCKET_MAILER_TOKEN` | `rocket.mailer.token` |
| `CONNECTOR_X` (connecteurs Lodgify, champ `secretVar`) | `connector_x` (champ `secret`, converti par la migration Doctrine) |

Migration d'une instance existante :

1. `php bin/console rocket:secrets:generate-key` → `ROCKET_SECRETS_KEY` dans `.env.local` (ou l'environnement du conteneur) ; `php bin/console doctrine:migrations:migrate`.
2. `php bin/console app:secrets:migrate-env --dry-run` puis `php bin/console app:secrets:migrate-env` : importe les variables ci-dessus sous leur nom de secret (idempotent, `--overwrite` pour remplacer).
3. Retirer ces variables du `.env.local` / de l'environnement. Pendant la transition, une variable encore présente sert de repli (avertissement « deprecated » dans les journaux).

