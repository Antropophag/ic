---
name: pr-review
description: Perform an independent, read-only, adversarial semantic code review of a branch, commit, pull/merge request, or uncommitted changes against main. Use after implementation and before merge, or when the user invokes $pr-review, asks for PR review, code review, regression review, or review of current changes. Inspect related callers, consumers, models, tests, contracts, migrations, and neighboring implementations; report only actionable findings with concrete failure scenarios and do not modify files.
---

# Independent PR review

Act only as reviewer. Do not edit files, apply fixes, stage changes, commit, or
push. Keep review independent from implementation even if the same conversation
created the change.

## Establish the review scope

1. Read repository `AGENTS.md`, `README.md`, and task-relevant canonical docs.
2. Use the user-supplied base when present. Otherwise prefer local `main`, then
   `origin/main`; do not fetch or mutate refs unless requested.
3. For a branch review, find the merge base and inspect the complete change set:

   ```sh
   base_ref=main
   merge_base=$(git merge-base HEAD "$base_ref")
   git diff --stat "$merge_base"
   git diff --name-status "$merge_base"
   git diff --find-renames --find-copies "$merge_base"
   git status --short
   ```

   Include staged, unstaged, and untracked files. If unresolved conflicts exist,
   identify them and explain review limitations.

   <!-- review-contract:sensitive-untracked -->
   For untracked files, read every relevant non-sensitive file directly,
   including source, tests, configuration, workflows, manifests, migrations,
   fixtures, and documentation. First classify potentially sensitive paths using
   repository conventions such as `.gitignore` and Gitleaks rules. Do not open
   obvious secret-bearing files (`.env`, `.env.dev`, `.env.prod`, local
   credential/token overrides, private keys, or certificates) unless the user
   explicitly requests their review and it is safe. Read sanitized examples and
   deterministic test configuration only after repository policy confirms their
   status. Report only sensitive paths and status; never include secret values in
   review evidence or findings.
4. For a requested commit or range, inspect exactly that scope plus affected code
   around it. State the resolved base/range before findings.

## Build an impact map

Do not review the patch in isolation. For each changed behavior, trace the
smallest relevant path through:

- entry point and validation boundary;
- authorization/policy and state transition;
- persistence, transaction, migration, audit, and notification effects;
- API/OpenAPI shapes and frontend consumers;
- callers, alternate entry points, neighboring implementations, cleanup paths;
- tests that claim to protect the behavior.

Use repository documentation and history when they prove intended behavior.
Read [repository-invariants.md](references/repository-invariants.md). Do not spend
review tokens restating formatter, linter, static-analysis, dependency-audit,
build, or generic style checks already owned by CI.

## Review adversarially

Check correctness, regressions, boundary inputs, authorization/security, data and
migrations, API/frontend/backend contracts, compatibility, error handling,
concurrency and partial failure, test validity, architecture, duplication, and
scope creep.

For every suspected defect:

1. Construct a concrete reachable scenario with input/state, execution path, and
   incorrect observable result.
2. Verify the premise in existing code, contract, test, or documented invariant.
3. Check whether another layer already prevents the failure.
4. Report only when changed code introduces or exposes it and a focused fix is
   possible.

Do not report speculative hardening, preferences, unrelated pre-existing defects,
or missing tests without naming the behavior that can regress unnoticed.

## Semantic passes

Apply only passes relevant to changed behavior, but make selected passes explicit.

### Async and lifecycle

Trace destruction/unmount, repeated calls, out-of-order completion, identity or
selection changes while in flight, and late success/error/finally updates. Verify
cleanup for listeners, timers, observers, connections, streams, and abort handles.

### Concurrency and state

For each read → interaction/wait → write sequence, identify when authorization,
identity, status, assignment, and version were read and whether they can become
stale. Check double submit, retry/idempotency, optimistic locking, transactions,
and partial state after secondary-operation failure.

### Regression-test strength

For each regression test, name the original failure and mentally restore the
buggy behavior. Report a gap when the fixture misses the collision/boundary/race,
the assertion observes the wrong layer, or a mock bypasses the integration.

### External input and filesystem

At import, archive, upload, or workspace boundaries, trace provenance and bind
artifacts to the intended request/snapshot. Check bounds before persistence and
distinguish regular files from symlinks, directories, FIFOs, and devices before
opening or hashing. Verify traversal, size, cleanup, retry, and resume behavior.

### Contracts and runtime edges

Compare exact backend responses, OpenAPI schema and `$ref`, checker assertions,
client serialization, and UI consumers. Exercise null, missing, empty,
whitespace-only, wrong-type, oversized, and old-client values. For browser changes,
trace viewport/zoom, runtime accessibility preferences, unavailable APIs,
navigation variants, and hot-path allocation/layout work.

## Report

List findings first, ordered by severity:

- `P0`: release-blocking data loss or broad security compromise;
- `P1`: likely correctness, authorization, migration, or compatibility failure;
- `P2`: narrower regression or material test gap;
- `P3`: low-impact actionable defect.

Each finding includes a concise title, exact file/line, concrete failure scenario,
and supporting evidence. Keep remediation to a direction. If no qualifying
findings exist, say so. Then state reviewed scope, validation, and residual risks.
Never fix findings in the review pass.
