#!/usr/bin/env sh
set -eu

base_url=${BASE_URL:-http://localhost:8080}
initiator=${INITIATOR_USER_ID:-3}
manager=${MANAGER_USER_ID:-1}

wait_attempt=0
until curl -fsS "$base_url/health/ready" >/dev/null; do
  wait_attempt=$((wait_attempt + 1))
  [ "$wait_attempt" -lt 30 ] || { echo "Readiness timeout" >&2; exit 1; }
  sleep 1
done
curl -fsS "$base_url/health/live" | grep -q '"status":"ok"'

marker="Smoke-$(date -u +%Y%m%d%H%M%S)"
created=$(curl -fsS -X POST -H "X-Dev-User-ID: $initiator" -H 'Content-Type: application/json' \
  -d "{\"productName\":\"$marker\",\"manufacturer\":\"Smoke\",\"supplier\":\"Smoke\",\"sampleQuantity\":1,\"testMethod\":\"Smoke\"}" \
  "$base_url/api/v1/requests")
request_id=$(printf '%s' "$created" | sed -n 's/.*"id":\([0-9][0-9]*\).*/\1/p')
[ -n "$request_id" ]

curl -fsS -H "X-Dev-User-ID: $initiator" "$base_url/api/v1/requests/$request_id" | grep -q "$marker"
curl -fsS -X POST -H "X-Dev-User-ID: $manager" -H 'Content-Type: application/json' \
  -d '{"executorId":2,"lockVersion":1}' "$base_url/api/v1/requests/$request_id/executor" >/dev/null
curl -fsS -X POST -H "X-Dev-User-ID: $manager" -H 'Content-Type: application/json' \
  -d '{"lockVersion":2}' "$base_url/api/v1/requests/$request_id/start" >/dev/null
echo "Smoke passed: request $request_id created, read, assigned and started"
