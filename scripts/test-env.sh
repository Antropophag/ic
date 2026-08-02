#!/bin/sh
set -eu
cd "$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)"
: "${COMPOSE:?COMPOSE must be provided by Makefile}"
compose="$COMPOSE --env-file .env.test -f compose.test.yaml"
action=${1:-}

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

case "$action" in
up)
  $compose config >/dev/null
  $compose up -d --build
  assert_health "${TEST_BASE_URL:-http://localhost:18080}/health/live" ok
  wait_url "${MAILPIT_BASE_URL:-http://localhost:18025}/api/v1/info"
  ;;
reset)
  $compose run --rm backend php yii test/reset
  assert_health "${TEST_BASE_URL:-http://localhost:18080}/health/ready" ready
  ;;
down) $compose down --remove-orphans ;;
destroy) $compose down --volumes --remove-orphans ;;
logs)
  if [ "$#" -gt 1 ]; then
    $compose logs --no-color --tail=200 "$2"
  else
    $compose logs --no-color --tail=200
  fi
  ;;
*)
  echo "Usage: $0 up|reset|down|destroy|logs [service]" >&2
  exit 2
  ;;
esac
