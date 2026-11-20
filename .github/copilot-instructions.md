# FetchDock-server - Copilot Instructions

## Project Overview
Containerized API Platform application centralizing media download requests via a single REST API.
- **API (`api/`):** PHP 8.4, Symfony 7.3, API Platform 4.1, Doctrine ORM
- **PWA (`pwa/`):** Next.js 15, React 19, TypeScript, TailwindCSS 4.1
- **Infrastructure:** Docker Compose, FrankenPHP, PostgreSQL, RabbitMQ (Symfony Messenger), Mercure
- **Media Tools:** `yt-dlp`, `gallery-dl`, `ffmpeg`

## Environment & Key Commands
**ALWAYS run commands inside Docker Compose**—local PHP/Node installations are not supported.

```bash
# Build & lifecycle (full build takes 5-10 min; retry if gallery-dl hits SSL errors)
make build                                              # or: IMAGES_PREFIX=local docker compose build --pull
make up                                                 # start stack & wait for health checks (~60s)
make down                                               # stop services
make logs                                               # view container logs

# Dependencies & Database
docker compose exec -T php composer install --prefer-dist --no-progress --optimize-autoloader
docker compose exec -T php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec -T php bin/console -e test doctrine:database:create
docker compose exec -T php bin/console -e test doctrine:migrations:migrate --no-interaction

# Validation & Code Style (MUST pass before merge)
make test                                               # or: docker compose exec -T php bin/phpunit
docker compose exec -T php bin/console -e test doctrine:schema:validate
docker compose exec -T php vendor/bin/php-cs-fixer fix  # uses api/.php-cs-fixer.dist.php (@Symfony)
curl -v --fail-with-body http://localhost               # API health check (HTTP 200)
```

### Service URLs
- **API & Docs:** `http://localhost` / `https://localhost/docs`
- **PWA:** `https://localhost` (`Accept: text/html`)
- **Mercure Hub:** `https://localhost/.well-known/mercure`

## Architecture & Directory Layout
```text
/
├── api/
│   ├── src/
│   │   ├── Command/            # Console commands
│   │   ├── Controller/         # Minimal controllers (prefer API Platform)
│   │   ├── Dto/                # Data Transfer Objects
│   │   ├── Entity/             # Doctrine entities (DownloadJob, Downloader, SupportedSite)
│   │   ├── Enum/               # PHP enums
│   │   ├── Event/              # Symfony events & EventListener/
│   │   ├── Factory/            # Object factories
│   │   ├── Handler/            # Messenger async job handlers
│   │   ├── Model/              # Domain models/interfaces
│   │   ├── Repository/         # Doctrine repositories
│   │   ├── Service/Downloader/ # Strategy pattern: DownloaderInterface (YoutubeDl, GalleryDl, Mock)
│   │   ├── State/              # API Platform state processors/providers
│   │   └── Validator/          # Custom validators
│   ├── config/
│   ├── migrations/
│   └── tests/                  # PHPUnit tests (api/phpunit.xml.dist)
├── pwa/                        # Next.js frontend (pages/, components/)
├── e2e/                        # Playwright E2E tests
└── helm/                       # Kubernetes charts
```

## Coding & Task Rules
1. **API Changes:** Prefer `#[ApiResource]`, DTOs (`api/src/Dto`), and custom `State\` processors/providers over fat controllers. Validate OpenAPI generation with `docker compose exec -T php bin/console api:openapi:export` (and redirect to `../contracts/openapi.json` if that directory exists locally).
2. **New Downloaders:** Implement `DownloaderInterface` in `api/src/Service/Downloader/`.
3. **Background Jobs:** Create Symfony Messenger handlers in `api/src/Handler/`.
4. **Database Changes:** Generate a Doctrine migration, run migrations in both dev and `test` environments, and run `doctrine:schema:validate`. **Never** run `docker system prune` or wipe volumes.
5. **Dockerfile Changes:** Rebuild affected images via `make down && make build && make up`.
6. **PWA Changes:** Rebuild frontend container via `docker compose build pwa`.
