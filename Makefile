# Makefile
DOCKER_COMPOSE_PREFIX ?= IMAGES_PREFIX=local
ENV ?=

up:
	$(DOCKER_COMPOSE_PREFIX) docker compose up --wait -d

logs:
	$(DOCKER_COMPOSE_PREFIX) docker compose logs -f

down:
	$(DOCKER_COMPOSE_PREFIX) docker compose down

restart:
	$(DOCKER_COMPOSE_PREFIX) docker compose down
	$(DOCKER_COMPOSE_PREFIX) docker compose up --wait -d

# Run the ./update-deps.sh script with prefix to update dependencies
update-deps:
	$(DOCKER_COMPOSE_PREFIX) ./update-deps.sh $(ENV)

force-recreate:
	$(DOCKER_COMPOSE_PREFIX) docker compose up --force-recreate --wait -d

build:
	$(DOCKER_COMPOSE_PREFIX) docker compose build --no-cache --pull

# Run command in the php container (ie: bin/console doctrine:migrations:migrate)
Arguments := $(wordlist 2,$(words $(MAKECMDGOALS)),$(MAKECMDGOALS))

php:
	$(DOCKER_COMPOSE_PREFIX) docker compose exec php $(Arguments)
