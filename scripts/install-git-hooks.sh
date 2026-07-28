#!/usr/bin/env sh
set -eu

project_root=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
git -C "$project_root" config core.hooksPath .githooks
echo "Git hooks enabled: every push will run the project checks."
