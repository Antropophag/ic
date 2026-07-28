#!/usr/bin/env sh
set -eu

project_root=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
output=${1:-"$project_root/dist-demo"}

mkdir -p "$output"
docker compose build backend gateway
docker tag shlz-test-registry-backend:latest shlz-test-registry-backend:demo
docker tag shlz-test-registry-gateway:latest shlz-test-registry-gateway:demo
docker pull mariadb:11.4.7-noble
docker save \
  --output "$output/demo-images.tar" \
  shlz-test-registry-backend:demo \
  shlz-test-registry-gateway:demo \
  mariadb:11.4.7-noble

cp "$project_root/compose.demo.yaml" "$output/compose.demo.yaml"
cp "$project_root/.env.example" "$output/.env.example"
cp "$project_root/scripts/demo-up.ps1" "$output/demo-up.ps1"
(cd "$output" && sha256sum demo-images.tar >SHA256SUMS)

echo "Demo bundle created in: $output"
