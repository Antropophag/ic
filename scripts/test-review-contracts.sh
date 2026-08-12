#!/bin/sh
set -eu

cd "$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)"
fixture_root=$(mktemp -d)
cleanup() { rm -rf "$fixture_root"; }
trap cleanup EXIT HUP INT TERM

copy_contract() {
  destination=$1
  mkdir -p "$destination/.agents/skills/pr-review/references" \
    "$destination/.agents/skills/pr-review/agents" "$destination/docs" \
    "$destination/.github"
  cp .agents/skills/pr-review/SKILL.md "$destination/.agents/skills/pr-review/SKILL.md"
  cp .agents/skills/pr-review/references/repository-invariants.md \
    "$destination/.agents/skills/pr-review/references/repository-invariants.md"
  cp .agents/skills/pr-review/agents/openai.yaml \
    "$destination/.agents/skills/pr-review/agents/openai.yaml"
  cp .pr_agent.toml AGENTS.md CLAUDE.md "$destination/"
  cp docs/ai-review.md docs/engineering-standards.md docs/ci.md "$destination/docs/"
  cp .github/pull_request_template.md "$destination/.github/"
}

positive="$fixture_root/positive"
copy_contract "$positive"
REVIEW_CONTRACT_ROOT="$positive" sh scripts/check-review-contracts.sh >/dev/null

missing_skill="$fixture_root/missing-skill"
copy_contract "$missing_skill"
rm "$missing_skill/.agents/skills/pr-review/SKILL.md"
if REVIEW_CONTRACT_ROOT="$missing_skill" sh scripts/check-review-contracts.sh >/dev/null 2>&1; then
  echo 'Review contract accepted a missing Codex skill.' >&2
  exit 1
fi

disabled_qodo="$fixture_root/disabled-qodo"
copy_contract "$disabled_qodo"
sed -i 's/handle_push_trigger = true/handle_push_trigger = false/' "$disabled_qodo/.pr_agent.toml"
if REVIEW_CONTRACT_ROOT="$disabled_qodo" sh scripts/check-review-contracts.sh >/dev/null 2>&1; then
  echo 'Review contract accepted disabled Qodo push review.' >&2
  exit 1
fi

missing_sensitive_policy="$fixture_root/missing-sensitive-policy"
copy_contract "$missing_sensitive_policy"
sed -i '/review-contract:sensitive-untracked/,/secret values in review evidence or findings/d' \
  "$missing_sensitive_policy/.agents/skills/pr-review/SKILL.md"
if REVIEW_CONTRACT_ROOT="$missing_sensitive_policy" sh scripts/check-review-contracts.sh >/dev/null 2>&1; then
  echo 'Review contract accepted a skill without sensitive-untracked protection.' >&2
  exit 1
fi

wrong_final_review_layer="$fixture_root/wrong-final-review-layer"
copy_contract "$wrong_final_review_layer"
sed -i '/review-contract:final-change-set/d' \
  "$wrong_final_review_layer/.github/pull_request_template.md"
printf '%s\n' '<!-- review-contract:final-change-set -->' \
  >>"$wrong_final_review_layer/CLAUDE.md"
if REVIEW_CONTRACT_ROOT="$wrong_final_review_layer" sh scripts/check-review-contracts.sh >/dev/null 2>&1; then
  echo 'Review contract accepted the final-review invariant only in the wrong policy layer.' >&2
  exit 1
fi

echo 'Review contract fixtures passed'
