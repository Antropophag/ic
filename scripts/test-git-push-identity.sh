#!/usr/bin/env sh
set -eu

project_root=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
fixture_root=$(mktemp -d)
global_config=$(mktemp)
cleanup() { rm -rf "$fixture_root" "$global_config"; }
trap cleanup EXIT HUP INT TERM

# Git exports repository-local GIT_* variables to hooks. Without clearing them,
# the nested fixture can accidentally address and reconfigure the real repository.
local_git_env=$(git rev-parse --local-env-vars)
# shellcheck disable=SC2086
unset $local_git_env

git -C "$fixture_root" init -q
git -C "$fixture_root" config user.name 'GitHub User'
git -C "$fixture_root" config user.email 'github@example.test'
git -C "$fixture_root" config remote.origin.pushIdentityName 'GitHub User'
git -C "$fixture_root" config remote.origin.pushIdentityEmail 'github@example.test'
git -C "$fixture_root" config remote.gitlab.pushIdentityName 'GitLab User'
git -C "$fixture_root" config remote.gitlab.pushIdentityEmail 'gitlab@example.test'

(cd "$fixture_root" && sh "$project_root/scripts/check-git-push-identity.sh" origin)

if (cd "$fixture_root" && sh "$project_root/scripts/check-git-push-identity.sh" gitlab) >/dev/null 2>&1; then
  echo 'Identity check accepted the GitHub identity for GitLab.' >&2
  exit 1
fi

git -C "$fixture_root" config user.name 'GitLab User'
git -C "$fixture_root" config user.email 'gitlab@example.test'
(cd "$fixture_root" && sh "$project_root/scripts/check-git-push-identity.sh" gitlab)

git -C "$fixture_root" config remote.incomplete.pushIdentityName 'Incomplete User'
if (cd "$fixture_root" && sh "$project_root/scripts/check-git-push-identity.sh" incomplete) >/dev/null 2>&1; then
  echo 'Identity check accepted an incomplete remote identity.' >&2
  exit 1
fi

(cd "$fixture_root" && sh "$project_root/scripts/check-git-push-identity.sh" unconfigured)

git config --file "$global_config" remote.global.pushIdentityName 'Global User'
git config --file "$global_config" remote.global.pushIdentityEmail 'global@example.test'
(cd "$fixture_root" && GIT_CONFIG_GLOBAL="$global_config" \
  sh "$project_root/scripts/check-git-push-identity.sh" global)

linked_repo="$fixture_root/linked-repo"
linked_worktree="$fixture_root/linked-worktree"
git init -q "$linked_repo"
git -C "$linked_repo" config user.name 'Shared User'
git -C "$linked_repo" config user.email 'shared@example.test'
git -C "$linked_repo" commit --allow-empty -qm initial
git -C "$linked_repo" worktree add -q -b linked-worktree "$linked_worktree"
git -C "$linked_repo" config extensions.worktreeConfig true
git -C "$linked_repo" config --worktree user.name 'Main Worktree User'
git -C "$linked_repo" config --worktree user.email 'main@example.test'
git -C "$linked_worktree" config --worktree user.name 'Linked Worktree User'
git -C "$linked_worktree" config --worktree user.email 'linked@example.test'

test "$(git -C "$linked_repo" config user.email)" = 'main@example.test'
test "$(git -C "$linked_worktree" config user.email)" = 'linked@example.test'

echo 'Git push identity contracts passed'
