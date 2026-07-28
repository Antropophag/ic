#!/usr/bin/env sh
set -eu

project_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$project_root"

python3 -m py_compile scripts/gen_schema_diagram.py
npm --prefix frontend ci --no-audit --no-fund
npm --prefix frontend run build

if command -v composer >/dev/null 2>&1 && command -v php >/dev/null 2>&1; then
    (cd backend && composer install --no-interaction --no-progress && composer test)
elif command -v docker >/dev/null 2>&1; then
    docker run --rm --volume "$project_root/backend:/app" --workdir /app composer:2.8.10 \
        sh -lc 'composer install --no-interaction --no-progress && composer test'
else
    echo "PHP checks require PHP 8.3 + Composer or Docker." >&2
    exit 1
fi

git diff --check
