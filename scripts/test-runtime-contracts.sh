#!/bin/sh
set -eu
cd "$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)"
: "${COMPOSE:?COMPOSE must be provided by Makefile}"
compose="$COMPOSE --env-file .env.test -f compose.test.yaml"
base=${TEST_BASE_URL:-http://localhost:18080}
mailpit=${MAILPIT_BASE_URL:-http://localhost:18025}
cookie_jar=$(mktemp)

curl_with_timeout() {
  curl --connect-timeout 3 --max-time 10 "$@"
}

restore_services() {
  status=$?
  trap - EXIT INT TERM
  $compose up -d ad mailpit mariadb scheduler >/dev/null 2>&1 || true
  rm -f "$cookie_jar"
  exit "$status"
}
trap restore_services EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

csrf_token() {
  curl_with_timeout -fsS -b "$cookie_jar" -c "$cookie_jar" "$base/api/v1/auth/me" |
    json_field csrfToken
}

json_field() {
  node -e '
    const fs = require("fs")
    const raw = fs.readFileSync(0, "utf8")
    try {
      const value = JSON.parse(raw)[process.argv[1]]
      if (value === undefined || value === null) process.exit(1)
      process.stdout.write(String(value))
    } catch (error) {
      console.error("Invalid JSON response: " + raw)
      process.exit(1)
    }
  ' "$1"
}

mailpit_message_count() {
  curl_with_timeout -fsS "$mailpit/api/v1/messages" |
    node -e '
      const fs = require("fs")
      const raw = fs.readFileSync(0, "utf8")
      try {
        const result = JSON.parse(raw)
        const messages = result.messages || result.items
        if (!Array.isArray(messages)) process.exit(1)
        process.stdout.write(String(messages.length))
      } catch (error) {
        console.error("Invalid Mailpit response: " + raw)
        process.exit(1)
      }
    '
}

db_query() {
  # shellcheck disable=SC2016 # Переменные раскрываются внутри mariadb-контейнера.
  $compose exec -T mariadb sh -eu -c \
    'mariadb -N -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -e "$1"' \
    sh "$1"
}

echo "Проверка защиты test identity окружением"
index_html=$(curl_with_timeout -fsS "$base/")
if printf '%s' "$index_html" | grep -q 'dev-tools.js'; then
  echo "Production frontend unexpectedly includes dev-tools.js" >&2
  exit 1
fi
assets=$(printf '%s' "$index_html" | sed -n 's/.*src="\([^"]*\.js\)".*/\1/p')
for asset in $assets; do
  if curl_with_timeout -fsS "$base$asset" | grep -Eq 'X-Dev-User-ID|/api/v1/dev/users'; then
    echo "Production frontend asset unexpectedly includes development identity code: $asset" >&2
    exit 1
  fi
done
test_identity_code=$(curl_with_timeout -sS -o /dev/null -w '%{http_code}' \
  -H 'X-Test-User-ID: 3' "$base/api/v1/requests")
[ "$test_identity_code" = 200 ]
missing_identity_code=$(curl_with_timeout -sS -o /dev/null -w '%{http_code}' \
  -H 'X-Test-User-ID: 999999' "$base/api/v1/requests")
[ "$missing_identity_code" = 401 ]
db_query 'UPDATE users SET is_active=0 WHERE id=3'
disabled_identity_code=$(curl_with_timeout -sS -o /dev/null -w '%{http_code}' \
  -H 'X-Test-User-ID: 3' "$base/api/v1/requests")
db_query 'UPDATE users SET is_active=1 WHERE id=3'
[ "$disabled_identity_code" = 401 ]
dev_route_code=$(curl_with_timeout -sS -o /dev/null -w '%{http_code}' "$base/api/v1/dev/users")
[ "$dev_route_code" = 404 ]
dev_identity_code=$(curl_with_timeout -sS -o /dev/null -w '%{http_code}' \
  -H 'X-Dev-User-ID: 3' "$base/api/v1/requests")
[ "$dev_identity_code" = 401 ]
set +e
dev_command_output=$($compose exec -T backend php yii dev/seed 2>&1)
dev_command_code=$?
set -e
[ "$dev_command_code" -ne 0 ]
printf '%s' "$dev_command_output" | grep -q 'Unknown command "dev/seed"'

echo "Проверка LDAP bind и профиля"
csrf=$(csrf_token)
[ -n "$csrf" ]
login_code=$(curl_with_timeout -sS -o /dev/null -w '%{http_code}' -b "$cookie_jar" -c "$cookie_jar" \
  -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
  -d '{"login":"initiator","password":"TestPassword1!"}' "$base/api/v1/auth/login")
[ "$login_code" = 200 ]
csrf=$(csrf_token)
bad_code=$(curl_with_timeout -sS -o /dev/null -w '%{http_code}' -b "$cookie_jar" -c "$cookie_jar" \
  -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
  -d '{"login":"initiator","password":"wrong"}' "$base/api/v1/auth/login")
[ "$bad_code" = 401 ]
$compose exec -T ad samba-tool group listmembers ICManagers | grep -qx ic_manager

echo "Проверка восстановления LDAP после недоступности"
$compose stop ad
csrf=$(csrf_token)
outage_code=$(curl_with_timeout -sS -o /dev/null -w '%{http_code}' -b "$cookie_jar" -c "$cookie_jar" \
  -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
  -d '{"login":"initiator","password":"TestPassword1!"}' "$base/api/v1/auth/login")
[ "$outage_code" = 500 ]
$compose start ad
attempt=0
until csrf=$(csrf_token) && curl_with_timeout -fsS -b "$cookie_jar" -c "$cookie_jar" \
  -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
  -d '{"login":"initiator","password":"TestPassword1!"}' "$base/api/v1/auth/login" >/dev/null; do
  attempt=$((attempt + 1))
  [ "$attempt" -lt 30 ] || exit 1
  sleep 1
done

create_request() {
  name=$1
  csrf=$(csrf_token)
  curl_with_timeout -fsS -b "$cookie_jar" -c "$cookie_jar" -X POST \
    -H 'X-Test-User-ID: 3' -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
    -d "{\"productName\":\"$name\",\"manufacturer\":\"Runtime\",\"supplier\":\"Runtime\",\"sampleQuantity\":1,\"testMethod\":\"Recovery\"}" \
    "$base/api/v1/requests"
}

echo "Проверка восстановления SMTP и повторной отправки"
curl_with_timeout -fsS -X DELETE "$mailpit/api/v1/messages" >/dev/null
$compose stop mailpit
created=$(create_request "SMTP runtime")
request_id=$(printf '%s' "$created" | json_field id)
[ -n "$request_id" ]
sleep 7
$compose ps --status running scheduler | grep -q scheduler
attempts=$(db_query "SELECT MAX(attempts) FROM notification_outbox WHERE request_id=$request_id")
[ "${attempts:-0}" -gt 0 ]
$compose start mailpit
db_query "UPDATE notification_outbox SET next_attempt_at=CURRENT_TIMESTAMP WHERE request_id=$request_id AND status='pending'"
mail_attempt=0
until [ "$(mailpit_message_count)" -gt 0 ]; do
  mail_attempt=$((mail_attempt + 1))
  [ "$mail_attempt" -lt 20 ] || exit 1
  sleep 1
done

echo "Проверка переподключения к MariaDB"
$compose stop mariadb
sleep 3
$compose ps --status running scheduler | grep -q scheduler
$compose logs --no-color scheduler | grep -q 'Notification worker iteration failed'
$compose start mariadb
attempt=0
until curl_with_timeout -fsS "$base/health/ready" >/dev/null 2>&1; do
  attempt=$((attempt + 1))
  [ "$attempt" -lt 40 ] || exit 1
  sleep 1
done
$compose ps --status running scheduler | grep -q scheduler
curl_with_timeout -fsS -X DELETE "$mailpit/api/v1/messages" >/dev/null
created=$(create_request "DB reconnect")
request_id=$(printf '%s' "$created" | json_field id)
[ -n "$request_id" ]
mail_attempt=0
until [ "$(mailpit_message_count)" -gt 0 ]; do
  mail_attempt=$((mail_attempt + 1))
  [ "$mail_attempt" -lt 20 ] || exit 1
  sleep 1
done

echo "Проверка корректного завершения notification worker по SIGTERM"
$compose kill -s SIGTERM scheduler
attempt=0
while $compose ps --status running scheduler | grep -q scheduler; do
  attempt=$((attempt + 1))
  [ "$attempt" -lt 15 ] || exit 1
  sleep 1
done
$compose logs --no-color scheduler | grep -q 'Notification worker stopped'
$compose up -d scheduler
$compose ps --status running scheduler | grep -q scheduler

echo "Runtime-проверки успешно завершены"
