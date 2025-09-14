# PHP Download Router - Copilot Coding Agent Instructions

## Project Overview

### Summary
PHP Download Router is a Docker-based API Platform application that centralizes download requests from various platforms (YouTube, image hosting sites, etc.) through a single REST API. The project consists of:
- **API**: Symfony 7.3 + API Platform powered backend (PHP 8.4)
- **PWA**: Next.js 15 frontend for web interface
- **Worker**: Background job processing using Symfony Messenger
- **Database**: PostgreSQL for persistence
- **Queue**: RabbitMQ for message queuing

### Tech Stack & Languages
- **Backend**: PHP 8.4, Symfony 7.3, API Platform 4.1, Doctrine ORM
- **Frontend**: TypeScript, Next.js 15, React 19, TailwindCSS 4.1
- **Testing**: PHPUnit, Playwright (E2E)
- **Infrastructure**: Docker, Docker Compose, FrankenPHP
- **External Tools**: yt-dlp, gallery-dl, ffmpeg

### Repository Size
- ~36 PHP files, ~10 TypeScript/JavaScript files
- Modular architecture with clear separation of concerns
- Main components: API (api/), PWA (pwa/), E2E tests (e2e/), Helm charts (helm/)

## Build & Validation Instructions

### Prerequisites
- Docker & Docker Compose (required)
- Internet connection for pulling dependencies

### Environment Setup
**ALWAYS** use Docker Compose - the application is fully containerized. Local PHP/Node.js installations are not supported.

### Key Commands

#### Build (Required First Step)
```bash
# Full build from scratch (takes 5-10 minutes)
IMAGES_PREFIX=local docker compose build --no-cache --pull

# Known Issue: SSL certificate problems may occur during pip install of gallery-dl
# Workaround: Retry the build command if it fails with SSL errors
```

#### Start Services
```bash
# Start all services (API, PWA, database, queue, worker)
IMAGES_PREFIX=local docker compose up --wait -d

# Alternative using Makefile
make up
```

#### Development Workflow
```bash
# Install PHP dependencies (run after any composer.json changes)
docker compose exec -T php composer install --prefer-dist --no-progress --optimize-autoloader

# Create test database
docker compose exec -T php bin/console -e test doctrine:database:create

# Run database migrations (always required after DB schema changes)
docker compose exec -T php bin/console -e test doctrine:migrations:migrate --no-interaction

# Run PHP tests
docker compose exec -T php bin/phpunit

# Validate database schema
docker compose exec -T php bin/console -e test doctrine:schema:validate
```

#### Code Quality
```bash
# PHP CS Fixer (code formatting)
docker compose exec php vendor/bin/php-cs-fixer fix

# Location: api/.php-cs-fixer.dist.php uses @Symfony rules
```


#### Unit Tests

You should **ALWAYS** run tests in docker to ensure consistency:
- this ensures the correct PHP version and environment

```bash
# Run PHPUnit tests
docker compose exec -T php bin/phpunit
# Location: api/phpunit.xml.dist
``` 

#### Useful Commands
```bash
# Execute any command in PHP container
make php bin/console [command]

# View logs
make logs

# Stop services
make down

# Force recreate (useful after Docker changes)
make build
```

### Service URLs (when running)
- **API**: http://localhost (HTTP) / https://localhost (HTTPS)
- **API Documentation**: https://localhost/docs
- **PWA**: https://localhost (Accept: text/html header)
- **Mercure Hub**: https://localhost/.well-known/mercure

### Build Times & Known Issues
- **Build time**: 5-10 minutes for full build
- **SSL Issues**: gallery-dl installation may fail with certificate errors - retry the build
- **Service startup**: Wait 60 seconds for health checks to pass
- **Port conflicts**: Default ports 80/443 - modify HTTP_PORT/HTTPS_PORT env vars if needed

## Project Architecture & Layout

### Directory Structure
```
/
├── api/                    # Symfony API Platform application
│   ├── src/
│   │   ├── Entity/         # Doctrine entities (DownloadJob, Downloader, SupportedSite)
│   │   ├── Service/        # Business logic & downloader implementations
│   │   │   └── Downloader/ # YoutubeDl, GalleryDl, Mock downloaders
│   │   ├── Handler/        # Message handlers for async processing
│   │   ├── State/          # API Platform state processors
│   │   ├── Repository/     # Database repositories
│   │   ├── Dto/           # Data transfer objects
│   │   ├── Enum/          # Enumerations (DownloadState, DownloaderType)
│   │   └── Validator/     # Custom validation logic
│   ├── config/            # Symfony configuration
│   ├── tests/             # PHPUnit tests
│   ├── migrations/        # Doctrine migrations
│   ├── composer.json      # PHP dependencies
│   └── phpunit.xml.dist   # PHPUnit configuration
├── pwa/                   # Next.js frontend
│   ├── pages/            # Next.js pages
│   ├── components/       # React components
│   ├── package.json      # Node.js dependencies
│   └── tsconfig.json     # TypeScript configuration
├── e2e/                  # Playwright end-to-end tests
├── helm/                 # Kubernetes deployment charts
├── .github/
│   └── workflows/        # CI/CD pipelines
├── compose.yaml          # Production Docker Compose
├── compose.override.yaml # Development overrides
├── Makefile             # Build shortcuts
└── update-deps.sh       # Dependency update script
```

