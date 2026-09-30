.PHONY: install test test-cov test-integration lint clean docker-build

IMAGE_NAME := php-audit-test
AUTHCLIENT_DIR ?= $(abspath $(CURDIR)/../php-authclient)
REDIS_CONTAINER := php-audit-redis
NETWORK := php-audit-net

DOCKER_RUN := docker run --rm \
	-v $(CURDIR):/app \
	-v $(AUTHCLIENT_DIR)/src:/authclient/src:ro \
	-v $(AUTHCLIENT_DIR)/composer.json:/authclient/composer.json:ro \
	-w /app

docker-build:
	@docker build -q -t $(IMAGE_NAME) -f Dockerfile.test . > /dev/null

vendor: composer.json
	@$(MAKE) install

install: docker-build
	$(DOCKER_RUN) $(IMAGE_NAME) sh tools/install-deps.sh

test: docker-build vendor
	$(DOCKER_RUN) $(IMAGE_NAME) vendor/bin/phpunit --testdox --exclude-group integration

test-cov: docker-build vendor
	$(DOCKER_RUN) $(IMAGE_NAME) sh -c "XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-text --exclude-group integration"

test-integration: docker-build vendor
	@docker network create $(NETWORK) > /dev/null 2>&1 || true
	@docker rm -f $(REDIS_CONTAINER) > /dev/null 2>&1 || true
	@docker run -d --rm --name $(REDIS_CONTAINER) --network $(NETWORK) redis:7-alpine > /dev/null
	$(DOCKER_RUN) --network $(NETWORK) -e REDIS_URL=tcp://$(REDIS_CONTAINER):6379 $(IMAGE_NAME) \
		vendor/bin/phpunit --testdox --group integration; \
		status=$$?; docker rm -f $(REDIS_CONTAINER) > /dev/null 2>&1; exit $$status

lint: docker-build vendor
	$(DOCKER_RUN) $(IMAGE_NAME) vendor/bin/phpstan analyse --memory-limit=512M

clean:
	rm -rf vendor/ composer.lock composer.local.json composer.local.lock .phpunit.cache
