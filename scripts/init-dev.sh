#!/usr/bin/env sh
set -eu

project_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$project_root"

docker compose up -d --build
docker compose run --rm backend ./yii migrate/up
docker compose run --rm backend ./yii dev/seed

echo "Portal: http://localhost:8080"
echo "Readiness: http://localhost:8080/health/ready"
