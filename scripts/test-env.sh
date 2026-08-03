#!/bin/sh
set -eu
cd "$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)"
: "${COMPOSE:?COMPOSE must be provided by Makefile}"
: "${CONTAINER_ENGINE:?CONTAINER_ENGINE must be provided by Makefile}"
compose="$COMPOSE --env-file .env.test -f compose.test.yaml"
action=${1:-}
test_base=${TEST_BASE_URL:-http://localhost:${TEST_FRONTEND_PORT:-18080}}
mailpit_base=${MAILPIT_BASE_URL:-http://localhost:${TEST_MAILPIT_PORT:-18025}}

wait_url() {
  url=$1
  attempts=0
  until curl -fsS "$url" >/dev/null 2>&1; do
    attempts=$((attempts + 1))
    [ "$attempts" -lt 60 ] || {
      echo "Timeout waiting for $url" >&2
      return 1
    }
    sleep 1
  done
}

assert_health() {
  url=$1
  expected=$2
  wait_url "$url"
  curl -fsS "$url" | grep -q "\"status\":\"$expected\""
}

service_running() {
  $compose ps "$1" 2>/dev/null | grep -Eiq 'running|Up'
}

require_image() {
  $CONTAINER_ENGINE image inspect "$1" >/dev/null 2>&1 || {
    echo "Test image $1 is missing; run the test build stage first." >&2
    return 1
  }
}

build_images() {
  if [ "$CONTAINER_ENGINE" = docker ]; then
    DOCKER_BUILDKIT=1 $compose build "$@"
  else
    $compose build "$@"
  fi
}

case "$action" in
build)
  $compose config >/dev/null
  if $CONTAINER_ENGINE image inspect shlz-test-registry-test-ad >/dev/null 2>&1; then
    build_images backend frontend
  else
    build_images backend frontend ad
  fi
  ;;
up)
  $compose config >/dev/null
  require_image shlz-test-registry-test-backend
  require_image shlz-test-registry-test-frontend
  require_image shlz-test-registry-test-ad
  $compose stop frontend scheduler >/dev/null 2>&1 || true
  $compose up -d --no-build mariadb
  $compose up -d --no-build ad
  $compose up -d --no-build mailpit
  wait_url "$mailpit_base/api/v1/info"
  $compose up -d --no-build --force-recreate backend
  "$0" reset
  $compose up -d --no-build --force-recreate frontend scheduler
  assert_health "$test_base/health/live" ok
  assert_health "$test_base/health/ready" ready
  ;;
reset)
  service_running backend || {
    echo "Test deployment is not running; run the build and start stages first." >&2
    exit 2
  }
  restart_frontend=0
  restart_scheduler=0
  if service_running scheduler; then
    restart_scheduler=1
    $compose stop scheduler
  fi
  if service_running frontend; then
    restart_frontend=1
    $compose stop frontend
  fi
  set +e
  $compose run --rm backend php yii test/reset
  reset_status=$?
  set -e
  if [ "$reset_status" -ne 0 ]; then
    if [ "$restart_frontend" -eq 1 ]; then
      $compose up -d --no-build --force-recreate frontend || true
    fi
    if [ "$restart_scheduler" -eq 1 ]; then
      $compose up -d --no-build --force-recreate scheduler || true
    fi
    exit "$reset_status"
  fi
  if [ "$restart_frontend" -eq 1 ]; then
    $compose up -d --no-build --force-recreate frontend
  fi
  if [ "$restart_scheduler" -eq 1 ]; then
    $compose up -d --no-build --force-recreate scheduler
  fi
  if [ "$restart_frontend" -eq 1 ]; then
    assert_health "$test_base/health/live" ok
    assert_health "$test_base/health/ready" ready
  fi
  ;;
down) $compose down --remove-orphans ;;
destroy) $compose down --volumes --remove-orphans ;;
logs)
  if [ "$#" -gt 1 ]; then
    $compose logs --tail=200 "$2"
  else
    $compose logs --tail=200
  fi
  ;;
*)
  echo "Usage: $0 build|up|reset|down|destroy|logs [service]" >&2
  exit 2
  ;;
esac
