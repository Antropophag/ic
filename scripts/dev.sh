#!/usr/bin/env sh
set -eu

# Backward-compatible entry point; Makefile uses environment.sh directly.
exec "$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)/environment.sh" dev up
