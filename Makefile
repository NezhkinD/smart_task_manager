DOCKER_REGISTRY ?= localhost:5000
TAG ?= latest
PHP_CONTAINER_NAME = stm_php

# === Dev ===
dev-run:
	docker-compose -f docker-compose-dev.yaml up -d --build

dev-down:
	docker-compose -f docker-compose-dev.yaml down

# === Prod build & push ===
build:
	docker build -t $(DOCKER_REGISTRY)/stm/php:$(TAG) -f .docker/Dockerfile.php.prod .
	docker build -t $(DOCKER_REGISTRY)/stm/nginx:$(TAG) -f .docker/Dockerfile.nginx.prod .

push:
	docker push $(DOCKER_REGISTRY)/stm/php:$(TAG)
	docker push $(DOCKER_REGISTRY)/stm/nginx:$(TAG)

release: build push

test:
	docker exec stm_php bash -c "cd /home/app && php bin/phpunit"

coverage:
	docker exec stm_php bash -c "cd /home/app && XDEBUG_MODE=coverage php bin/phpunit --coverage-text --coverage-filter src"

# === Doctrine (dev) ===
doctrine_make_entity:
	docker exec -it $(PHP_CONTAINER_NAME) bash -c "php bin/console make:entity"

doctrine_make_migration:
	docker exec -it $(PHP_CONTAINER_NAME) bash -c "php bin/console make:migration"

doctrine_migrate:
	docker exec -it $(PHP_CONTAINER_NAME) bash -c "yes | php bin/console doctrine:migrations:migrate"

unlock:
	sudo chown -R $(USER):$(USER) ./app
	chmod 775 ./app
