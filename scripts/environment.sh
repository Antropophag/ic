#!/bin/sh
set -eu

cd "$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)"

: "${COMPOSE:?COMPOSE должен быть передан из Makefile}"
: "${CONTAINER_ENGINE:?CONTAINER_ENGINE должен быть передан из Makefile}"

environment=${1:-}
action=${2:-}
case "$environment" in
dev)
  label=Разработка
  project=${DEV_PROJECT:?DEV_PROJECT должен быть передан из Makefile}
  env_file=${DEV_ENV_FILE:?DEV_ENV_FILE должен быть передан из Makefile}
  compose_files='-f compose.yaml -f compose.dev.yaml'
  ;;
prod)
  label='Промышленная эксплуатация'
  project=${PROD_PROJECT:?PROD_PROJECT должен быть передан из Makefile}
  env_file=${PROD_ENV_FILE:?PROD_ENV_FILE должен быть передан из Makefile}
  compose_files='-f compose.yaml'
  ;;
*)
  echo "Использование: $0 dev|prod up|down|restart|status|logs" >&2
  exit 2
  ;;
esac

[ -f "$env_file" ] || {
  if [ "$environment" = dev ]; then
    echo "Отсутствует $env_file. Выполните make init." >&2
  else
    echo "Отсутствует $env_file. Скопируйте .env.example и настройте production." >&2
  fi
  exit 2
}

export COMPOSE_ENV_FILE="$env_file"
compose() {
  # shellcheck disable=SC2086 # Provider and Compose files intentionally contain arguments.
  $COMPOSE -p "$project" --env-file "$env_file" $compose_files "$@"
}

if { [ -t 1 ] && [ "${TERM:-}" != dumb ] && [ -z "${NO_COLOR:-}" ] && [ -z "${CI:-}" ]; } ||
  [ "${FORCE_COLOR:-0}" = 1 ]; then
  blue=$(printf '\033[36m')
  green=$(printf '\033[32m')
  yellow=$(printf '\033[33m')
  red=$(printf '\033[31m')
  dim=$(printf '\033[2m')
  reset=$(printf '\033[0m')
else
  blue=
  green=
  yellow=
  red=
  dim=
  reset=
fi

output_file=$(mktemp)
cleanup() {
  rm -f "$output_file"
}
trap cleanup EXIT HUP INT TERM

heading() {
  printf '%sIC · %s%s\n\n' "$blue" "$label" "$reset"
}

run_quiet() {
  description=$1
  shift
  printf '  %s…%s %s\n' "$yellow" "$reset" "$description"
  if "$@" >"$output_file" 2>&1; then
    if grep -Eiq 'warning|warn:|deprecated' "$output_file"; then
      printf '  %sПредупреждения:%s\n' "$yellow" "$reset" >&2
      grep -Ei 'warning|warn:|deprecated' "$output_file" >&2
    fi
    : >"$output_file"
    return 0
  else
    status=$?
  fi
  printf '  %s✗%s Ошибка: %s\n\n' "$red" "$reset" "$description" >&2
  cat "$output_file" >&2
  return "$status"
}

container_state() {
  service=$1
  : >"$output_file"
  if container_ids=$(compose ps -q "$service" 2>"$output_file"); then
    if grep -Eiq 'warning|warn:|deprecated' "$output_file"; then
      grep -Ei 'warning|warn:|deprecated' "$output_file" >&2
    fi
    : >"$output_file"
  else
    command_status=$?
    cat "$output_file" >&2
    : >"$output_file"
    return "$command_status"
  fi
  [ -n "$container_ids" ] || {
    printf 'missing\n'
    return
  }
  ready_state=
  pending_state=
  failure_state=
  for container_id in $container_ids; do
    if inspect_output=$("$CONTAINER_ENGINE" inspect --format \
      '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' \
      "$container_id" 2>&1); then
      state=$inspect_output
    else
      command_status=$?
      printf '%s\n' "$inspect_output" >&2
      return "$command_status"
    fi
    case "$state" in
    dead)
      failure_state=dead
      ;;
    unhealthy)
      [ "$failure_state" = dead ] || failure_state=unhealthy
      ;;
    exited)
      [ -n "$failure_state" ] || failure_state=exited
      ;;
    restarting)
      pending_state=restarting
      ;;
    starting)
      [ "$pending_state" = restarting ] || pending_state=starting
      ;;
    created)
      [ -n "$pending_state" ] || pending_state=created
      ;;
    healthy | running)
      if [ -z "$ready_state" ]; then
        ready_state=$state
      elif [ "$ready_state" != "$state" ]; then
        ready_state=mixed
      fi
      ;;
    *)
      printf '%s\n' "$state"
      return
      ;;
    esac
  done
  if [ -n "$failure_state" ]; then
    printf '%s\n' "$failure_state"
  elif [ -n "$pending_state" ]; then
    printf '%s\n' "$pending_state"
  else
    printf '%s\n' "${ready_state:-missing}"
  fi
}

show_failure_logs() {
  service=$1
  printf '\nПоследние 50 строк логов %s:\n' "$service" >&2
  compose logs --tail=50 "$service" >&2 || true
  printf '\nПолные логи: make %s-logs SERVICE=%s\n' "$environment" "$service" >&2
}

