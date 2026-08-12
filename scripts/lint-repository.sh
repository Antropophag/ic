#!/usr/bin/env sh
set -eu

project_root=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
cd "$project_root"

for tool in actionlint hadolint shellcheck shfmt; do
  command -v "$tool" >/dev/null 2>&1 || {
    echo "Не найден обязательный инструмент: $tool" >&2
    exit 1
  }
done

actionlint .github/workflows/*.yml
hadolint docker/*.Dockerfile
shellcheck scripts/*.sh
shfmt -d scripts/*.sh
sh scripts/check-deployment-contracts.sh
sh scripts/check-review-contracts.sh
sh scripts/test-review-contracts.sh
if command -v yamllint >/dev/null 2>&1; then
  yamllint -c .yamllint.yml .
elif command -v uvx >/dev/null 2>&1; then
  uvx --from 'yamllint==1.37.1' yamllint -c .yamllint.yml .
else
  echo "Не найден обязательный инструмент: yamllint или uvx" >&2
  exit 1
fi
frontend/node_modules/.bin/markdownlint-cli2
git diff --check
