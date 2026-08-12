#!/bin/sh
set -eu

cd "$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)"
: "${COMPOSE:?COMPOSE must be provided by Makefile}"
: "${CONTAINER_ENGINE:?CONTAINER_ENGINE must be provided by Makefile}"

project=ic-coverage
env_file=.env.test
compose="$COMPOSE -p $project --env-file $env_file -f compose.test.yaml"

cleanup() {
  $compose down --volumes --remove-orphans
}
trap cleanup EXIT
trap 'exit 1' INT TERM

$compose up -d --wait mariadb
$CONTAINER_ENGINE run --rm \
  --network "${project}_default" \
  --env-file "$env_file" \
  --env MAILPIT_API_URL= \
  --volume "$(pwd)/deployment/test/console.php:/app/deployment/console.php:ro" \
  shlz-test-registry-coverage \
  php yii test/reset
$CONTAINER_ENGINE run --rm \
  --network "${project}_default" \
  --env-file "$env_file" \
  --volume "$(pwd)/backend/build/coverage:/app/build/coverage" \
  shlz-test-registry-coverage \
  php vendor/bin/phpunit --configuration phpunit.coverage.xml \
  --coverage-clover build/coverage/clover.xml
