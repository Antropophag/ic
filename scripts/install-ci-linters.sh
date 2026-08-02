#!/usr/bin/env sh
set -eu

actionlint_version=1.7.7
actionlint_sha256=023070a287cd8cccd71515fedc843f1985bf96c436b7effaecce67290e7e0757
hadolint_version=2.12.0
hadolint_sha256=56de6d5e5ec427e17b74fa48d51271c7fc0d61244bf5c90e828aab8362d55010
shfmt_version=3.10.0
shfmt_sha256=1f57a384d59542f8fac5f503da1f3ea44242f46dff969569e80b524d64b71dbc

sudo apt-get update
sudo apt-get install -y shellcheck
python3 -m pip install --break-system-packages "yamllint==1.37.1"

shfmt_bin="shfmt_v${shfmt_version}_linux_amd64"
curl -sSfL "https://github.com/mvdan/sh/releases/download/v${shfmt_version}/${shfmt_bin}" -o "$shfmt_bin"
echo "$shfmt_sha256  $shfmt_bin" | sha256sum -c -
sudo install -m 0755 "$shfmt_bin" /usr/local/bin/shfmt

curl -sSfL "https://github.com/hadolint/hadolint/releases/download/v${hadolint_version}/hadolint-Linux-x86_64" -o hadolint
echo "$hadolint_sha256  hadolint" | sha256sum -c -
sudo install -m 0755 hadolint /usr/local/bin/hadolint

actionlint_archive="actionlint_${actionlint_version}_linux_amd64.tar.gz"
curl -sSfL "https://github.com/rhysd/actionlint/releases/download/v${actionlint_version}/${actionlint_archive}" -o "$actionlint_archive"
echo "$actionlint_sha256  $actionlint_archive" | sha256sum -c -
tar -xzf "$actionlint_archive" actionlint
sudo install -m 0755 actionlint /usr/local/bin/actionlint
