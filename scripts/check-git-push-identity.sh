#!/usr/bin/env sh
set -eu

remote_name=${1:-}
if [ -z "$remote_name" ]; then
  echo 'Не указано имя Git remote для проверки identity.' >&2
  exit 2
fi

expected_name=$(git config --get "remote.$remote_name.pushIdentityName" || true)
expected_email=$(git config --get "remote.$remote_name.pushIdentityEmail" || true)

if [ -z "$expected_name" ] && [ -z "$expected_email" ]; then
  exit 0
fi

if [ -z "$expected_name" ] || [ -z "$expected_email" ]; then
  echo "Identity для remote '$remote_name' настроена не полностью." >&2
  echo "Задайте remote.$remote_name.pushIdentityName и remote.$remote_name.pushIdentityEmail." >&2
  exit 2
fi

actual_name=$(git config --get user.name || true)
actual_email=$(git config --get user.email || true)

if [ "$actual_name" != "$expected_name" ] || [ "$actual_email" != "$expected_email" ]; then
  echo "Push в '$remote_name' заблокирован: текущая Git identity не соответствует remote." >&2
  echo "Ожидается: $expected_name <$expected_email>" >&2
  echo "Текущая: ${actual_name:-<не задано>} <${actual_email:-не задано}>" >&2
  echo 'Настройте identity в этом worktree:' >&2
  echo "  git config --worktree user.name '$expected_name'" >&2
  echo "  git config --worktree user.email '$expected_email'" >&2
  exit 1
fi
