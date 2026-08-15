#!/bin/sh
set -eu
project_root=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
cd "$project_root"
: "${COMPOSE:?COMPOSE must be provided by Makefile}"
: "${TEST_PROJECT:?TEST_PROJECT must be provided by Makefile}"
: "${TEST_ENV_FILE:?TEST_ENV_FILE must be provided by Makefile}"
compose="$COMPOSE -p $TEST_PROJECT --env-file $TEST_ENV_FILE -f compose.test.yaml"
. scripts/compose-metadata.sh

redact_download_tokens() {
  sed -E \
    -e 's#(/api/v1/document-links/)[a-f0-9]{64}(/download)#\1[REDACTED]\2#g' \
    -e 's#((download_token|token)=)[a-f0-9]{64}#\1[REDACTED]#g'
}

cleanup() {
  status=$?
  cd "$project_root"
  if [ "$status" -ne 0 ]; then
    echo "Test deployment failed; saving container status and recent logs." >&2
    diagnostics=frontend/playwright-report/deployment
    mkdir -p "$diagnostics"
    $compose ps >"$diagnostics/ps.txt" 2>&1 || true
    $compose logs --tail=200 2>&1 | redact_download_tokens >"$diagnostics/logs.txt" || true
  fi
  sh scripts/test-env.sh destroy || true
  return "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
artifact_probe_token=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
artifact_probe=$(printf '/api/v1/document-links/%s/download?token=%s safe-marker\n' \
  "$artifact_probe_token" "$artifact_probe_token" | redact_download_tokens)
printf '%s' "$artifact_probe" | grep -Fq 'safe-marker'
if printf '%s' "$artifact_probe" | grep -Fq "$artifact_probe_token"; then
  echo 'Deployment diagnostics redaction contract failed.' >&2
  exit 1
fi
sh scripts/test-env.sh build
sh scripts/test-env.sh up
if [ -z "${TEST_BASE_URL:-}" ]; then
  TEST_BASE_URL=$(compose_http_url frontend 8080)
  export TEST_BASE_URL
fi
if [ -z "${MAILPIT_BASE_URL:-}" ]; then
  MAILPIT_BASE_URL=$(compose_http_url mailpit 8025)
  export MAILPIT_BASE_URL
fi
$compose exec -T backend \
  vendor/bin/phpunit -c phpunit.integration.xml --colors=always
sh scripts/test-env.sh reset
npm --prefix frontend ci --no-audit --no-fund
cd frontend
if [ -n "${CI:-}" ]; then
  npm exec -- playwright install --with-deps chromium
else
  npm exec -- playwright install chromium
fi
E2E_BASE_URL="$TEST_BASE_URL" \
  MAILPIT_BASE_URL="$MAILPIT_BASE_URL" \
  TEST_AD_LOGIN="${TEST_AD_LOGIN:-initiator}" \
  TEST_AD_PASSWORD="${TEST_AD_PASSWORD:-TestPassword1!}" npm run e2e
cd "$project_root"
sh scripts/test-runtime-contracts.sh
