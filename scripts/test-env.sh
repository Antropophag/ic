#!/bin/sh
set -eu
cd "$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)"
compose='docker compose -f compose.test.yaml'
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

case "$action" in
up)
  $compose up -d --build
  wait_url "${TEST_BASE_URL:-http://localhost:18080}/health/ready"
  wait_url "${MAILPIT_BASE_URL:-http://localhost:18025}/api/v1/info"
  ;;
reset)
  $compose run --rm backend php yii test/reset
  ;;
down) $compose down --remove-orphans ;;
destroy) $compose down --volumes --remove-orphans ;;
logs) $compose logs --no-color --tail=200 "${2:-}" ;;
*)
  echo "Usage: $0 up|reset|down|destroy|logs [service]" >&2
  exit 2
  ;;
esac