### Key Configuration Files
- **api/.php-cs-fixer.dist.php**: PHP code style rules (@Symfony)
- **api/phpunit.xml.dist**: PHPUnit test configuration
- **compose.yaml**: Multi-service Docker setup
- **Dockerfile** (in api/): PHP application container
- **.github/workflows/ci.yml**: Main CI pipeline
- **.github/workflows/e2e.yml**: End-to-end test pipeline

### Core Architecture Patterns
- **DDD-style entities**: DownloadJob, Downloader, SupportedSite
- **Strategy pattern**: Multiple downloader implementations (YoutubeDl, GalleryDl, Mock)
- **Message queues**: Async job processing via Symfony Messenger + RabbitMQ
- **API Platform**: Auto-generated REST API with OpenAPI spec
- **State processors**: Custom API Platform processors for business logic

### CI/CD Pipeline
**GitHub Actions workflows run on push/PR:**
1. **ci.yml**: Docker build, service startup, PHPUnit tests, schema validation
2. **e2e.yml**: Playwright browser tests
3. **Docker lint**: Hadolint for Dockerfile validation

**Tests must pass before merge** - always run these locally:
```bash
docker compose exec -T php bin/phpunit
docker compose exec -T php bin/console -e test doctrine:schema:validate
```

### Dependencies & External Services
- **yt-dlp**: YouTube and video platform downloads
- **gallery-dl**: Image platform downloads  
- **FrankenPHP**: High-performance PHP application server
- **Mercure**: Real-time updates via Server-Sent Events
- **PostgreSQL**: Primary database
- **RabbitMQ**: Message queue for background jobs

### File System Layout
```
api/src/
├── Command/           # Console commands
├── Controller/        # API controllers (minimal - mostly API Platform)
├── Dto/              # Data transfer objects
├── Entity/           # Doctrine entities
├── Enum/             # PHP enumerations
├── Factory/          # Object factories
├── Handler/          # Message handlers
├── Model/            # Domain models/interfaces
├── Repository/       # Database repositories
├── Service/          # Business logic services
├── State/            # API Platform state processors
└── Validator/        # Custom validators
```

## Important Notes for Coding Agents

### Always Follow These Rules:
1. **Use Docker exclusively** - never install PHP/Node locally
2. **Run tests after changes** - PHPUnit and schema validation are mandatory
3. **Use existing patterns** - follow the downloader interface for new downloaders
4. **Respect the architecture** - entities, services, handlers pattern
5. **Update migrations** - any entity changes require new Doctrine migrations

### Common Tasks:
- **New downloader**: Implement `DownloaderInterface` in `src/Service/Downloader/`
- **API changes**: Modify entities, update migrations, run schema validation
- **Frontend changes**: Edit PWA components, rebuild with `docker compose build pwa`
- **Background jobs**: Create handlers in `src/Handler/`

### Quick Validation:
```bash
# After any changes, always run:
docker compose exec -T php bin/phpunit
docker compose exec -T php bin/console -e test doctrine:schema:validate
curl -v --fail-with-body http://localhost  # API health check
```

### Dockerfile Changes:
**CRITICAL**: Whenever you change ANY `Dockerfile`, you MUST run:
```bash
make down && make build && make up
```
This ensures that Docker images are properly rebuilt and can boot successfully.

### Migration Changes:
**MANDATORY STEPS** when you create database migrations:

**YOU MUST ensure you start from a clean slate:**
1. **Remove all containers, volumes and traces:**
   ```bash
   make down
   docker system prune -af --volumes
   ```

2. **Rebuild all images from scratch:**
   ```bash
   make build
   # OR alternatively:
   IMAGES_PREFIX=local docker compose build --no-cache --pull
   ```

3. **Start the entire stack:**
   ```bash
   make up
   # Wait for all health checks to pass (up to 60 seconds)
   ```

4. **Run all migrations:**
   ```bash
   docker compose exec -T php bin/console doctrine:migrations:migrate --no-interaction
   ```

5. **Verify application serves content:**
   ```bash
   curl -v --fail-with-body http://localhost
   # Should return HTTP 200 with API Platform documentation
   ```

**Application MUST be able to serve content** - if any step fails, debug before proceeding.

**Trust these instructions** - only search for additional information if these instructions are incomplete or incorrect.
