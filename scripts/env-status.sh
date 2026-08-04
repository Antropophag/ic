#!/bin/sh
set -eu

cd "$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)"
: "${COMPOSE:?COMPOSE must be provided by Makefile}"
: "${CONTAINER_ENGINE:?CONTAINER_ENGINE must be provided by Makefile}"
: "${PROD_PROJECT:?PROD_PROJECT must be provided by Makefile}"
: "${DEV_PROJECT:?DEV_PROJECT must be provided by Makefile}"
: "${PROD_ENV_FILE:?PROD_ENV_FILE must be provided by Makefile}"
: "${DEV_ENV_FILE:?DEV_ENV_FILE must be provided by Makefile}"

environment_database() {
  sed -n 's/^DB_NAME=//p' "$1" | sed -n '1p' | sed "s/^['\"]//; s/['\"]$//"
}

has_containers() {
  compose_command=$1
  # shellcheck disable=SC2086 # Compose command intentionally contains provider arguments.
  [ -n "$($compose_command ps -aq 2>/dev/null)" ]
}

show_environment() {
  label=$1
  project=$2
  env_file=$3
  files=$4
  compose_command=$5

  echo "Environment: $label"
  echo "Compose project: $project"
  echo "Compose files:"
  for file in $files; do
    echo "  $file"
  done
  echo "Database:"
  echo "  $(environment_database "$env_file")"
  echo "Volumes:"
  # shellcheck disable=SC2086 # Compose command intentionally contains provider arguments.
  $compose_command config --volumes | while IFS= read -r volume; do
    echo "  ${project}_${volume}"
  done
  echo "Images:"
  if has_containers "$compose_command"; then
    # `compose images` reports the exact image IDs used by existing containers.
    # shellcheck disable=SC2086 # Compose command intentionally contains provider arguments.
    $compose_command images backend frontend scheduler
  else
    echo "  containers have not been created"
  fi
}

prod_compose="env COMPOSE_ENV_FILE=$PROD_ENV_FILE $COMPOSE -p $PROD_PROJECT --env-file $PROD_ENV_FILE -f compose.yaml"
dev_compose="env COMPOSE_ENV_FILE=$DEV_ENV_FILE $COMPOSE -p $DEV_PROJECT --env-file $DEV_ENV_FILE -f compose.yaml -f compose.dev.yaml"
prod_active=0
dev_active=0
[ -f "$PROD_ENV_FILE" ] && has_containers "$prod_compose" && prod_active=1
[ -f "$DEV_ENV_FILE" ] && has_containers "$dev_compose" && dev_active=1

shown=0
if [ "$dev_active" -eq 1 ] || { [ "$prod_active" -eq 0 ] && [ -f "$DEV_ENV_FILE" ]; }; then
  show_environment development "$DEV_PROJECT" "$DEV_ENV_FILE" "compose.yaml compose.dev.yaml" "$dev_compose"
  shown=1
fi
if [ "$prod_active" -eq 1 ] || { [ "$dev_active" -eq 0 ] && [ -f "$PROD_ENV_FILE" ]; }; then
  [ "$shown" -eq 0 ] || echo
  show_environment production "$PROD_PROJECT" "$PROD_ENV_FILE" "compose.yaml" "$prod_compose"
  shown=1
fi
if [ "$shown" -eq 0 ]; then
  echo "Локальные окружения не настроены: отсутствуют .env.dev и .env.prod."
  echo "Выполните make init для development или скопируйте .env.example в .env.prod."
fi
