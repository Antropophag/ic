#!/bin/sh
set -eu
cd "$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)"
compose='docker compose -f compose.test.yaml'
image=shlz-test-registry-coverage

$compose up -d mariadb
attempt=0
until $compose exec -T mariadb healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; do
  attempt=$((attempt + 1))
  [ "$attempt" -lt 90 ] || {
    echo "Тестовая MariaDB не стала готова за 90 секунд." >&2
    $compose logs --no-color --tail=200 mariadb >&2
    exit 1
  }
  sleep 1
done
container_id=$($compose ps -q mariadb)
network=$(docker inspect --format '{{range $name, $_ := .NetworkSettings.Networks}}{{$name}}{{"\n"}}{{end}}' "$container_id" | head -n 1)
[ -n "$network" ] || {
  echo "Не удалось определить сеть тестовой MariaDB." >&2
  exit 1
}
docker build --file docker/coverage.Dockerfile --tag "$image" .
docker run --rm --network "$network" \
  -e APP_ENV=test -e DB_HOST=mariadb -e DB_PORT=3306 -e DB_NAME=ic_test \
  -e DB_USER=ic_test -e DB_PASSWORD=ic_test_password \
  -e APP_PUBLIC_URL=http://localhost:18080 "$image" \
  sh -c 'php yii migrate/up && vendor/bin/phpunit -c phpunit.integration.xml --colors=always'
