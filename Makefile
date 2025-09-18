

docker_up:
	docker-compose up -d

docker_build:
	docker-compose build

unlock:
	sudo chown -R ${USER}:${USER} ./app
	chmod 775 ./app