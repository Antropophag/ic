#!/bin/sh
set -eu

repository_root=${REVIEW_CONTRACT_ROOT:-$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)}
cd "$repository_root"

required_files='
.agents/skills/pr-review/SKILL.md
.agents/skills/pr-review/references/repository-invariants.md
.agents/skills/pr-review/agents/openai.yaml
.pr_agent.toml
docs/ai-review.md
docs/engineering-standards.md
.github/pull_request_template.md
'
printf '%s\n' "$required_files" | while IFS= read -r path; do
  [ -z "$path" ] || [ -f "$path" ] || {
    echo "Missing review contract file: $path" >&2
    exit 1
  }
done

grep -Eq '^handle_push_trigger[[:space:]]*=[[:space:]]*true$' .pr_agent.toml
grep -Eq '^push_commands[[:space:]]*=[[:space:]]*\["/review"\]$' .pr_agent.toml
grep -Fq 'Act only as reviewer' .agents/skills/pr-review/SKILL.md
grep -Fq 'Never fix findings in the review pass' .agents/skills/pr-review/SKILL.md
grep -Fq 'review-contract:sensitive-untracked' .agents/skills/pr-review/SKILL.md

# Each invariant is checked in the policy layer that owns it. Prose around the
# stable anchors may change without weakening the contract.
# shellcheck disable=SC2016 # Literal skill name, not a shell variable.
grep -Fq '$pr-review' AGENTS.md
grep -Fq 'review-contract:final-change-set' .github/pull_request_template.md
grep -Fq 'review-contract:final-change-set' docs/ai-review.md
grep -Fq 'Code Review by Qodo' .github/pull_request_template.md
grep -Fq 'CodeRabbit' docs/ai-review.md
grep -Fq 'make check' docs/ai-review.md
grep -Fq 'Sonar' docs/ai-review.md

echo 'Review workflow contracts passed'
