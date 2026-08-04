#!/bin/sh
set -eu

cd "$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)"

reject_tracked() {
  description=$1
  pattern=$2
  shift 2
  if matches=$(git grep -n -E "$pattern" -- "$@" 2>/dev/null); then
    echo "$description:" >&2
    printf '%s\n' "$matches" >&2
    return 1
  fi
}

# Public URLs must come from Compose metadata; service-to-service port 8025 is valid.
old_mailpit_port=18025
localhost_prefix=localhost:
reject_tracked "Hardcoded legacy Mailpit URL" \
  "${localhost_prefix}${old_mailpit_port}|${localhost_prefix}8025" \
  . ':(exclude)scripts/check-deployment-contracts.sh'

# Keep parsing differences between Docker Compose and Podman Compose in one place.
# shellcheck disable=SC2016 # This is a grep pattern, not a shell expression.
reject_tracked "Direct Compose port lookup outside the shared helper" \
  '\$compose[[:space:]]+port' \
  'scripts/*.sh' ':(exclude)scripts/compose-metadata.sh'

# Runtime scripts receive project names from Makefile; literal project flags drift easily.
reject_tracked "Hardcoded Compose project name in automation" \
  '(^|[[:space:]])(-p|--project-name)[[:space:]]+[a-zA-Z0-9]' \
  'scripts/*.sh' '.github/workflows/*.yml' '.gitlab-ci.yml'

for compose_file in compose.yaml compose.dev.yaml compose.test.yaml; do
  if grep -Eq '^name:' "$compose_file"; then
    echo "$compose_file must not define a project name; Makefile owns project metadata." >&2
    exit 1
  fi
done

# Regression-test both common `compose port` output formats.
# shellcheck source=scripts/compose-metadata.sh
. scripts/compose-metadata.sh
compose=fake_compose
fake_compose() {
  printf '%s\n' "$FAKE_COMPOSE_BINDING"
  return "${FAKE_COMPOSE_STATUS:-0}"
}
FAKE_COMPOSE_BINDING=0.0.0.0:18026
[ "$(compose_published_port mailpit 8025)" = 18026 ]
FAKE_COMPOSE_BINDING='[::]:18026'
[ "$(compose_published_port mailpit 8025)" = 18026 ]
COMPOSE_PUBLISHED_HOST=docker
export COMPOSE_PUBLISHED_HOST
[ "$(compose_http_url mailpit 8025)" = http://docker:18026 ]
FAKE_COMPOSE_BINDING=''
FAKE_COMPOSE_STATUS=1
set +e
failed_url=$(compose_http_url mailpit 8025 2>/dev/null)
failed_status=$?
set -e
[ "$failed_status" -ne 0 ]
[ -z "$failed_url" ]

grep -q 'MAILPIT_BASE_URL must be provided' frontend/e2e/notifications.e2e.js

echo "Deployment metadata contracts passed"
