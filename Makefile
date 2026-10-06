SHELL := /bin/bash

check_defined = \
    $(strip $(foreach 1,$1, \
        $(call __check_defined,$1,$(strip $(value 2)))))
__check_defined = \
    $(if $(value $1),, \
      $(error Required parameter is missing: $1$(if $2, ($2))))

APP_SERVICE_NAME := php
XDEBUG_SERVICE_NAME := xdebug
TEST_SERVICE_NAME := test
PMA_SERVICE_NAME := phpmyadmin
PROD_COMPOSE := bin/docker-compose -f docker-compose.prod.yml --env-file .env.prod.local

include docker-compose.env
-include .env.local

.PHONY: default dcps dcupd dcupd dcstop dcdn dclogs dcshell dcxdbg dctest dccheck prod php prod-logs prod-down

default: dcps

# Get services URLs and docker-compose process status.
dcps:
	@bin/docker-compose ps -a
	@echo

	$(eval APP_ID := $(shell bin/docker-compose ps -q $(APP_SERVICE_NAME) 2> /dev/null))
	$(eval APP_PORT := $(shell docker inspect $(APP_ID) --format='{{json (index (index .NetworkSettings.Ports "8080/tcp") 0).HostPort}}' 2> /dev/null))
	@echo $(APP_SERVICE_NAME): $(if $(APP_PORT), "http://localhost:$(APP_PORT)", "port not found.")

	$(eval PMA_ID := $(shell bin/docker-compose ps -q $(PMA_SERVICE_NAME) 2> /dev/null))
	$(eval PMA_PORT := $(shell docker inspect $(PMA_ID) --format='{{json (index (index .NetworkSettings.Ports "80/tcp") 0).HostPort}}' 2> /dev/null))
	@echo $(PMA_SERVICE_NAME): $(if $(PMA_PORT), "http://localhost:$(PMA_PORT)", "port not found.")

	-$(eval XDEBUG_ID := $(shell bin/docker-compose  --profile xdebug ps -q $(XDEBUG_SERVICE_NAME) 2> /dev/null))
	-$(eval XDEBUG_PORT := $(shell docker inspect $(XDEBUG_ID) --format='{{json (index (index .NetworkSettings.Ports "8080/tcp") 0).HostPort}}' 2> /dev/null))
	@echo $(XDEBUG_SERVICE_NAME): $(if $(XDEBUG_PORT), "http://localhost:$(XDEBUG_PORT)", "port not found.")

# Rebuild images, remove orphans, and docker-compose up.
dcupd:
	bin/docker-compose up -d --build --remove-orphans

# Stop all runner containers.
dcstop:
	bin/docker-compose stop

# Stop all runner containers.
dcdn:
	bin/docker-compose down --remove-orphans --volumes

# Get core-api container logs.
dclogs:
	bin/docker-compose logs --tail=100 -f init $(APP_SERVICE_NAME)

# Get a bash inside running core-api container.
dcshell:
	bin/docker-compose run --rm --no-deps $(APP_SERVICE_NAME) bash

# Start core-api with xdebug enabled.
dcxdbg:
	bin/docker-compose --profile $(XDEBUG_SERVICE_NAME) up -d --build --remove-orphans
	bin/docker-compose --profile $(XDEBUG_SERVICE_NAME) logs --tail=100 -f $(XDEBUG_SERVICE_NAME)

# Start core-api test
dctest:
	bin/docker-compose --profile $(TEST_SERVICE_NAME) run --rm test composer test

# Start core-api check
dccheck:
	bin/docker-compose --profile $(TEST_SERVICE_NAME) run --rm test composer check
dcexec:
	bin/docker-compose exec $(APP_SERVICE_NAME) bash
# Production: rebuild images, (re)start the stack, install deps, migrate, warm cache.
prod:
	$(PROD_COMPOSE) up -d --build --remove-orphans
	$(PROD_COMPOSE) exec -T $(APP_SERVICE_NAME) composer install --no-dev --optimize-autoloader --no-interaction
	$(PROD_COMPOSE) exec -T $(APP_SERVICE_NAME) php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
	$(PROD_COMPOSE) exec -T $(APP_SERVICE_NAME) php bin/console cache:clear
	$(PROD_COMPOSE) exec -T $(APP_SERVICE_NAME) php bin/console cache:warmup
	$(PROD_COMPOSE) ps

# Get a bash inside the running production php container.
php:
	$(PROD_COMPOSE) exec $(APP_SERVICE_NAME) bash

# Follow production logs.
prod-logs:
	$(PROD_COMPOSE) logs --tail=100 -f

# Stop production stack (keeps the database volume).
prod-down:
	$(PROD_COMPOSE) down --remove-orphans
# Include the .d makefiles. The - at the front suppresses the errors of missing
-include makefiles.d/*