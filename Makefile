# Makefile
DOCKER_COMPOSE_PREFIX ?= IMAGES_PREFIX=ghcr.io/pbxg33k/php-download-router XDEBUG_MODE=debug
ENV ?=

up:
	$(DOCKER_COMPOSE_PREFIX) docker compose up --wait -d

logs:
	$(DOCKER_COMPOSE_PREFIX) docker compose logs -f

down:
	$(DOCKER_COMPOSE_PREFIX) docker compose down

clean:
	$(DOCKER_COMPOSE_PREFIX) docker compose down -v --remove-orphans

clean-images:
	$(DOCKER_COMPOSE_PREFIX) docker compose down -v --rmi all --remove-orphans

restart:
	$(DOCKER_COMPOSE_PREFIX) docker compose down
	$(DOCKER_COMPOSE_PREFIX) docker compose up --wait -d

# Run the ./update-deps.sh script with prefix to update dependencies
update-deps:
	$(DOCKER_COMPOSE_PREFIX) ./update-deps.sh $(ENV)

force-recreate:
	$(DOCKER_COMPOSE_PREFIX) docker compose up --force-recreate --wait -d

.PHONY: build
build:
	$(DOCKER_COMPOSE_PREFIX) docker compose build --no-cache --pull

rebuild: clean-images build up

prep-test:
	$(DOCKER_COMPOSE_PREFIX) docker compose exec -T php bin/console -e test doctrine:database:create
	$(DOCKER_COMPOSE_PREFIX) docker compose exec -T php bin/console -e test doctrine:migrations:migrate --no-interaction
	$(DOCKER_COMPOSE_PREFIX) docker compose exec -T php bin/console -e test doctrine:fixtures:load --no-interaction

test:
	$(DOCKER_COMPOSE_PREFIX) docker compose exec --env XDEBUG_MODE=coverage -it php ./bin/phpunit --colors=always --testdox

integration:
	docker run --network host -w /app -v ./e2e:/app --rm --ipc=host mcr.microsoft.com/playwright:v1.50.0-noble /bin/sh -c 'npm i; npx playwright test;'

# Run command in the php container (ie: bin/console doctrine:migrations:migrate)
Arguments := $(wordlist 2,$(words $(MAKECMDGOALS)),$(MAKECMDGOALS))

php:
	$(DOCKER_COMPOSE_PREFIX) docker compose run --entrypoint="" --rm -it php $(Arguments)
