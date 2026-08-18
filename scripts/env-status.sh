#!/bin/sh
set -eu

cd "$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)"
: "${COMPOSE:?COMPOSE должен быть передан из Makefile}"
: "${CONTAINER_ENGINE:?CONTAINER_ENGINE должен быть передан из Makefile}"
: "${PROD_PROJECT:?PROD_PROJECT должен быть передан из Makefile}"
: "${DEV_PROJECT:?DEV_PROJECT должен быть передан из Makefile}"
: "${PROD_ENV_FILE:?PROD_ENV_FILE должен быть передан из Makefile}"
: "${DEV_ENV_FILE:?DEV_ENV_FILE должен быть передан из Makefile}"

# shellcheck source=scripts/compose-metadata.sh
. scripts/compose-metadata.sh

environment_database() {
  sed -n 's/^DB_NAME=//p' "$1" | sed -n '1p' | sed "s/^['\"]//; s/['\"]$//"
}

has_containers() {
  _compose_command=$1
  project=$2
  if container_ids=$(
    "$CONTAINER_ENGINE" ps --filter status=running \
      --filter "label=com.docker.compose.project=$project" -q 2>&1
  ); then
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

show_runtime_project() {
  label=$1
  project=$2

  echo "Окружение: $label"
  echo "Проект Compose: $project"
  echo "Состояние: запущено"
  echo "Конфигурация: env-файл отсутствует; показаны runtime metadata"
  echo "Frontend URL:"
  if frontend_id=$(
    "$CONTAINER_ENGINE" ps -q \
      --filter "label=com.docker.compose.project=$project" \
      --filter "label=com.docker.compose.service=frontend" | sed -n '1p'
  ) && [ -n "$frontend_id" ]; then
    if binding=$("$CONTAINER_ENGINE" port "$frontend_id" 8080/tcp 2>/dev/null | sed -n '1p') &&
      published_port=${binding##*:} &&
      printf '%s' "$published_port" | grep -Eq '^[0-9]+$'; then
      echo "  http://${COMPOSE_PUBLISHED_HOST:-localhost}:$published_port"
    else
      echo "  не опубликован"
    fi
  else
    echo "  не опубликован (frontend не запущен)"
  fi
  echo "Контейнеры и health:"
  "$CONTAINER_ENGINE" ps --filter "label=com.docker.compose.project=$project"
  echo "Тома данных:"
  "$CONTAINER_ENGINE" volume ls \
    --filter "label=com.docker.compose.project=$project" --format '  {{.Name}}'
  echo "Сети:"
  "$CONTAINER_ENGINE" network ls \
    --filter "label=com.docker.compose.project=$project" --format '  {{.Name}}'
}

show_environment() {
  label=$1
  project=$2
  env_file=$3
  files=$4
  compose_command=$5
  active=$6

  echo "Окружение: $label"
  echo "Проект Compose: $project"
  if [ "$active" -eq 1 ]; then
    echo "Состояние: запущено"
  else
    echo "Состояние: остановлено"
  fi
  echo "Compose-файлы:"
  for file in $files; do
    echo "  $file"
  done
  echo "База данных:"
  echo "  $(environment_database "$env_file")"
  echo "Frontend URL:"
  if [ "$active" -eq 1 ]; then
    compose=$compose_command
    if frontend_url=$(compose_http_url frontend 8080 2>/dev/null); then
      echo "  $frontend_url"
    else
      echo "  не опубликован (frontend не запущен)"
    fi
  else
    echo "  не опубликован"
  fi
  echo "Контейнеры и health:"
  if [ "$active" -eq 1 ]; then
    # `compose ps` includes the provider's health and published-port columns.
    # shellcheck disable=SC2086 # Compose command intentionally contains provider arguments.
    if containers=$($compose_command ps 2>&1); then
      printf '%s\n' "$containers"
    else
      printf '%s\n' "$containers" >&2
      return 2
    fi
  else
    echo "  запущенные контейнеры отсутствуют"
  fi
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
  echo "Сети:"
  # shellcheck disable=SC2086 # Compose command intentionally contains provider arguments.
  if networks=$($compose_command config --networks 2>&1); then
    :
  else
    printf '%s\n' "$networks" >&2
    return 2
  fi
  printf '%s\n' "$networks" | while IFS= read -r network; do
    echo "  ${project}_${network}"
  done
  echo "Образы:"
  if has_containers "$compose_command" "$project"; then
    # `compose images` reports the exact image IDs used by existing containers.
    # shellcheck disable=SC2086 # Compose command intentionally contains provider arguments.
    if images=$($compose_command images backend frontend scheduler ai-cleanup 2>&1); then
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
if has_containers "$prod_compose" "$PROD_PROJECT"; then
  prod_active=1
else
  container_status=$?
  [ "$container_status" -eq 1 ] || exit "$container_status"
fi
if has_containers "$dev_compose" "$DEV_PROJECT"; then
  dev_active=1
else
  container_status=$?
  [ "$container_status" -eq 1 ] || exit "$container_status"
fi

shown=0
if [ -f "$DEV_ENV_FILE" ]; then
  show_environment "разработка" "$DEV_PROJECT" "$DEV_ENV_FILE" \
    "compose.yaml compose.dev.yaml" "$dev_compose" "$dev_active" || exit $?
  shown=1
elif [ "$dev_active" -eq 1 ]; then
  show_runtime_project "разработка" "$DEV_PROJECT"
  shown=1
fi
if [ -f "$PROD_ENV_FILE" ]; then
  [ "$shown" -eq 0 ] || echo
  show_environment "промышленная эксплуатация" "$PROD_PROJECT" \
    "$PROD_ENV_FILE" "compose.yaml" "$prod_compose" "$prod_active" || exit $?
  shown=1
elif [ "$prod_active" -eq 1 ]; then
  [ "$shown" -eq 0 ] || echo
  show_runtime_project "промышленная эксплуатация" "$PROD_PROJECT"
  shown=1
fi
if [ "$shown" -eq 0 ]; then
  echo "Локальные окружения не настроены: отсутствуют .env.dev и .env.prod."
  echo "Выполните make init для development или скопируйте .env.example в .env.prod."
fi
