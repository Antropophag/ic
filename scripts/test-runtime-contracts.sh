#!/bin/sh
set -eu
cd "$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)"
compose='docker compose -f compose.test.yaml'
base=${TEST_BASE_URL:-http://localhost:18080}
prod_identity_container=ic-test-prod-identity-contract

cleanup() {
  docker rm -f "$prod_identity_container" >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM
cleanup

echo "Test identity environment guard contract"
test_identity_code=$(curl -sS -o /dev/null -w '%{http_code}' \
  -H 'X-Test-User-ID: 3' "$base/api/v1/requests")
[ "$test_identity_code" = 200 ]
$compose run -d --name "$prod_identity_container" \
  --publish 127.0.0.1:18081:8080 \
  -e APP_ENV=prod backend \
  php -S 0.0.0.0:8080 -t public public/index.php >/dev/null
attempt=0
until curl -fsS http://localhost:18081/health/live >/dev/null 2>&1; do
  attempt=$((attempt + 1))
  [ "$attempt" -lt 20 ] || exit 1
  sleep 1
done
production_header_code=$(curl -sS -o /dev/null -w '%{http_code}' \
  -H 'X-Test-User-ID: 3' http://localhost:18081/api/v1/requests)
[ "$production_header_code" = 401 ]
cleanup

echo "LDAP bind/profile contract"
login_code=$(curl -sS -o /tmp/ic-login.json -w '%{http_code}' -H 'Content-Type: application/json' \
  -d '{"login":"initiator","password":"TestPassword1!"}' "$base/api/v1/auth/login")
[ "$login_code" = 200 ]
bad_code=$(curl -sS -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' \
  -d '{"login":"initiator","password":"wrong"}' "$base/api/v1/auth/login")
[ "$bad_code" = 401 ]
$compose exec -T ad samba-tool group listmembers ICManagers | grep -qx ic_manager

echo "LDAP outage/recovery contract"
$compose stop ad
outage_code=$(curl -sS -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' \
  -d '{"login":"initiator","password":"TestPassword1!"}' "$base/api/v1/auth/login")
[ "$outage_code" = 500 ]
$compose start ad
attempt=0
until curl -fsS -H 'Content-Type: application/json' \
  -d '{"login":"initiator","password":"TestPassword1!"}' "$base/api/v1/auth/login" >/dev/null; do
  attempt=$((attempt + 1))
  [ "$attempt" -lt 30 ] || exit 1
  sleep 1
done

echo "SMTP outage/retry/recovery contract"
curl -fsS -X DELETE "${MAILPIT_BASE_URL:-http://localhost:18025}/api/v1/messages" >/dev/null
$compose stop mailpit
created=$(curl -fsS -X POST -H 'X-Test-User-ID: 3' -H 'Content-Type: application/json' \
  -d '{"productName":"SMTP runtime","manufacturer":"Runtime","supplier":"Runtime","sampleQuantity":1,"testMethod":"Recovery"}' \
  "$base/api/v1/requests")
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
until [ "$(curl -fsS "${MAILPIT_BASE_URL:-http://localhost:18025}/api/v1/messages" | grep -o '\"ID\"' | wc -l)" -gt 0 ]; do
  mail_attempt=$((mail_attempt + 1))
  [ "$mail_attempt" -lt 20 ] || exit 1
  sleep 1
done

echo "MariaDB outage/reconnect contract"
$compose stop mariadb
sleep 3
$compose ps --status running scheduler | grep -q scheduler
$compose logs --no-color scheduler | grep -q 'Notification worker iteration failed'
$compose start mariadb
attempt=0
until curl -fsS "$base/health/ready" >/dev/null 2>&1; do
  attempt=$((attempt + 1))
  [ "$attempt" -lt 40 ] || exit 1
  sleep 1
done
$compose ps --status running scheduler | grep -q scheduler
curl -fsS -X DELETE "${MAILPIT_BASE_URL:-http://localhost:18025}/api/v1/messages" >/dev/null
created=$(curl -fsS -X POST -H 'X-Test-User-ID: 3' -H 'Content-Type: application/json' \
  -d '{"productName":"DB reconnect","manufacturer":"Runtime","supplier":"Runtime","sampleQuantity":1,"testMethod":"Recovery"}' \
  "$base/api/v1/requests")
request_id=$(printf '%s' "$created" | sed -n 's/.*"id":\([0-9][0-9]*\).*/\1/p')
[ -n "$request_id" ]
mail_attempt=0
until [ "$(curl -fsS "${MAILPIT_BASE_URL:-http://localhost:18025}/api/v1/messages" | grep -o '\"ID\"' | wc -l)" -gt 0 ]; do
  mail_attempt=$((mail_attempt + 1))
  [ "$mail_attempt" -lt 20 ] || exit 1
  sleep 1
done

echo "Notification worker SIGTERM contract"
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

echo "Runtime contracts passed"
