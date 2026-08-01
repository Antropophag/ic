#!/bin/sh
set -eu
cd "$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)"
trap 'sh scripts/test-env.sh down' EXIT INT TERM
sh scripts/test-env.sh up
sh scripts/test-env.sh reset
npm --prefix frontend ci --no-audit --no-fund
cd frontend
npx playwright install chromium
E2E_BASE_URL="${TEST_BASE_URL:-http://localhost:18080}" \
MAILPIT_BASE_URL="${MAILPIT_BASE_URL:-http://localhost:18025}" npm run e2e
