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

smoke_dir=$(mktemp -d)
trap 'rm -rf "$smoke_dir"' EXIT INT TERM
printf '%%PDF-1.4\n%% smoke document\n' >"$smoke_dir/report.pdf"
printf '<html>not a document</html>\n' >"$smoke_dir/invalid.pdf"
document=$(curl --fail --silent --show-error \
  --request POST \
  --header "X-Dev-User-ID: $dev_user_id" \
  --form "file=@$smoke_dir/report.pdf;type=application/pdf" \
  "$base_url/api/v1/requests/$request_id/documents")
printf '%s' "$document" | grep '"version":1' >/dev/null
document=$(curl --fail --silent --show-error \
  --request POST \
  --header "X-Dev-User-ID: $dev_user_id" \
  --form "file=@$smoke_dir/report.pdf;type=application/pdf" \
  "$base_url/api/v1/requests/$request_id/documents")
printf '%s' "$document" | grep '"version":2' >/dev/null
version_id=$(printf '%s' "$document" | sed -n 's/.*"versionId":\([0-9][0-9]*\).*/\1/p')
[ -n "$version_id" ] || {
  echo 'Uploaded document has no version id' >&2
  exit 1
}
curl --fail --silent --show-error \
  --header "X-Dev-User-ID: $dev_user_id" \
  --output "$smoke_dir/downloaded.pdf" \
  "$base_url/api/v1/document-versions/$version_id/download"
cmp "$smoke_dir/report.pdf" "$smoke_dir/downloaded.pdf"

invalid_file_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --request POST \
  --header "X-Dev-User-ID: $dev_user_id" \
  --form "file=@$smoke_dir/invalid.pdf;type=application/pdf" \
  "$base_url/api/v1/requests/$request_id/documents")
[ "$invalid_file_status" = '422' ] || {
  echo "Expected invalid document status 422, got $invalid_file_status" >&2
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

comment_marker="Комментарий $marker"
comment=$(curl --fail --silent --show-error \
  --request POST \
  --header "X-Dev-User-ID: $dev_user_id" \
  --header 'Content-Type: application/json' \
  --data "{\"body\":\"$comment_marker\"}" \
  "$base_url/api/v1/requests/$request_id/comments")
printf '%s' "$comment" | grep "$comment_marker" >/dev/null

invalid_comment_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --request POST \
  --header "X-Dev-User-ID: $dev_user_id" \
  --header 'Content-Type: application/json' \
  --data '{"body":"   "}' \
  "$base_url/api/v1/requests/$request_id/comments")
[ "$invalid_comment_status" = '422' ] || {
  echo "Expected invalid comment status 422, got $invalid_comment_status" >&2
  exit 1
}

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

premature_report_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --request POST \
  --header 'X-Dev-User-ID: 2' \
  --form "file=@$smoke_dir/report.pdf;type=application/pdf" \
  "$base_url/api/v1/requests/$request_id/report")
[ "$premature_report_status" = '403' ] || {
  echo "Expected premature report status 403, got $premature_report_status" >&2
  exit 1
}

started=$(curl --fail --silent --show-error \
  --request POST \
  --header 'X-Dev-User-ID: 2' \
  --header 'Content-Type: application/json' \
  --data '{"lockVersion":2}' \
  "$base_url/api/v1/requests/$request_id/start")
printf '%s' "$started" | grep '"status":"in_progress"' >/dev/null
printf '%s' "$started" | grep '"lockVersion":3' >/dev/null

report=$(curl --fail --silent --show-error \
  --request POST \
  --header 'X-Dev-User-ID: 2' \
  --form "file=@$smoke_dir/report.pdf;type=application/pdf" \
  "$base_url/api/v1/requests/$request_id/report")
printf '%s' "$report" | grep '"documentType":"report"' >/dev/null
printf '%s' "$report" | grep '"status":"opinion_preparation"' >/dev/null
printf '%s' "$report" | grep '"lockVersion":4' >/dev/null
report_version_id=$(printf '%s' "$report" | sed -n 's/.*"versionId":\([0-9][0-9]*\).*/\1/p')
[ -n "$report_version_id" ] || {
  echo 'Uploaded report has no version id' >&2
  exit 1
}
curl --fail --silent --show-error \
  --header 'X-Dev-User-ID: 2' \
  --output "$smoke_dir/downloaded-report.pdf" \
  "$base_url/api/v1/document-versions/$report_version_id/download"