wait_for_service() {
  service=$1
  expected=$2
  timeout=${SERVICE_READY_TIMEOUT:-90}
  case "$timeout" in
  '' | *[!0-9]*)
    echo "SERVICE_READY_TIMEOUT должен быть неотрицательным целым числом." >&2
    return 2
    ;;
  esac
  elapsed=0
  while :; do
    if state=$(container_state "$service"); then
      :
    else
      state_status=$?
      printf '  %s✗%s %-14s ошибка проверки состояния\n' \
        "$red" "$reset" "$service" >&2
      show_failure_logs "$service"
      return "$state_status"
    fi
    [ "$state" = "$expected" ] && return 0
    [ "$state" = unhealthy ] && break
    [ "$state" = exited ] && break
    [ "$state" = dead ] && break
    [ "$elapsed" -ge "$timeout" ] && break
    sleep 1
    elapsed=$((elapsed + 1))
  done
  printf '  %s✗%s %-14s %s (ожидалось: %s)\n' \
    "$red" "$reset" "$service" "$state" "$expected" >&2
  show_failure_logs "$service"
  return 1
}

show_service() {
  service=$1
  if state=$(container_state "$service"); then
    :
  else
    return $?
  fi
  case "$state" in
  healthy | running)
    printf '  %s✓%s %-14s %s\n' "$green" "$reset" "$service" "$state"
    ;;
  starting | created | restarting)
    printf '  %s…%s %-14s %s\n' "$yellow" "$reset" "$service" "$state"
    ;;
  *)
    printf '  %s✗%s %-14s %s\n' "$red" "$reset" "$service" "$state"
    return 1
    ;;
  esac
}

show_urls() {
  frontend_binding=$(compose port frontend 8080 2>/dev/null | sed -n '1p') || return 0
  frontend_port=${frontend_binding##*:}
  case "$frontend_port" in '' | *[!0-9]*) return 0 ;; esac
  printf '\n  Портал           %shttp://localhost:%s%s\n' "$blue" "$frontend_port" "$reset"
  printf '  Swagger UI       %shttp://localhost:%s/api/docs/%s\n' "$blue" "$frontend_port" "$reset"
}

show_status() {
  status=0
  printf '  Сервисы\n'
  for service in mariadb backend frontend scheduler; do
    if show_service "$service"; then
      :
    else
      service_status=$?
      [ "$status" -ne 0 ] || status=$service_status
    fi
  done
  show_urls
  return "$status"
}

start_environment() {
  run_quiet 'Проверка конфигурации' compose config --quiet
  if [ "$environment" = dev ]; then
    run_quiet 'Сборка и запуск сервисов' compose up -d --build --force-recreate
    run_quiet 'Применение миграций' compose run --rm backend php yii migrate/up --interactive=0
    run_quiet 'Загрузка development-данных' compose run --rm backend php yii dev/seed
    run_quiet 'Настройка break-glass доступа' compose run --rm backend php yii admin/provision-break-glass
  else
    run_quiet 'Сборка образов' compose build backend scheduler frontend
    run_quiet 'Запуск базы данных и backend' compose up -d --no-build --force-recreate mariadb backend
    run_quiet 'Применение миграций' compose run --rm backend php yii migrate/up --interactive=0
    run_quiet 'Настройка break-glass доступа' compose run --rm backend php yii admin/provision-break-glass
    run_quiet 'Настройка администраторов' compose run --rm backend php yii admin/bootstrap
    run_quiet 'Запуск frontend и scheduler' compose up -d --no-build --force-recreate frontend scheduler
  fi
  printf '\n  Проверка готовности…\n'
  wait_for_service mariadb healthy
  wait_for_service backend running
  wait_for_service frontend healthy
  wait_for_service scheduler running
  show_status
  printf '\n  %sЛоги%s             make %s-logs\n' "$dim" "$reset" "$environment"
  printf '  %sОстановка%s        make %s-down\n' "$dim" "$reset" "$environment"
}

stop_environment() {
  run_quiet 'Остановка сервисов' compose down --remove-orphans
  printf '\n  %s✓%s Окружение остановлено\n' "$green" "$reset"
}

case "$action" in
up)
  heading
  start_environment
  ;;
down)
  heading
  stop_environment
  ;;
restart)
  heading
  stop_environment
  printf '\n'
  start_environment
  ;;
status)
  heading
  show_status
  ;;
logs)
  service=${SERVICE:-}
  log_tail=${LOG_TAIL:-100}
  case "$service" in
  '' | frontend | backend | scheduler | mariadb) ;;
  *)
    echo "Неизвестный сервис: $service" >&2
    exit 2
    ;;
  esac
  case "$log_tail" in
  all | *[!0-9]* | '')
    [ "$log_tail" = all ] || {
      echo "LOG_TAIL должен быть неотрицательным целым числом или 'all'." >&2
      exit 2
    }
    ;;
  esac
  if [ -n "$service" ]; then
    compose logs --follow --tail="$log_tail" "$service"
  else
    compose logs --follow --tail="$log_tail"
  fi
  ;;
*)
  echo "Использование: $0 dev|prod up|down|restart|status|logs" >&2
  exit 2
  ;;
esac
