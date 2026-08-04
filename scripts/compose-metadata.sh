#!/bin/sh
# shellcheck disable=SC2154 # Callers define the deployment-specific compose command.

# Shared helpers for metadata reported by Docker Compose and Podman Compose.

compose_published_port() {
  service=$1
  container_port=$2
  # shellcheck disable=SC2086 # COMPOSE may contain a command with arguments.
  binding=$($compose port "$service" "$container_port" | sed -n '1p')
  published_port=${binding##*:}
  case "$published_port" in
  '' | *[!0-9]*)
    echo "Cannot determine published port for $service:$container_port from: $binding" >&2
    return 1
    ;;
  esac
  printf '%s\n' "$published_port"
}

compose_http_url() {
  service=$1
  container_port=$2
  published_host=${COMPOSE_PUBLISHED_HOST:-localhost}
  published_port=$(compose_published_port "$service" "$container_port") || return
  printf 'http://%s:%s\n' "$published_host" "$published_port"
}