cmp "$smoke_dir/report.pdf" "$smoke_dir/downloaded-report.pdf"

experts=$(curl --fail --silent --show-error \
  --header "X-Dev-User-ID: $dev_user_id" \
  "$base_url/api/v1/experts")
printf '%s' "$experts" | grep '"displayName":"Анна Смирнова"' >/dev/null
expert_assignment=$(curl --fail --silent --show-error \
  --request POST \
  --header "X-Dev-User-ID: $dev_user_id" \
  --header 'Content-Type: application/json' \
  --data '{"expertId":4,"lockVersion":4}' \
  "$base_url/api/v1/requests/$request_id/expert")
printf '%s' "$expert_assignment" | grep '"expertId":4' >/dev/null
printf '%s' "$expert_assignment" | grep '"lockVersion":5' >/dev/null
stale_expert_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --request POST \
  --header "X-Dev-User-ID: $dev_user_id" \
  --header 'Content-Type: application/json' \
  --data '{"expertId":4,"lockVersion":4}' \
  "$base_url/api/v1/requests/$request_id/expert")
[ "$stale_expert_status" = '409' ] || {
  echo "Expected stale expert assignment status 409, got $stale_expert_status" >&2
  exit 1
}

printf '%%PDF-1.4\n%% smoke report revision 2\n' >"$smoke_dir/report-v2.pdf"
report_v2=$(curl --fail --silent --show-error \
  --request POST \
  --header 'X-Dev-User-ID: 2' \
  --form "file=@$smoke_dir/report-v2.pdf;type=application/pdf" \
  "$base_url/api/v1/requests/$request_id/report")
printf '%s' "$report_v2" | grep '"version":2' >/dev/null
printf '%s' "$report_v2" | grep '"lockVersion":5' >/dev/null
report_v2_version_id=$(printf '%s' "$report_v2" | sed -n 's/.*"versionId":\([0-9][0-9]*\).*/\1/p')
[ -n "$report_v2_version_id" ] || {
  echo 'Second report revision has no version id' >&2
  exit 1
}

details=$(curl --fail --silent --show-error \
  --header "X-Dev-User-ID: $dev_user_id" \
  "$base_url/api/v1/requests/$request_id")
printf '%s' "$details" | grep "$marker" >/dev/null
printf '%s' "$details" | grep '"action":"start"' >/dev/null
printf '%s' "$details" | grep '"action":"assign_executor"' >/dev/null
printf '%s' "$details" | grep "$comment_marker" >/dev/null
printf '%s' "$details" | grep '"can_comment":1' >/dev/null
printf '%s' "$details" | grep '"can_upload_document":1' >/dev/null
printf '%s' "$details" | grep '"version":2' >/dev/null
printf '%s' "$details" | grep '"status":"opinion_preparation"' >/dev/null
printf '%s' "$details" | grep '"expert_name":"Анна Смирнова"' >/dev/null
printf '%s' "$details" | grep '"action":"assign_expert"' >/dev/null
printf '%s' "$details" | grep '"documentType":"report"' >/dev/null
report_history_count=$(printf '%s' "$details" | grep -o '"documentType":"report"' | wc -l | tr -d ' ')
[ "$report_history_count" = '2' ] || {
  echo "Expected two report revisions for participant, got $report_history_count" >&2
  exit 1
}
printf '%s' "$details" | grep '"action":"upload_report"' >/dev/null
printf '%s' "$details" | grep '"can_publish_opinion":0' >/dev/null
printf '%s' "$details" | grep -E '"(occurredAt|createdAt)":"[^"]+Z"' >/dev/null
if printf '%s' "$details" | grep 'payload_json' >/dev/null; then
  echo 'Request details must not expose audit payloads' >&2
  exit 1
fi

expert_details=$(curl --fail --silent --show-error \
  --header 'X-Dev-User-ID: 4' \
  "$base_url/api/v1/requests/$request_id")
printf '%s' "$expert_details" | grep '"can_publish_opinion":1' >/dev/null
denied_opinion_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --request POST \
  --header 'X-Dev-User-ID: 2' \
  --header 'Content-Type: application/json' \
  --data '{"body":"Недопустимое заключение исполнителя","lockVersion":5}' \
  "$base_url/api/v1/requests/$request_id/opinion")
