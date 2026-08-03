#!/bin/sh
set -eu
project_root=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
cd "$project_root"
: "${COMPOSE:?COMPOSE must be provided by Makefile}"
cleanup() {
  status=$?
  cd "$project_root"
  if [ "$status" -ne 0 ]; then
    echo "Test deployment failed; saving container status and recent logs." >&2
    diagnostics=frontend/playwright-report/deployment
    mkdir -p "$diagnostics"
    $COMPOSE --env-file .env.test -f compose.test.yaml ps >"$diagnostics/ps.txt" 2>&1 || true
    $COMPOSE --env-file .env.test -f compose.test.yaml logs --tail=200 >"$diagnostics/logs.txt" 2>&1 || true
  fi
  sh scripts/test-env.sh destroy || true
  return "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
sh scripts/test-env.sh build
sh scripts/test-env.sh up
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
E2E_BASE_URL="${TEST_BASE_URL:-http://localhost:${TEST_FRONTEND_PORT:-18080}}" \
  MAILPIT_BASE_URL="${MAILPIT_BASE_URL:-http://localhost:${TEST_MAILPIT_PORT:-18025}}" \
  TEST_AD_LOGIN="${TEST_AD_LOGIN:-initiator}" \
  TEST_AD_PASSWORD="${TEST_AD_PASSWORD:-TestPassword1!}" npm run e2e
cd "$project_root"
sh scripts/test-runtime-contracts.sh
