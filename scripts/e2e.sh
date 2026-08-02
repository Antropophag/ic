#!/bin/sh
set -eu
project_root=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
cd "$project_root"
: "${COMPOSE:?COMPOSE must be provided by Makefile}"
cleanup() {
  status=$?
  cd "$project_root"
  if [ "$status" -ne 0 ]; then
    echo "Test deployment failed; container status and recent logs follow." >&2
    $COMPOSE --env-file .env.test -f compose.test.yaml ps >&2 || true
    $COMPOSE --env-file .env.test -f compose.test.yaml logs --no-color --tail=200 >&2 || true
  fi
  sh scripts/test-env.sh down || true
  return "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
sh scripts/test-env.sh up
sh scripts/test-env.sh reset
$COMPOSE --env-file .env.test -f compose.test.yaml exec -T backend \
  vendor/bin/phpunit -c phpunit.integration.xml --colors=always
sh scripts/test-env.sh reset
npm --prefix frontend ci --no-audit --no-fund
cd frontend
if [ -n "${CI:-}" ]; then
  npm exec -- playwright install --with-deps chromium
else
  npm exec -- playwright install chromium
fi
E2E_BASE_URL="${TEST_BASE_URL:-http://localhost:18080}" \
  MAILPIT_BASE_URL="${MAILPIT_BASE_URL:-http://localhost:18025}" \
  TEST_AD_LOGIN="${TEST_AD_LOGIN:-initiator}" \
  TEST_AD_PASSWORD="${TEST_AD_PASSWORD:-TestPassword1!}" npm run e2e
cd "$project_root"
sh scripts/test-runtime-contracts.sh
