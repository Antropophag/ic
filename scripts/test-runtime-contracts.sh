#!/bin/sh
set -eu
cd "$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)"
compose='docker compose -f compose.test.yaml'
base=${TEST_BASE_URL:-http://localhost:18080}
mailpit=${MAILPIT_BASE_URL:-http://localhost:18025}
prod_identity_container="ic-test-prod-identity-contract-$$"
cookie_jar=$(mktemp)

curl_with_timeout() {
  curl --connect-timeout 3 --max-time 10 "$@"
}

restore_services() {
  status=$?
  trap - EXIT INT TERM
  docker rm -f "$prod_identity_container" >/dev/null 2>&1 || true
  $compose up -d ad mailpit mariadb scheduler >/dev/null 2>&1 || true
  rm -f "$cookie_jar"
  exit "$status"
}
trap restore_services EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

csrf_token() {
  curl_with_timeout -fsS -b "$cookie_jar" -c "$cookie_jar" "$base/api/v1/auth/me" |
    sed -n 's/.*"csrfToken":"\([^"]*\)".*/\1/p'
}

echo "Проверка защиты test identity окружением"
curl_with_timeout -fsS "$base/api/v1/auth/me" | grep -q '"devMode":false'
test_identity_code=$(curl_with_timeout -sS -o /dev/null -w '%{http_code}' \
  -H 'X-Test-User-ID: 3' "$base/api/v1/requests")
[ "$test_identity_code" = 200 ]
missing_identity_code=$(curl_with_timeout -sS -o /dev/null -w '%{http_code}' \
  -H 'X-Test-User-ID: 999999' "$base/api/v1/requests")
[ "$missing_identity_code" = 401 ]
$compose exec -T mariadb mariadb -uic_test -pic_test_password ic_test \
  -e 'UPDATE users SET is_active=0 WHERE id=3'
disabled_identity_code=$(curl_with_timeout -sS -o /dev/null -w '%{http_code}' \
  -H 'X-Test-User-ID: 3' "$base/api/v1/requests")
$compose exec -T mariadb mariadb -uic_test -pic_test_password ic_test \
  -e 'UPDATE users SET is_active=1 WHERE id=3'
[ "$disabled_identity_code" = 401 ]
$compose run -d --name "$prod_identity_container" \
  --publish 127.0.0.1::8080 \
  -e APP_ENV=prod backend \
  php -S 0.0.0.0:8080 -t public public/index.php >/dev/null
prod_port=$(docker port "$prod_identity_container" 8080/tcp | sed -n 's/.*://p' | head -n 1)
[ -n "$prod_port" ]
prod_base="http://127.0.0.1:$prod_port"
attempt=0
until curl_with_timeout -fsS "$prod_base/health/live" >/dev/null 2>&1; do
  attempt=$((attempt + 1))
  [ "$attempt" -lt 20 ] || exit 1
  sleep 1
done
production_header_code=$(curl_with_timeout -sS -o /dev/null -w '%{http_code}' \
  -H 'X-Test-User-ID: 3' "$prod_base/api/v1/requests")
[ "$production_header_code" = 401 ]
docker rm -f "$prod_identity_container" >/dev/null

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
request_id=$(printf '%s' "$created" | sed -n 's/.*"id":\([0-9][0-9]*\).*/\1/p')
[ -n "$request_id" ]
sleep 7
$compose ps --status running scheduler | grep -q scheduler
attempts=$($compose exec -T mariadb mariadb -N -uic_test -pic_test_password ic_test \
  -e "SELECT MAX(attempts) FROM notification_outbox WHERE request_id=$request_id")
[ "${attempts:-0}" -gt 0 ]
$compose start mailpit
$compose exec -T mariadb mariadb -uic_test -pic_test_password ic_test \
  -e "UPDATE notification_outbox SET next_attempt_at=CURRENT_TIMESTAMP WHERE request_id=$request_id AND status='pending'"
mail_attempt=0
until [ "$(curl_with_timeout -fsS "$mailpit/api/v1/messages" | grep -o '\"ID\"' | wc -l)" -gt 0 ]; do
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
request_id=$(printf '%s' "$created" | sed -n 's/.*"id":\([0-9][0-9]*\).*/\1/p')
[ -n "$request_id" ]
mail_attempt=0
until [ "$(curl_with_timeout -fsS "$mailpit/api/v1/messages" | grep -o '\"ID\"' | wc -l)" -gt 0 ]; do
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
