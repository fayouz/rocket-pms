# Rocket PMS

Gestion de locations courte durée (Property Management System) : **logements**, **réservations Lodgify** (liste façon boîte mail, conversation avec le voyageur et réponse, valeur et détail du prix), **serrures connectées Nuki** (état, batterie, codes clavier par séjour), **timeline** et **tableau de bord**. Brique du Middleware Rocket, sur le socle [rocket-core](https://github.com/fayouz/rocket-core).

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

Sans `LODGIFY_API_KEY` ni `NUKI_API_TOKEN`, l'application tourne sur des **données de démo** (deux logements fictifs, leurs réservations et leurs serrures) : rien n'est lu ni écrit chez Lodgify ou Nuki.

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
| `NUKI_API_TOKEN` | Jeton de l'API web Nuki ; le droit `smartlock.auth` est nécessaire pour créer les codes. Vide : démo. |
| `PMS_TIMEZONE` | Fuseau des logements (heures d'arrivée et de départ, codes clavier). `Europe/Paris` par défaut. |

## Fonctionnalités (v0.1)

- **Logements** : créés depuis Lodgify (« Synchroniser »), renommables, couleur repère.
- **Réservations** d'un logement, façon client mail : recherche, filtre (en cours, à venir, passées, annulées), tri ; conversation Lodgify avec le voyageur et **réponse** (poussée par Lodgify sur Airbnb, Booking.com ou par e-mail, sans double envoi) ; **valeur** et détail du prix (devis Lodgify, hors commission de la plateforme).
- **Serrures Nuki** : état, batterie, historique ; un **code clavier** est prévu pour chaque séjour à venir (ouvert 1 h avant l'arrivée, fermé 1 h après le départ) et **envoyé à la serrure seulement sur un clic** confirmé ; un séjour commencé n'est jamais modifié.
- **Timeline** d'un logement ou de tous : séjours, codes, passages aux serrures.
- **Tableau de bord** : arrivées et départs du jour, ménages entre deux séjours, occupation et revenus sur 30 jours, prochaines arrivées ; état des services Lodgify et Nuki.
- **API** pour les applications externes (jeton `rpm_…`), par exemple LoussaHousing.

## Gitflow

`main` : production ; `develop` : intégration ; `feature/*` → `develop` (section `[Non publié]` du [CHANGELOG](CHANGELOG.md)) ; `release/*` et `hotfix/*` → `main`, puis tag `vX.Y.Z` créé depuis GitHub.