[ "$denied_opinion_status" = '403' ] || {
  echo "Expected forbidden opinion status 403, got $denied_opinion_status" >&2
  exit 1
}
opinion=$(curl --fail --silent --show-error \
  --request POST \
  --header 'X-Dev-User-ID: 4' \
  --header 'Content-Type: application/json' \
  --data '{"body":"Образец соответствует заявленным требованиям по результатам испытаний.","lockVersion":5}' \
  "$base_url/api/v1/requests/$request_id/opinion")
printf '%s' "$opinion" | grep '"status":"security_review"' >/dev/null
printf '%s' "$opinion" | grep '"lockVersion":6' >/dev/null
opinion_version_id=$(printf '%s' "$opinion" | sed -n 's/.*"documentVersionId":\([0-9][0-9]*\).*/\1/p')
[ -n "$opinion_version_id" ] || {
  echo 'Published opinion has no document version id' >&2
  exit 1
}
stale_opinion_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --request POST \
  --header 'X-Dev-User-ID: 4' \
  --header 'Content-Type: application/json' \
  --data '{"body":"Повторная публикация заключения запрещена.","lockVersion":5}' \
  "$base_url/api/v1/requests/$request_id/opinion")
[ "$stale_opinion_status" = '403' ] || {
  echo "Expected repeated opinion status 403, got $stale_opinion_status" >&2
  exit 1
}
curl --fail --silent --show-error \
  --header 'X-Dev-User-ID: 4' \
  --output "$smoke_dir/opinion.pdf" \
  "$base_url/api/v1/document-versions/$opinion_version_id/download"
grep '%PDF-' "$smoke_dir/opinion.pdf" >/dev/null
private_opinion_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --header 'X-Dev-User-ID: 3' \
  "$base_url/api/v1/document-versions/$opinion_version_id/download")
[ "$private_opinion_status" = '404' ] || {
  echo "Expected private opinion status 404, got $private_opinion_status" >&2
  exit 1
}
opinion_details=$(curl --fail --silent --show-error \
  --header 'X-Dev-User-ID: 4' \
  "$base_url/api/v1/requests/$request_id")
printf '%s' "$opinion_details" | grep '"action":"publish_opinion"' >/dev/null
printf '%s' "$opinion_details" | grep '"documentType":"opinion"' >/dev/null
printf '%s' "$opinion_details" | grep '"can_security_decide":0' >/dev/null
security_details=$(curl --fail --silent --show-error \
  --header 'X-Dev-User-ID: 5' \
  "$base_url/api/v1/requests/$request_id")
printf '%s' "$security_details" | grep '"can_security_decide":1' >/dev/null
denied_security_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --request POST \
  --header 'X-Dev-User-ID: 2' \
  --header 'Content-Type: application/json' \
  --data '{"decision":"approve","reason":null,"lockVersion":6}' \
  "$base_url/api/v1/requests/$request_id/security-decision")
[ "$denied_security_status" = '403' ] || {
  echo "Expected forbidden security decision status 403, got $denied_security_status" >&2
  exit 1
}
security_decision=$(curl --fail --silent --show-error \
  --request POST \
  --header 'X-Dev-User-ID: 5' \
  --header 'Content-Type: application/json' \
  --data '{"decision":"approve","reason":null,"lockVersion":6}' \
  "$base_url/api/v1/requests/$request_id/security-decision")
printf '%s' "$security_decision" | grep '"status":"completed"' >/dev/null
printf '%s' "$security_decision" | grep '"lockVersion":7' >/dev/null
repeated_security_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --request POST \
  --header 'X-Dev-User-ID: 5' \
  --header 'Content-Type: application/json' \
  --data '{"decision":"approve","reason":null,"lockVersion":6}' \
  "$base_url/api/v1/requests/$request_id/security-decision")
[ "$repeated_security_status" = '403' ] || {
  echo "Expected repeated security decision status 403, got $repeated_security_status" >&2
  exit 1
}
completed_details=$(curl --fail --silent --show-error \
  --header 'X-Dev-User-ID: 5' \
  "$base_url/api/v1/requests/$request_id")
printf '%s' "$completed_details" | grep '"action":"security_approve"' >/dev/null

