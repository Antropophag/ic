#!/bin/sh
set -eu

cd "$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)"

: "${COMPOSE:?COMPOSE должен быть передан из Makefile}"
: "${CONTAINER_ENGINE:?CONTAINER_ENGINE должен быть передан из Makefile}"

environment=${1:-}
scope=${2:-}
action=${3:-}
case "$environment" in
dev)
  label=Разработка
  project=${DEV_PROJECT:?DEV_PROJECT должен быть передан из Makefile}
  env_file=${DEV_ENV_FILE:?DEV_ENV_FILE должен быть передан из Makefile}
  app_compose_files='-f compose.yaml -f compose.dev.yaml'
  ;;
prod)
  label='Промышленная эксплуатация'
  project=${PROD_PROJECT:?PROD_PROJECT должен быть передан из Makefile}
  env_file=${PROD_ENV_FILE:?PROD_ENV_FILE должен быть передан из Makefile}
  app_compose_files='-f compose.yaml'
  ;;
*)
  echo "Использование: $0 dev|prod app|obs|stack <action>" >&2
  exit 2
  ;;
esac

application_services='mariadb backend frontend scheduler'
application_stop_services='frontend scheduler backend mariadb'
observability_services='grafana prometheus loki alloy node-exporter cadvisor blackbox-exporter'
case "$scope" in
app)
  compose_files=$app_compose_files
  managed_services=$application_services
  export COMPOSE_IGNORE_ORPHANS=1
  ;;
obs | stack)
  compose_files="$app_compose_files -f compose.observability.yml"
  managed_services=$observability_services
  ;;
*)
  echo "Использование: $0 dev|prod app|obs|stack <action>" >&2
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
if [ "$environment" = dev ] && [ "$scope" != app ] &&
  [ -z "${GRAFANA_ADMIN_PASSWORD:-}" ]; then
  unset GRAFANA_ADMIN_PASSWORD
  if ! grep -Eq '^GRAFANA_ADMIN_PASSWORD=.+$' "$env_file"; then
    export GRAFANA_ADMIN_PASSWORD=admin
  fi
fi
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
  case " $observability_services " in
  *" $service "*)
    printf '\nПолные логи: make %s-obs-logs SERVICE=%s\n' "$environment" "$service" >&2
    ;;
  *)
    printf '\nПолные логи: make %s-logs SERVICE=%s\n' "$environment" "$service" >&2
    ;;
  esac
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
    [ "$expected" = ready ] && { [ "$state" = healthy ] || [ "$state" = running ]; } && return 0
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

show_application_urls() {
  frontend_binding=$(compose port frontend 8080 2>/dev/null | sed -n '1p') || return 0
  frontend_port=${frontend_binding##*:}
  case "$frontend_port" in '' | *[!0-9]*) return 0 ;; esac
  printf '\n  Портал           %shttp://localhost:%s%s\n' "$blue" "$frontend_port" "$reset"
  printf '  Swagger UI       %shttp://localhost:%s/api/docs/%s\n' "$blue" "$frontend_port" "$reset"
}

show_application_status() {
  status=0
  printf '  Сервисы\n'
  for service in $application_services; do
    if show_service "$service"; then
      :
    else
      service_status=$?
      [ "$status" -ne 0 ] || status=$service_status
    fi
  done
  show_application_urls
  return "$status"
}

show_observability_urls() {
  grafana_binding=$(compose port grafana 3000 2>/dev/null | sed -n '1p') || return 0
  grafana_port=${grafana_binding##*:}
  case "$grafana_port" in '' | *[!0-9]*) return 0 ;; esac
  printf '\n  Grafana          %shttp://localhost:%s%s\n' "$blue" "$grafana_port" "$reset"
}

show_observability_status() {
  status=0
  printf '  Сервисы observability\n'
  for service in $observability_services; do
    if show_service "$service"; then
      :
    else
      service_status=$?
      [ "$status" -ne 0 ] || status=$service_status
    fi
  done
  show_observability_urls
  return "$status"
}

