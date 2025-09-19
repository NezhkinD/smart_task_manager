PHP_CONTAINER_NAME=stm_php

docker_up:
	docker-compose up -d

docker_down:
	docker-compose up -d

docker_build:
	docker-compose build

doctrine_make_entity:
	docker exec -it ${PHP_CONTAINER_NAME} bash -c "php bin/console make:entity"

doctrine_make_migration:
	docker exec -it ${PHP_CONTAINER_NAME} bash -c "php bin/console make:migration"

doctrine_migrate:
	docker exec -it ${PHP_CONTAINER_NAME} bash -c "yes | php bin/console doctrine:migrations:migrate"


unlock:
	sudo chown -R ${USER}:${USER} ./app
	chmod 775 ./app