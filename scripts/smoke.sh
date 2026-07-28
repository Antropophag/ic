#!/usr/bin/env sh
set -eu

base_url=${BASE_URL:-http://localhost:8080}
dev_user_id=${DEV_USER_ID:-1}

attempts=0
until curl --fail --silent --show-error "$base_url/health/ready" >/dev/null; do
  attempts=$((attempts + 1))
  if [ "$attempts" -ge 30 ]; then
    echo "Readiness check did not pass: $base_url/health/ready" >&2
    exit 1
  fi
  sleep 2
done

curl --fail --silent --show-error "$base_url/health/live" | grep '"status":"ok"' >/dev/null

invalid_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --request POST \
  --header "X-Dev-User-ID: $dev_user_id" \
  --header 'Content-Type: application/json' \
  --data '{}' \
  "$base_url/api/v1/requests")
[ "$invalid_status" = '422' ] || {
  echo "Expected invalid request status 422, got $invalid_status" >&2
  exit 1
}

marker="Smoke $(date -u +%Y%m%d%H%M%S)"
created=$(curl --fail --silent --show-error \
  --request POST \
  --header "X-Dev-User-ID: $dev_user_id" \
  --header 'Content-Type: application/json' \
  --data "{\"productName\":\"$marker\",\"manufacturer\":\"Тестовый производитель\",\"supplier\":\"Тестовый поставщик\",\"sampleQuantity\":1,\"testMethod\":\"Smoke-проверка\"}" \
  "$base_url/api/v1/requests")
printf '%s' "$created" | grep '"status":"registered"' >/dev/null
request_id=$(printf '%s' "$created" | sed -n 's/.*"id":\([0-9][0-9]*\).*/\1/p')
[ -n "$request_id" ] || {
  echo "Created request does not contain an id" >&2
  exit 1
}

assigned=$(curl --fail --silent --show-error \
  --request POST \
  --header "X-Dev-User-ID: $dev_user_id" \
  --header 'Content-Type: application/json' \
  --data '{"executorId":2,"lockVersion":1}' \
  "$base_url/api/v1/requests/$request_id/executor")
printf '%s' "$assigned" | grep '"executorId":2' >/dev/null
printf '%s' "$assigned" | grep '"lockVersion":2' >/dev/null

denied_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --request POST \
  --header 'X-Dev-User-ID: 2' \
  --header 'Content-Type: application/json' \
  --data '{"executorId":2,"lockVersion":2}' \
  "$base_url/api/v1/requests/$request_id/executor")
[ "$denied_status" = '403' ] || {
  echo "Expected forbidden assignment status 403, got $denied_status" >&2
  exit 1
}

registry=$(curl --fail --silent --show-error \
  --header "X-Dev-User-ID: $dev_user_id" \
  "$base_url/api/v1/requests")
printf '%s' "$registry" | grep "$marker" >/dev/null
printf '%s' "$registry" | grep '"lockVersion":2' >/dev/null

started=$(curl --fail --silent --show-error \
  --request POST \
  --header 'X-Dev-User-ID: 2' \
  --header 'Content-Type: application/json' \
  --data '{"lockVersion":2}' \
  "$base_url/api/v1/requests/$request_id/start")
printf '%s' "$started" | grep '"status":"in_progress"' >/dev/null
printf '%s' "$started" | grep '"lockVersion":3' >/dev/null

stale_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --request POST \
  --header "X-Dev-User-ID: $dev_user_id" \
  --header 'Content-Type: application/json' \
  --data '{"lockVersion":2}' \
  "$base_url/api/v1/requests/$request_id/start")
[ "$stale_status" = '409' ] || {
  echo "Expected stale start status 409, got $stale_status" >&2
  exit 1
}

echo "Smoke test passed: health, validation, creation, assignment, start and registry."
