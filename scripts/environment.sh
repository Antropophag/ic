#!/bin/sh
set -eu

cd "$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)"

: "${COMPOSE:?COMPOSE must be provided by Makefile}"
: "${CONTAINER_ENGINE:?CONTAINER_ENGINE must be provided by Makefile}"

environment=${1:-}
action=${2:-}
case "$environment" in
dev)
  label=Development
  project=${DEV_PROJECT:?DEV_PROJECT must be provided by Makefile}
  env_file=${DEV_ENV_FILE:?DEV_ENV_FILE must be provided by Makefile}
  compose_files='-f compose.yaml -f compose.dev.yaml'
  ;;
prod)
  label=Production
  project=${PROD_PROJECT:?PROD_PROJECT must be provided by Makefile}
  env_file=${PROD_ENV_FILE:?PROD_ENV_FILE must be provided by Makefile}
  compose_files='-f compose.yaml'
  ;;
*)
  echo "Usage: $0 dev|prod up|down|restart|status|logs" >&2
  exit 2
  ;;
esac

[ -f "$env_file" ] || {
  if [ "$environment" = dev ]; then
    echo "Missing $env_file. Run make init." >&2
  else
    echo "Missing $env_file. Copy .env.example and configure production." >&2
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
      printf '  %sWarnings:%s\n' "$yellow" "$reset" >&2
      grep -Ei 'warning|warn:|deprecated' "$output_file" >&2
    fi
    : >"$output_file"
    return 0
  else
    status=$?
  fi
  printf '  %s✗%s %s failed\n\n' "$red" "$reset" "$description" >&2
  cat "$output_file" >&2
  return "$status"
}

container_state() {
  service=$1
  if container_output=$(compose ps -q "$service" 2>&1); then
    container_id=$(printf '%s\n' "$container_output" | sed -n '1p')
  else
    command_status=$?
    printf '%s\n' "$container_output" >&2
    return "$command_status"
  fi
  [ -n "$container_id" ] || {
    printf 'missing\n'
    return
  }
  if inspect_output=$("$CONTAINER_ENGINE" inspect --format \
    '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' \
    "$container_id" 2>&1); then
    printf '%s\n' "$inspect_output"
  else
    command_status=$?
    printf '%s\n' "$inspect_output" >&2
    return "$command_status"
  fi
}

wait_for_service() {
  service=$1
  expected=$2
  timeout=${SERVICE_READY_TIMEOUT:-90}
  elapsed=0
  while :; do
    state=$(container_state "$service")
    [ "$state" = "$expected" ] && return 0
    [ "$state" = unhealthy ] && break
    [ "$state" = exited ] && break
    [ "$state" = dead ] && break
    [ "$elapsed" -ge "$timeout" ] && break
    sleep 1
    elapsed=$((elapsed + 1))
  done
  printf '  %s✗%s %-14s %s (expected %s)\n' \
    "$red" "$reset" "$service" "$state" "$expected" >&2
  printf '\nLast logs for %s:\n' "$service" >&2
  compose logs --tail=50 "$service" >&2 || true
  printf '\nFull logs: make %s-logs SERVICE=%s\n' "$environment" "$service" >&2
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
  printf '\n  %-16s %shttp://localhost:%s%s\n' Frontend "$blue" "$frontend_port" "$reset"
  printf '  %-16s %shttp://localhost:%s/api/docs/%s\n' 'Swagger UI' "$blue" "$frontend_port" "$reset"
}

show_status() {
  status=0
  printf '  Services\n'
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
  run_quiet 'Validating configuration' compose config
  if [ "$environment" = dev ]; then
    run_quiet 'Building and starting services' compose up -d --build --force-recreate
    run_quiet 'Applying migrations' compose run --rm backend php yii migrate/up --interactive=0
    run_quiet 'Seeding development data' compose run --rm backend php yii dev/seed
    run_quiet 'Provisioning break-glass access' compose run --rm backend php yii admin/provision-break-glass
  else
    run_quiet 'Building images' compose build backend scheduler frontend
    run_quiet 'Starting database and backend' compose up -d --no-build --force-recreate mariadb backend
    run_quiet 'Applying migrations' compose run --rm backend php yii migrate/up --interactive=0
    run_quiet 'Provisioning break-glass access' compose run --rm backend php yii admin/provision-break-glass
    run_quiet 'Provisioning administrators' compose run --rm backend php yii admin/bootstrap
    run_quiet 'Starting frontend and scheduler' compose up -d --no-build --force-recreate frontend scheduler
  fi
  printf '\n  Checking readiness…\n'
  wait_for_service mariadb healthy
  wait_for_service backend running
  wait_for_service frontend healthy
  wait_for_service scheduler running
  show_status
  printf '\n  %sLogs%s             make %s-logs\n' "$dim" "$reset" "$environment"
  printf '  %sStop%s             make %s-down\n' "$dim" "$reset" "$environment"
}

stop_environment() {
  run_quiet 'Stopping services' compose down --remove-orphans
  printf '\n  %s✓%s Environment stopped\n' "$green" "$reset"
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
    echo "Unknown service: $service" >&2
    exit 2
    ;;
  esac
  case "$log_tail" in
  all | *[!0-9]* | '')
    [ "$log_tail" = all ] || {
      echo "LOG_TAIL must be a non-negative integer or 'all'." >&2
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
  echo "Usage: $0 dev|prod up|down|restart|status|logs" >&2
  exit 2
  ;;
esac
