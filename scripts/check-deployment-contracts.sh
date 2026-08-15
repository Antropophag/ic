#!/bin/sh
set -eu

cd "$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)"

tracked_runtime_env=$(git ls-files -- .env .env.dev .env.prod)
if [ -n "$tracked_runtime_env" ]; then
  echo "Runtime environment files must stay untracked; commit sanitized examples only:" >&2
  printf '%s\n' "$tracked_runtime_env" >&2
  exit 1
fi

reject_tracked() {
  description=$1
  pattern=$2
  shift 2
  set +e
  matches=$(git grep -n -E "$pattern" -- "$@")
  grep_status=$?
  set -e
  if [ "$grep_status" -eq 0 ]; then
    echo "$description:" >&2
    printf '%s\n' "$matches" >&2
    return 1
  fi
  if [ "$grep_status" -ne 1 ]; then
    echo "Cannot check deployment contract: $description" >&2
    return "$grep_status"
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

grep -q '^PROD_PROJECT := ic-prod$' Makefile
grep -q '^DEV_PROJECT := ic-dev$' Makefile
grep -q '^TEST_PROJECT := ic-test$' Makefile
grep -q '^PROD_ENV_FILE := .env.prod$' Makefile
grep -q '^DEV_ENV_FILE := .env.dev$' Makefile
grep -q '^TEST_ENV_FILE := .env.test$' Makefile
grep -q '^COMPOSE_PROJECT_NAME=ic-prod$' .env.example
grep -q '^COMPOSE_FILE=compose.yaml$' .env.example
grep -q '^COMPOSE_ENV_FILE=.env.prod$' .env.example
grep -q '^FRONTEND_PORT=8080$' .env.example
grep -q '^COMPOSE_PROJECT_NAME=ic-dev$' .env.dev.example
grep -q '^COMPOSE_FILE=compose.yaml:compose.dev.yaml$' .env.dev.example
grep -q '^COMPOSE_ENV_FILE=.env.dev$' .env.dev.example
grep -q '^FRONTEND_PORT=8081$' .env.dev.example
grep -q '^COMPOSE_PROJECT_NAME=ic-test$' .env.test
grep -q '^COMPOSE_FILE=compose.test.yaml$' .env.test
grep -q '^TEST_ENV_FILE=.env.test$' .env.test
grep -q '^FRONTEND_PORT=18080$' .env.test
grep -Fq "\${FRONTEND_PORT:-18080}:8080" compose.test.yaml
grep -Fq "\${MAILPIT_PORT:-18026}:8025" compose.test.yaml
grep -Fq "\${FRONTEND_PORT:-8080}:8080" compose.yaml
if grep -Eq '^[[:space:]]*container_name:' compose*.yaml; then
  echo "Fixed container_name is forbidden in Compose files" >&2
  exit 1
fi
for compose_file in compose.yaml compose.dev.yaml compose.test.yaml; do
  if awk '
    /^(networks|volumes):[[:space:]]*$/ { section = 1; next }
    /^[^[:space:]#]/ { section = 0 }
    section && /^[[:space:]]+name:/ { found = 1 }
    END { exit found ? 0 : 1 }
  ' "$compose_file"; then
    echo "$compose_file must not assign explicit network or volume names" >&2
    exit 1
  fi
done
grep -q '^prod-up: _doctor$' Makefile
grep -q '^prod-restart: _doctor$' Makefile
grep -q '^prod-status: _doctor$' Makefile
grep -q '^dev-up: _doctor$' Makefile
grep -q '^dev-restart: _doctor$' Makefile
grep -q '^dev-status: _doctor$' Makefile
grep -q '^dev-reset: doctor$' Makefile
grep -q '^prod-reset: doctor$' Makefile
grep -q '^env-status: doctor$' Makefile
grep -Fq "sh scripts/environment.sh prod up" Makefile
grep -Fq "sh scripts/environment.sh dev up" Makefile
grep -Fq "compose build backend scheduler frontend" scripts/environment.sh
grep -Fq "compose stop frontend scheduler backend" scripts/environment.sh
grep -Fq "compose up -d --no-build --force-recreate backend frontend scheduler" scripts/environment.sh
grep -Fq "compose logs --follow" scripts/environment.sh
grep -Fq "SERVICE_READY_TIMEOUT" scripts/environment.sh
grep -Fq "compose config --quiet" scripts/environment.sh
# shellcheck disable=SC2016 # This is a literal Compose interpolation contract.
[ "$(grep -Fc 'env_file: ${COMPOSE_ENV_FILE:?COMPOSE_ENV_FILE must select .env.dev or .env.prod}' compose.yaml)" -eq 2 ]
# shellcheck disable=SC2016 # This is a literal Compose interpolation contract.
[ "$(grep -Fc 'env_file: ${TEST_ENV_FILE:?TEST_ENV_FILE must select the test environment}' compose.test.yaml)" -eq 2 ]
grep -Fq "exec make --no-print-directory -C \"\$repository_root\" dev-up" scripts/dev.sh
grep -Fq 'http://127.0.0.1:8080/health/ready' compose.yaml

if ambiguous_output=$(make --no-print-directory up 2>&1); then
  ambiguous_status=0
else
  ambiguous_status=$?
fi
[ "$ambiguous_status" -eq 2 ]
printf '%s' "$ambiguous_output" | grep -q 'make dev-up или make prod-up'

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
grep -Fq 'COPY frontend/package*.json frontend/.npmrc ./' docker/frontend.Dockerfile
grep -Fxq 'backend/deployment' .dockerignore

# Production и development images должны содержать проверенный контракт и
# локальные Swagger UI assets; каждый stage проверяется независимо.
stage_has_copy() {
  stage=$1
  copy_instruction=$2
  awk -v stage="$stage" -v copy_instruction="$copy_instruction" '
    $1 == "FROM" { active = ($NF == stage); next }
    active && index($0, copy_instruction) { found = 1 }
    END { exit found ? 0 : 1 }
  ' docker/frontend.Dockerfile
}
for stage in production development; do
  stage_has_copy "$stage" 'COPY openapi/openapi.yaml /srv/frontend/api/openapi.yaml'
  stage_has_copy "$stage" 'COPY openapi/swagger-ui/index.html /srv/frontend/api/docs/index.html'
  stage_has_copy "$stage" 'node_modules/swagger-ui-dist/swagger-ui-bundle.js'
  stage_has_copy "$stage" 'node_modules/swagger-ui-dist/swagger-ui.css'
done
grep -Fq 'location = /api/openapi.yaml' docker/nginx/default.conf
grep -Fq 'location ^~ /api/docs/' docker/nginx/default.conf
grep -Fq 'client_max_body_size 256m;' docker/nginx/default.conf
# shellcheck disable=SC2016 # These are literal nginx variable contracts.
grep -Fq 'map $request_uri $source_access_request_uri {' docker/nginx/default.conf
grep -Fq '"~^/api/v1/document-links/[a-f0-9]{64}/download(?:\\?|$)" /api/v1/document-links/[masked]/download;' docker/nginx/default.conf
# shellcheck disable=SC2016 # These are literal nginx variable contracts.
grep -Fq '"$request_method $source_access_request_uri $server_protocol"' docker/nginx/default.conf
# shellcheck disable=SC2016 # These are literal nginx variable contracts.
grep -Fq 'map $http_referer $source_access_referer {' docker/nginx/default.conf
grep -Fq '"~^.*?/api/v1/document-links/[a-f0-9]{64}/download(?:[?#].*)?$" /api/v1/document-links/[masked]/download;' docker/nginx/default.conf
# shellcheck disable=SC2016 # These are literal nginx variable contracts.
grep -Fq '"$source_access_referer" "$http_user_agent" request_time=$request_time' docker/nginx/default.conf
grep -Fq 'access_log /var/log/nginx/access.log source_access;' docker/nginx/default.conf
# shellcheck disable=SC2016 # These are literal nginx variable contracts.
source_access_format=$(awk '
  /^log_format source_access[[:space:]]/ { collecting = 1 }
  collecting { printf "%s ", $0 }
  collecting && /;/ { exit }
' docker/nginx/default.conf)
# shellcheck disable=SC2016 # These are literal nginx variable contracts.
if printf '%s\n' "$source_access_format" | grep -Eq '(\$request|\$request_uri)([^_a-zA-Z]|$)'; then
  echo 'Nginx source access log must use the sanitized request URI.' >&2
  exit 1
fi
# Prove that the guard rejects a forbidden variable on a continuation line.
# shellcheck disable=SC2016 # These are literal nginx variable contracts.
unsafe_source_access_format=$(printf '%s\n' "$source_access_format" | sed 's/\$source_access_request_uri/\$request_uri/')
# shellcheck disable=SC2016 # These are literal nginx variable contracts.
printf '%s\n' "$unsafe_source_access_format" | grep -Eq '(\$request|\$request_uri)([^_a-zA-Z]|$)'
for alloy_config in observability/alloy/config.alloy observability/alloy/config.test.alloy; do
  grep -Fq 'expression = "(?P<download_token>/api/v1/document-links/[a-f0-9]{64}/download)"' "$alloy_config"
  grep -Fq 'replace    = "/api/v1/document-links/[REDACTED]/download"' "$alloy_config"
done
grep -Fxq 'upload_max_filesize=200M' docker/php/uploads.ini
grep -Fxq 'post_max_size=210M' docker/php/uploads.ini

# Validate the exact direct-Compose entry points without relying on local
# untracked deployment env files or exposing their secrets.
compose_config_dir=$(mktemp -d)
compose_provider=${COMPOSE:-docker compose}
cleanup_compose_config_dir() {
  rm -rf "$compose_config_dir"
}
trap cleanup_compose_config_dir EXIT HUP INT TERM
cp .env.example "$compose_config_dir/.env.prod"
cp .env.dev.example "$compose_config_dir/.env.dev"
cp .env.test "$compose_config_dir/.env.test"
ln -s "$PWD/compose.yaml" "$compose_config_dir/compose.yaml"
ln -s "$PWD/compose.dev.yaml" "$compose_config_dir/compose.dev.yaml"
ln -s "$PWD/compose.test.yaml" "$compose_config_dir/compose.test.yaml"
(
  cd "$compose_config_dir"
  # shellcheck disable=SC2086 # Compose provider command intentionally contains arguments.
  $compose_provider --env-file .env.prod config --quiet
  # shellcheck disable=SC2086 # Compose provider command intentionally contains arguments.
  $compose_provider --env-file .env.dev config --quiet
  # shellcheck disable=SC2086 # Compose provider command intentionally contains arguments.
  $compose_provider --env-file .env.test config --quiet
)
cleanup_compose_config_dir
trap - EXIT HUP INT TERM

sh scripts/test-environment-output.sh

echo "Deployment metadata contracts passed"
