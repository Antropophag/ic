#!/usr/bin/env sh
set -eu

project_root=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
cd "$project_root"

: "${COMPOSE:?COMPOSE must be provided by Makefile}"
[ -f .env.dev ] || {
  echo "Нет .env.dev. Выполните make init." >&2
  exit 2
}

export COMPOSE_ENV_FILE=.env.dev
compose="$COMPOSE --env-file .env.dev -f compose.yaml -f compose.dev.yaml"
$compose config --quiet
$compose up -d --build
$compose run --rm backend php yii migrate/up --interactive=0
$compose run --rm backend php yii dev/seed

frontend_port=${FRONTEND_PORT:-8080}
echo "Development: http://localhost:$frontend_port"
echo "Readiness:   http://localhost:$frontend_port/health/ready"
