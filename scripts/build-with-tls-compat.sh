#!/bin/sh
set -eu

# Opt-in for build networks that reject large hybrid TLS ClientHello messages.
case "${BUILD_TLS_COMPAT:-0}" in
0) exec "$@" ;;
1) ;;
*)
  echo "BUILD_TLS_COMPAT must be 0 or 1" >&2
  exit 2
  ;;
esac

tls_config=$(mktemp)
trap 'rm -f "$tls_config"' EXIT HUP INT TERM
cat >"$tls_config" <<'EOF'
openssl_conf = build_tls_init
nodejs_conf = build_tls_init

[build_tls_init]
ssl_conf = build_tls_config

[build_tls_config]
system_default = build_tls_defaults

[build_tls_defaults]
Groups = X25519:P-256:P-384
EOF

# Both OpenSSL consumers and Node read this file only for this command.
OPENSSL_CONF="$tls_config" "$@"