start_application() {
  run_quiet 'Проверка конфигурации' compose config --quiet
  if [ "$environment" = dev ]; then
    run_quiet 'Сборка образов' compose build backend scheduler frontend
    run_quiet 'Остановка прикладных сервисов перед миграцией' compose stop frontend scheduler backend
    run_quiet 'Запуск базы данных' compose up -d mariadb
    run_quiet 'Применение миграций' compose run --rm backend php yii migrate/up --interactive=0
    run_quiet 'Загрузка development-данных' compose run --rm backend php yii dev/seed
    run_quiet 'Настройка break-glass доступа' compose run --rm backend php yii admin/provision-break-glass
    run_quiet 'Запуск прикладных сервисов' compose up -d --no-build --force-recreate backend frontend scheduler
  else
    run_quiet 'Сборка образов' compose build backend scheduler frontend
    run_quiet 'Остановка прикладных сервисов перед миграцией' compose stop frontend scheduler backend
    run_quiet 'Запуск базы данных' compose up -d mariadb
    run_quiet 'Применение миграций' compose run --rm backend php yii migrate/up --interactive=0
    run_quiet 'Настройка break-glass доступа' compose run --rm backend php yii admin/provision-break-glass
    run_quiet 'Настройка администраторов' compose run --rm backend php yii admin/bootstrap
    run_quiet 'Запуск прикладных сервисов' compose up -d --no-build --force-recreate backend frontend scheduler
  fi
  printf '\n  Проверка готовности…\n'
  wait_for_service mariadb healthy
  wait_for_service backend running
  wait_for_service frontend healthy
  wait_for_service scheduler running
  show_application_status
  printf '\n  %sЛоги%s             make %s-logs\n' "$dim" "$reset" "$environment"
  printf '  %sОстановка%s        make %s-down\n' "$dim" "$reset" "$environment"
}

stop_services() {
  description=$1
  shift
  run_quiet "Остановка $description" compose stop "$@"
  run_quiet "Удаление containers: $description" compose rm -f "$@"
}

stop_application() {
  # Address app services explicitly so a separately managed obs overlay survives.
  # shellcheck disable=SC2086 # The service list intentionally expands to arguments.
  stop_services 'прикладных сервисов' $application_stop_services
  printf '\n  %s✓%s Приложение остановлено, данные сохранены\n' "$green" "$reset"
}

remove_application_volumes() {
  for volume in "${project}_mariadb-data" "${project}_document-data"; do
    if "$CONTAINER_ENGINE" volume inspect "$volume" >/dev/null 2>&1; then
      run_quiet "Удаление volume $volume" "$CONTAINER_ENGINE" volume rm "$volume"
    fi
  done
}

start_observability() {
  run_quiet 'Проверка конфигурации observability' compose config --quiet
  # shellcheck disable=SC2086 # The service list intentionally expands to arguments.
  run_quiet 'Запуск observability-сервисов' compose up -d $observability_services
  printf '\n  Проверка готовности observability…\n'
  wait_for_service prometheus healthy
  wait_for_service loki healthy
  wait_for_service grafana healthy
  wait_for_service alloy ready
  wait_for_service node-exporter ready
  wait_for_service cadvisor ready
  wait_for_service blackbox-exporter ready
  show_observability_status
  printf '\n  %sЛоги%s             make %s-obs-logs\n' "$dim" "$reset" "$environment"
  printf '  %sОстановка%s        make %s-obs-down\n' "$dim" "$reset" "$environment"
}

stop_observability() {
  # shellcheck disable=SC2086 # The service list intentionally expands to arguments.
  stop_services 'observability-сервисов' $observability_services
  printf '\n  %s✓%s Observability остановлен, данные сохранены\n' "$green" "$reset"
}

stop_stack() {
  run_quiet 'Остановка полного стека' compose down
  printf '\n  %s✓%s Полный стек остановлен, данные сохранены\n' "$green" "$reset"
}

run_logs() {
  service=${SERVICE:-}
  log_tail=${LOG_TAIL:-100}
  case "$service" in
  '') ;;
  *)
    case " $managed_services " in
    *" $service "*) ;;
    *)
      echo "Неизвестный сервис: $service" >&2
      exit 2
      ;;
    esac
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
    # shellcheck disable=SC2086 # The service list intentionally expands to arguments.
    compose logs --follow --tail="$log_tail" $managed_services
  fi
}

case "$scope:$action" in
app:up)
  heading
  start_application
  ;;
app:down)
  heading
  stop_application
  ;;
app:restart)
  heading
  stop_application
  printf '\n'
  start_application
  ;;
app:reset)
  heading
  stop_application
  remove_application_volumes
  printf '\n'
  start_application
  ;;
app:status)
  heading
  show_application_status
  ;;
app:logs | obs:logs)
  run_logs
  ;;
obs:up)
  heading
  start_observability
  ;;
obs:down)
  heading
  stop_observability
  ;;
obs:restart)
  heading
  stop_observability
  printf '\n'
  start_observability
  ;;
obs:status)
  heading
  show_observability_status
  ;;
stack:up)
  heading
  start_application
  printf '\n'
  start_observability
  ;;
stack:down)
  heading
  stop_stack
  ;;
*)
  echo "Недопустимое действие '$action' для '$scope'." >&2
  exit 2
  ;;
esac
