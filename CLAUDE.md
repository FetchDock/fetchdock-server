# FetchDock-server (API Platform + Admin PWA)

## Stack & Structure
- **Environment:** Fully containerized via Docker Compose—never run local `php` or `node` commands on the host.
- **Backend (`api/`):** PHP 8.4, Symfony 7.3, API Platform 4.1, Doctrine ORM, PostgreSQL, Symfony Messenger + RabbitMQ.
    - Key dirs: `api/src/Entity`, `api/src/Dto`, `api/src/State`, `api/src/Service/Downloader`, `api/src/Handler`, `api/migrations`, `api/tests`.
- **Admin PWA (`pwa/`):** Next.js 15, React 19, TypeScript, TailwindCSS 4.1.
- **Contract Output:** `../contracts/openapi.json`

## Key Commands (Run via Docker)
- **Export OpenAPI spec:** `make openapi`
- **Run PHPUnit tests:** `make test` (or `docker compose exec -T api bin/phpunit`)
- **Validate DB schema:** `docker compose exec -T api bin/console -e test doctrine:schema:validate`
- **Run test migrations:** `docker compose exec -T api bin/console -e test doctrine:migrations:migrate --no-interaction`
- **Code style fix:** `docker compose exec -T api vendor/bin/php-cs-fixer fix`


## Architecture & Safety Rules
- Prefer API Platform `#[ApiResource]` attributes, DTOs (`api/src/Dto`), and custom `State\ProcessorInterface` / `ProviderInterface` over custom controllers.
- Implement new downloaders via `DownloaderInterface` in `api/src/Service/Downloader/` and async jobs in `api/src/Handler/`.
- **Always export `../contracts/openapi.json`** after adding or modifying any API endpoint, filter, or serialization group.
- **Validation:** Run PHPUnit and `doctrine:schema:validate` after backend/entity changes.
- **Architecture docs:** if a change affects entities or relations, endpoint groups, messages or handlers, Mercure topics, auth or roles, or settings layers, update `../docs/ARCHITECTURE.md` (and add an ADR in `../docs/adr/` for lasting decisions). After `make openapi`, run `make -C .. contracts:index`.
- **Destructive commands forbidden:** Never run `docker system prune`, volume wipes, or `--no-cache` full image rebuilds—ask me to run those manually if needed.

## Token-Saving Rules
- Never read `api/vendor/`, `api/var/`, `pwa/node_modules/`, `pwa/.next/`, or `../fetchdock-extension/` unless explicitly asked.
- Keep responses concise; I will review full diffs in PhpStorm.
