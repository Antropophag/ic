#!/bin/sh
# shellcheck disable=SC2016
set -eu
cd "$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)"
: "${COMPOSE:?COMPOSE must be provided by Makefile}"

export COMPOSE_ENV_FILE=.env.dev
compose="$COMPOSE --env-file .env.dev -f compose.yaml -f compose.dev.yaml"
base="http://localhost:${FRONTEND_PORT:-8080}"

curl -fsS "$base/health/live" | grep -q '"status":"ok"'
curl -fsS "$base/health/ready" | grep -q '"status":"ready"'
curl -fsS "$base/" | grep -q '<script type="module" src="/dev-tools.js"></script>'
curl -fsS "$base/dev-tools.js" | grep -q 'X-Dev-User-ID'

users=$(curl -fsS "$base/api/v1/dev/users")
printf '%s' "$users" | node -e '
  const fs = require("fs")
  const result = JSON.parse(fs.readFileSync(0, "utf8"))
  if (!Array.isArray(result.items) || result.items.length !== 7) process.exit(1)
  for (const user of result.items) {
    if (Object.keys(user).sort().join(",") !== "displayName,id,position,roles") process.exit(2)
    if (!Array.isArray(user.roles) || user.roles.length === 0) process.exit(3)
  }
'

for id in 1 2 3 4 5 6 7; do
  curl -fsS -H "X-Dev-User-ID: $id" "$base/api/v1/auth/me" |
    node -e '
      const fs = require("fs")
      const result = JSON.parse(fs.readFileSync(0, "utf8"))
      if (!result.user || !Array.isArray(result.user.roles) || result.user.roles.length === 0) process.exit(1)
    '
done

cookie_jar=$(mktemp)
trap 'rm -f "$cookie_jar"' EXIT INT TERM
csrf=$(curl -fsS -b "$cookie_jar" -c "$cookie_jar" -H 'X-Dev-User-ID: 3' \
  "$base/api/v1/auth/me" | node -e '
    const fs = require("fs")
    process.stdout.write(JSON.parse(fs.readFileSync(0, "utf8")).csrfToken)
  ')
seed_code=$(curl -sS -o /dev/null -w '%{http_code}' -b "$cookie_jar" -c "$cookie_jar" \
  -H 'X-Dev-User-ID: 3' -H "X-CSRF-Token: $csrf" -X POST "$base/api/v1/dev/seed-requests")
[ "$seed_code" = 403 ]
csrf=$(curl -fsS -b "$cookie_jar" -c "$cookie_jar" -H 'X-Dev-User-ID: 6' \
  "$base/api/v1/auth/me" | node -e '
    const fs = require("fs")
    process.stdout.write(JSON.parse(fs.readFileSync(0, "utf8")).csrfToken)
  ')
seed_code=$(curl -sS -o /dev/null -w '%{http_code}' -b "$cookie_jar" -c "$cookie_jar" \
  -H 'X-Dev-User-ID: 6' -H "X-CSRF-Token: $csrf" -X POST "$base/api/v1/dev/seed-requests")
[ "$seed_code" = 200 ]
rm -f "$cookie_jar"
trap - EXIT INT TERM

test_header_code=$(curl -sS -o /dev/null -w '%{http_code}' \
  -H 'X-Test-User-ID: 1' "$base/api/v1/requests")
[ "$test_header_code" = 401 ]

set +e
test_command_output=$(mktemp)
trap 'rm -f "$test_command_output"' EXIT INT TERM
$compose exec -T backend php yii test/reset >"$test_command_output" 2>&1
test_route_code=$?
set -e
[ "$test_route_code" -ne 0 ]
grep -q 'Unknown command "test/reset"' "$test_command_output"
rm -f "$test_command_output"
trap - EXIT INT TERM

before_users=$($compose exec -T mariadb sh -c \
  'mariadb -N -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -e "SELECT COUNT(*) FROM users"')
before_roles=$($compose exec -T mariadb sh -c \
  'mariadb -N -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -e "SELECT COUNT(*) FROM user_roles"')
$compose run --rm backend php yii dev/seed >/dev/null
after_users=$($compose exec -T mariadb sh -c \
  'mariadb -N -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -e "SELECT COUNT(*) FROM users"')
after_roles=$($compose exec -T mariadb sh -c \
  'mariadb -N -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -e "SELECT COUNT(*) FROM user_roles"')
[ "$before_users:$before_roles" = "$after_users:$after_roles" ]

$compose exec -T mariadb sh -c \
  'mariadb -u root -p"$MARIADB_ROOT_PASSWORD" -e "
    CREATE DATABASE IF NOT EXISTS ic;
    CREATE DATABASE IF NOT EXISTS ic_test;
    GRANT ALL ON ic.* TO '\''${MARIADB_USER}'\''@'\''%'\'';
    GRANT ALL ON ic_test.* TO '\''${MARIADB_USER}'\''@'\''%'\'';
  "'
for database in ic ic_test; do
  tables_before=$($compose exec -T mariadb sh -c \
    "mariadb -N -u root -p\"\$MARIADB_ROOT_PASSWORD\" -e \
      \"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$database'\"")
  set +e
  refusal=$($compose run --rm -e DB_NAME="$database" backend php yii dev/seed 2>&1)
  refusal_code=$?
  set -e
  [ "$refusal_code" -ne 0 ]
  printf '%s' "$refusal" | grep -q 'должно оканчиваться на _dev'
  tables_after=$($compose exec -T mariadb sh -c \
    "mariadb -N -u root -p\"\$MARIADB_ROOT_PASSWORD\" -e \
      \"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$database'\"")
  [ "$tables_before" = "$tables_after" ]
done

$compose ps --status running scheduler | grep -q scheduler
echo "Development runtime contracts passed"
