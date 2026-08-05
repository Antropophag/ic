#!/bin/sh
set -eu

cd "$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)"
: "${COMPOSE:?COMPOSE должен быть передан из Makefile}"
: "${CONTAINER_ENGINE:?CONTAINER_ENGINE должен быть передан из Makefile}"
: "${PROD_PROJECT:?PROD_PROJECT должен быть передан из Makefile}"
: "${DEV_PROJECT:?DEV_PROJECT должен быть передан из Makefile}"
: "${PROD_ENV_FILE:?PROD_ENV_FILE должен быть передан из Makefile}"
: "${DEV_ENV_FILE:?DEV_ENV_FILE должен быть передан из Makefile}"

environment_database() {
  sed -n 's/^DB_NAME=//p' "$1" | sed -n '1p' | sed "s/^['\"]//; s/['\"]$//"
}

has_containers() {
  compose_command=$1
  project=$2
  # shellcheck disable=SC2086 # Compose command intentionally contains provider arguments.
  if [ "$CONTAINER_ENGINE" = podman ]; then
    if container_ids=$(
      "$CONTAINER_ENGINE" ps --filter status=running \
        --filter "label=com.docker.compose.project=$project" -q 2>&1
    ); then
      :
    else
      printf '%s\n' "$container_ids" >&2
      return 2
    fi
  elif container_ids=$($compose_command ps --status running -q 2>&1); then
    :
  else
    printf '%s\n' "$container_ids" >&2
    return 2
  fi
  if [ -n "$container_ids" ] && printf '%s\n' "$container_ids" | grep -Eqv '^[[:xdigit:]]{12,64}$'; then
    printf '%s\n' "$container_ids" >&2
    return 2
  fi
  [ -n "$container_ids" ]
}

show_environment() {
  label=$1
  project=$2
  env_file=$3
  files=$4
  compose_command=$5

  echo "Окружение: $label"
  echo "Проект Compose: $project"
  echo "Compose-файлы:"
  for file in $files; do
    echo "  $file"
  done
  echo "База данных:"
  echo "  $(environment_database "$env_file")"
  echo "Тома данных:"
  # shellcheck disable=SC2086 # Compose command intentionally contains provider arguments.
  if volumes=$($compose_command config --volumes 2>&1); then
    :
  else
    printf '%s\n' "$volumes" >&2
    return 2
  fi
  printf '%s\n' "$volumes" | while IFS= read -r volume; do
    echo "  ${project}_${volume}"
  done
  echo "Образы:"
  if has_containers "$compose_command" "$project"; then
    # `compose images` reports the exact image IDs used by existing containers.
    # shellcheck disable=SC2086 # Compose command intentionally contains provider arguments.
    if images=$($compose_command images backend frontend scheduler 2>&1); then
      printf '%s\n' "$images"
    else
      printf '%s\n' "$images" >&2
      return 2
    fi
  else
    container_status=$?
    [ "$container_status" -eq 1 ] || return "$container_status"
    echo "  запущенные контейнеры отсутствуют"
  fi
}

prod_compose="env COMPOSE_ENV_FILE=$PROD_ENV_FILE $COMPOSE -p $PROD_PROJECT --env-file $PROD_ENV_FILE -f compose.yaml"
dev_compose="env COMPOSE_ENV_FILE=$DEV_ENV_FILE $COMPOSE -p $DEV_PROJECT --env-file $DEV_ENV_FILE -f compose.yaml -f compose.dev.yaml"
prod_active=0
dev_active=0
if [ -f "$PROD_ENV_FILE" ]; then
  if has_containers "$prod_compose" "$PROD_PROJECT"; then
    prod_active=1
  else
    container_status=$?
    [ "$container_status" -eq 1 ] || exit "$container_status"
  fi
fi
if [ -f "$DEV_ENV_FILE" ]; then
  if has_containers "$dev_compose" "$DEV_PROJECT"; then
    dev_active=1
  else
    container_status=$?
    [ "$container_status" -eq 1 ] || exit "$container_status"
  fi
fi

shown=0
if [ "$dev_active" -eq 1 ] || { [ "$prod_active" -eq 0 ] && [ -f "$DEV_ENV_FILE" ]; }; then
  show_environment "разработка" "$DEV_PROJECT" "$DEV_ENV_FILE" \
    "compose.yaml compose.dev.yaml" "$dev_compose" || exit $?
  shown=1
fi
if [ "$prod_active" -eq 1 ] || { [ "$dev_active" -eq 0 ] && [ -f "$PROD_ENV_FILE" ]; }; then
  [ "$shown" -eq 0 ] || echo
  show_environment "промышленная эксплуатация" "$PROD_PROJECT" \
    "$PROD_ENV_FILE" "compose.yaml" "$prod_compose" || exit $?
  shown=1
fi
if [ "$shown" -eq 0 ]; then
  echo "Локальные окружения не настроены: отсутствуют .env.dev и .env.prod."
  echo "Выполните make init для development или скопируйте .env.example в .env.prod."
fi