obsolete_public_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --header 'X-Dev-User-ID: 3' \
  "$base_url/api/v1/document-versions/$report_version_id/download")
[ "$obsolete_public_status" = '404' ] || {
  echo "Expected obsolete public report status 404, got $obsolete_public_status" >&2
  exit 1
}
curl --fail --silent --show-error \
  --header 'X-Dev-User-ID: 3' \
  --output "$smoke_dir/public-report.pdf" \
  "$base_url/api/v1/document-versions/$report_v2_version_id/download"
cmp "$smoke_dir/report-v2.pdf" "$smoke_dir/public-report.pdf"
curl --fail --silent --show-error \
  --header 'X-Dev-User-ID: 3' \
  --output "$smoke_dir/public-opinion.pdf" \
  "$base_url/api/v1/document-versions/$opinion_version_id/download"
cmp "$smoke_dir/opinion.pdf" "$smoke_dir/public-opinion.pdf"

public_details=$(curl --fail --silent --show-error \
  --header 'X-Dev-User-ID: 3' \
  "$base_url/api/v1/requests/$request_id")
public_report_count=$(printf '%s' "$public_details" | grep -o '"documentType":"report"' | wc -l | tr -d ' ')
[ "$public_report_count" = '1' ] || {
  echo "Expected one current public report, got $public_report_count" >&2
  exit 1
}

imported=$(curl --fail --silent --show-error \
  --request POST \
  --header "X-Dev-User-ID: $dev_user_id" \
  --header 'Content-Type: application/json' \
  --data "{\"productName\":\"Imported $marker\",\"manufacturer\":\"Тестовый производитель\",\"supplier\":\"Тестовый поставщик\",\"sampleQuantity\":1,\"testMethod\":\"Импортированный статус\"}" \
  "$base_url/api/v1/requests")
imported_request_id=$(printf '%s' "$imported" | sed -n 's/.*"id":\([0-9][0-9]*\).*/\1/p')
[ -n "$imported_request_id" ] || {
  echo 'Imported-state request has no id' >&2
  exit 1
}
docker compose exec -T mariadb sh -lc \
  'mariadb --user=root --password="$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" --execute "$1"' \
  sh "UPDATE requests SET status = 'opinion_preparation' WHERE id = $imported_request_id"
imported_report=$(curl --fail --silent --show-error \
  --request POST \
  --header "X-Dev-User-ID: $dev_user_id" \
  --form "file=@$smoke_dir/report.pdf;type=application/pdf" \
  "$base_url/api/v1/requests/$imported_request_id/report")
printf '%s' "$imported_report" | grep '"version":1' >/dev/null
printf '%s' "$imported_report" | grep '"status":"opinion_preparation"' >/dev/null
printf '%s' "$imported_report" | grep '"lockVersion":2' >/dev/null

# Имитируем возврат заявки с существующим отчётом из контроля СБ в работу.
docker compose exec -T mariadb sh -lc \
  'mariadb --user=root --password="$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" --execute "$1"' \
  sh "UPDATE requests SET status = 'in_progress' WHERE id = $imported_request_id"
returned_report=$(curl --fail --silent --show-error \
  --request POST \
  --header "X-Dev-User-ID: $dev_user_id" \
  --form "file=@$smoke_dir/report-v2.pdf;type=application/pdf" \
  "$base_url/api/v1/requests/$imported_request_id/report")
printf '%s' "$returned_report" | grep '"version":2' >/dev/null
printf '%s' "$returned_report" | grep '"status":"opinion_preparation"' >/dev/null
printf '%s' "$returned_report" | grep '"lockVersion":3' >/dev/null
returned_details=$(curl --fail --silent --show-error \
  --header "X-Dev-User-ID: $dev_user_id" \
  "$base_url/api/v1/requests/$imported_request_id")
printf '%s' "$returned_details" | grep '"status":"opinion_preparation"' >/dev/null
printf '%s' "$returned_details" | grep '"lockVersion":3' >/dev/null

hidden_status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --header 'X-Dev-User-ID: 999999' \
  "$base_url/api/v1/requests/$request_id")
[ "$hidden_status" = '404' ] || {
  echo "Expected hidden request status 404, got $hidden_status" >&2
  exit 1
}

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

echo "Smoke test passed: health, validation, creation, comments, documents, assignment, start, registry and details."
