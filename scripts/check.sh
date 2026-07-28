#!/usr/bin/env sh
set -eu

project_root=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
cd "$project_root"

python3 -m py_compile scripts/gen_schema_diagram.py
npm --prefix frontend ci --no-audit --no-fund
npm --prefix frontend run lint
npm --prefix frontend run audit
npm --prefix frontend run build
npm --prefix frontend run coverage

if command -v composer >/dev/null 2>&1 && command -v php >/dev/null 2>&1; then
  (cd backend && composer install --no-interaction --no-progress && composer lint && composer analyse && composer audit && composer test)
elif command -v docker >/dev/null 2>&1; then
  make backend-quality
  docker run --rm shlz-test-registry-coverage composer test
else
  echo "PHP checks require PHP 8.3 + Composer or Docker." >&2
  exit 1
fi

make coverage
make repo-quality

git diff --check
