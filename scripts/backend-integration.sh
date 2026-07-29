#!/usr/bin/env sh
set -eu

# Прогоняет интеграционные тесты Infrastructure-репозиториев
# (RequestRepository, DocumentRepository) против реальной MariaDB —
# отдельная база `ic_test` в том же сервисе, что и dev/demo-контур, не
# пересекается с его данными и накатывается идемпотентно при каждом запуске.

project_root=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
cd "$project_root"

test_db_name=${TEST_DB_NAME:-ic_test}
network_name=${INTEGRATION_NETWORK:-shlz-test-registry_default}
image_tag=shlz-test-registry-coverage

if [ ! -f .env ]; then
  cp .env.example .env
fi

docker compose up -d mariadb

attempts=0
until docker compose exec -T mariadb healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; do
  attempts=$((attempts + 1))
  if [ "$attempts" -ge 30 ]; then
    echo "MariaDB did not become healthy" >&2
    exit 1
  fi
  sleep 2
done

db_user=$(grep -m1 '^DB_USER=' .env | cut -d= -f2-)
db_password=$(grep -m1 '^DB_PASSWORD=' .env | cut -d= -f2-)

# Тестовая база создаётся под тем же пользователем, что и dev/demo-база
# (см. MARIADB_USER/MARIADB_PASSWORD в compose.yaml) — отдельный
# пользователь не нужен, достаточно выдать ему права ещё на одну схему.
docker compose exec -T mariadb sh -lc \
  'mariadb --user=root --password="$MARIADB_ROOT_PASSWORD" --execute "$1"' \
  sh "CREATE DATABASE IF NOT EXISTS \`$test_db_name\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON \`$test_db_name\`.* TO '$db_user'@'%';"

docker build --file docker/coverage.Dockerfile --tag "$image_tag" .

docker run --rm \
  --network "$network_name" \
  -e DB_HOST=mariadb \
  -e DB_PORT=3306 \
  -e DB_NAME="$test_db_name" \
  -e DB_USER="$db_user" \
  -e DB_PASSWORD="$db_password" \
  -e APP_PUBLIC_URL=http://localhost:8080 \
  "$image_tag" \
  sh -c 'php yii migrate/up && vendor/bin/phpunit -c phpunit.integration.xml --colors=always'
