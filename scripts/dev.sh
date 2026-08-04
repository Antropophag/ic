#!/usr/bin/env sh
set -eu

project_root=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
cd "$project_root"

: "${COMPOSE:?COMPOSE must be provided by Makefile}"
: "${DEV_PROJECT:?DEV_PROJECT must be provided by Makefile}"
: "${DEV_ENV_FILE:?DEV_ENV_FILE must be provided by Makefile}"
[ -f "$DEV_ENV_FILE" ] || {
  echo "Нет .env.dev. Выполните make init." >&2
  exit 2
}

export COMPOSE_ENV_FILE="$DEV_ENV_FILE"
compose="$COMPOSE -p $DEV_PROJECT --env-file $DEV_ENV_FILE -f compose.yaml -f compose.dev.yaml"
. scripts/compose-metadata.sh
$compose config >/dev/null
$compose up -d --build --force-recreate
$compose run --rm backend php yii migrate/up --interactive=0
$compose run --rm backend php yii dev/seed

frontend_url=$(compose_http_url frontend 8080)
echo "Development: $frontend_url"
echo "Readiness:   $frontend_url/health/ready"
