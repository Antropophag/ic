#!/usr/bin/env sh
set -eu

# Backward-compatible entry point; Makefile uses environment.sh directly.
repository_root=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
exec make -C "$repository_root" dev-up
