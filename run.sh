docker compose rm redis
docker compose -f docker-compose.yml --compatibility up --force-recreate --build
