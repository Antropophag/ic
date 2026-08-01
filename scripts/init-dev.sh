#!/usr/bin/env sh
set -eu

project_root=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
cd "$project_root"

[ -f .env.dev ] || {
  echo "Создайте локальный .env.dev из .env.dev.example и заполните параметры окружения." >&2
  exit 2
}

docker compose -f compose.yaml -f compose.dev.yaml --env-file .env.dev up -d --build
docker compose -f compose.yaml -f compose.dev.yaml --env-file .env.dev run --rm backend ./yii migrate/up
docker compose -f compose.yaml -f compose.dev.yaml --env-file .env.dev run --rm backend ./yii dev/seed

echo "Portal: http://localhost:8080"
echo "Readiness: http://localhost:8080/health/ready"
