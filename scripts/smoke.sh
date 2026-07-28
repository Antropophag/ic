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

curl --fail --silent --show-error \
    --header "X-Dev-User-ID: $dev_user_id" \
    "$base_url/api/v1/requests" | grep "$marker" >/dev/null

echo "Smoke test passed: health, validation, creation and registry."
