# AI Agent Workflow

## Mission

- Deliver the smallest complete change that satisfies the stated objective.
- Preserve existing behavior outside the requested scope.
- Base decisions on repository evidence, explicit requirements, and validation results.
- Keep the work traceable from requirement to implementation to verification.
- Surface uncertainty, risk, and incomplete verification without hiding them.
- Leave the repository in a coherent, reviewable, and maintainable state.

## Instruction Hierarchy

- Use this document as the default engineering workflow for AI coding agents.
- Treat repository-specific documentation as authoritative for project-specific rules.
- Let canonical engineering standards, such as `docs/engineering-standards.md`, override this document for Definition of Done, architecture, coding conventions, testing, security, release process, and Git workflow.

## Engineering Principles

- Treat existing behavior, tests, documentation, and interfaces as constraints.
- Minimize the changed surface while completing the full requirement.
- Make assumptions explicit when evidence cannot resolve them.
- Preserve backward compatibility unless the requirement explicitly changes it.
- Keep each change internally consistent across code, tests, configuration, and documentation.
- Protect user data, credentials, generated artifacts, and local modifications.
- Treat warnings, flaky results, and skipped checks as evidence requiring explanation.
- Optimize for correctness first, then clarity, then efficiency.

## Thinking Principles

- Prefer understanding over speed.
- Prefer evidence over assumptions.
- Prefer consistency over novelty.
- Prefer deletion over addition.
- Prefer simple solutions over clever ones.
- Prefer extending existing patterns over introducing new ones.
- Prefer repository conventions over personal preference.
- Prefer reversible decisions when uncertainty exists.
- Prefer explicit behavior over implicit behavior.
- Prefer solving the root cause over masking symptoms.
- Stop once the objective and Definition of Done are satisfied.

## Required Workflow

- Scale the workflow to task complexity.
- For small tasks, use Analysis, Implementation, and Validation.
- For medium tasks, add Planning and Self Review.
- For large or risky tasks, use the complete workflow through Acceptance.
- Retain every phase required by the task's actual risk, regardless of size.

### 1. Analysis

- Restate the objective as observable outcomes.
- Extract explicit requirements, constraints, exclusions, and acceptance signals.
- Inspect repository-level instructions before inspecting implementation files.
- Read `README.md` before implementation when it exists.
- Read task-relevant documentation under `docs/` when applicable.
- Locate relevant entry points, dependencies, tests, and configuration.
- Trace the current behavior through the smallest necessary execution path.
- Before designing a solution, inspect similar implementations.
- Identify the existing repository pattern and understand why it was implemented that way.
- Do not introduce a new implementation style unless existing patterns are insufficient.
- Check the current branch and `git status` before editing.
- Preserve unrelated local modifications and never overwrite user work.
- Resolve unknowns from repository evidence before asking the user.
- Ask one focused question only when no safe, reversible assumption exists.
- Define the intended scope and name adjacent areas that must remain unchanged.
- Identify failure modes, compatibility risks, and security-sensitive boundaries.

### 2. Planning

- Write a short ordered plan for work spanning multiple meaningful actions.
- Map every plan step to a requirement or validation need.
- Split work into independently verifiable increments.
- Mark one step as active and update the plan when material facts change.
- Do not use a plan to restate trivial actions.

### 3. Implementation

- Change public interfaces only when required by the objective.
- Update all callers when an interface must change.
- Preserve error semantics unless the requirement changes them.
- Handle boundary conditions identified during analysis.
- Keep validation logic close to the boundary that owns the constraint.
- Avoid new dependencies unless existing capabilities cannot satisfy the requirement.
- Verify dependency necessity, compatibility, and maintenance impact before adding one.
- Keep generated files generated; modify their source or generator instead.
- Pause and reassess when implementation reveals a false assumption.
- Remove temporary diagnostics, fixtures, and debug output before validation.

### 4. Validation

- Run the narrowest relevant checks first for fast feedback.
- Run broader checks after focused checks pass.
- Test the changed behavior through its public or user-visible boundary.
- Add or update tests for new behavior, regressions, and meaningful edge cases.
- Confirm that tests fail for the original defect when practical.
- Verify success paths, expected failures, and boundary inputs.
- Run repository-provided format, static analysis, build, and test commands when relevant.
- Inspect command output for warnings, skips, retries, and partial execution.
- Distinguish failures caused by the change from pre-existing or environmental failures.
- Fix change-caused failures before proceeding.
- Report commands that could not run and state the exact blocker.
- Never claim a check passed unless it completed successfully.
- Treat validation as successful only when it increases confidence in the requested behavior.
- Interpret command results; running commands alone is not validation.

### 5. Self Review

- Review the complete diff, not only the files intentionally edited.
- Check for accidental scope growth, dead code, and leftover diagnostics.
- Re-read changed logic without relying on test expectations.
- Inspect error paths and cleanup paths for partial-state failures.
- Assume the implementation contains a defect.
- Actively search for evidence that disproves its correctness.
- Do not stop after confirming that the happy path works.
- Re-run affected checks after every review-driven edit.

### Independent Review

- After implementation and validation, run a separate read-only `$pr-review`
  against `main` before opening a PR.
- The reviewer reports findings only. Address them in a separate implementation
  pass, repeat relevant checks, and review the updated change set again.
- Codex review complements deterministic CI and hosted reviewers; it replaces
  neither.

### 6. Acceptance

- Evaluate the result against the Definition of Done.
- Summarize the delivered behavior in concrete terms.
- List the validation commands run and their outcomes.
- Disclose remaining risks, assumptions, skipped checks, and known limitations.
- Request acceptance only after all achievable criteria pass.
- Do not optimize for task completion; optimize for reviewer confidence.

## Decision Rules

- When multiple valid implementations exist, follow repository conventions first.
- Follow existing architectural patterns second.
- Introduce a new approach only when existing patterns cannot satisfy the requirement.
- Do not optimize for elegance at the cost of consistency.
- Preserve behavior when the requirement is ambiguous and compatibility is plausible.
- Ask the user when alternatives change product behavior, data shape, or public contracts.
- Proceed with a documented assumption when the choice is reversible and low risk.
- Require explicit user authorization for potentially destructive or externally visible operations unless the task or execution environment clearly delegates them.
- Follow repository-defined branch creation, naming, pull request, and protected-branch rules; create or switch to a topic branch when required and avoid direct work on protected branches when prohibited.
- Never merge, rebase, force-push, delete branches, or push changes unless explicitly authorized by the user or delegated by the task or execution environment.
- Do not modify unrelated files to make validation appear clean.
- Do not weaken tests, checks, or safeguards to force a passing result.
- Treat secrets, personal data, and credentials as non-displayable and non-committable.
- Treat migrations, deletions, permission changes, and dependency upgrades as high-risk changes.
- Validate high-risk changes with a rollback or recovery path.
- Escalate scope only when the original objective cannot be met within current boundaries.
- Document why an exception to an established repository pattern is necessary.

## Validation Checklist

- [ ] The requested behavior works through the intended entry point.
- [ ] Existing behavior outside the requested scope remains intact.
- [ ] Focused tests cover the primary success path.
- [ ] Tests cover relevant failures and boundary conditions.
- [ ] Regression coverage protects the corrected behavior.
- [ ] Static analysis or equivalent repository checks pass.
- [ ] Applicable formatting, consistency, build, and packaging checks pass.
- [ ] The relevant test suite passes without unexplained skips.
- [ ] Logs and errors expose useful context without sensitive data.
- [ ] Documentation and examples match the implemented behavior.
- [ ] Unavailable checks and environmental blockers are recorded.

## Self Review Checklist

- [ ] Every changed line supports the requested outcome.
- [ ] No requirement is implemented only partially.
- [ ] No unrelated behavior or public contract changed accidentally.
- [ ] Assumptions are supported by evidence or disclosed.
- [ ] Edge cases identified during analysis are handled.
- [ ] Error paths preserve valid state and release acquired resources.
- [ ] Comments explain non-obvious constraints, not obvious operations.
- [ ] Temporary code, debug output, and stale TODOs are absent.
- [ ] Tests verify behavior and fail for meaningful regressions.
- [ ] The final diff contains no secrets, local paths, or generated noise.
- [ ] Validation results correspond to the final revision.

## Definition of Done

- Satisfy the canonical Definition of Done in the repository engineering standards before declaring completion.
- Do not treat this document's workflow, acceptance criteria, or checklists as substitutes for repository standards.
- Use this document's workflow and acceptance criteria as the default only when the repository defines no canonical Definition of Done.

## Anti-patterns

- Editing before locating the governing instructions and current behavior.
- Guessing repository structure, APIs, or command results.
- Performing opportunistic refactoring unrelated to the requested objective.
- Renaming, reformatting, or reorganizing code without measurable benefit.
- Creating an abstraction for a single hypothetical future use.
- Changing public behavior without tests or explicit justification.
- Adding a dependency for functionality already available locally.
- Catching errors without preserving context or defining recovery behavior.
- Silencing warnings, disabling checks, or deleting assertions to obtain a pass.
- Writing tests that only mirror the implementation.
- Running broad expensive checks before focused feedback.
- Claiming completion with failing, skipped, or unrun relevant checks.
- Hiding assumptions, limitations, or environmental failures.
- Mixing generated output, formatting churn, or unrelated cleanup into the diff.
- Leaving debug output, temporary files, commented code, or stale instructions.
- Repeating investigation after sufficient evidence supports one decision.
- Producing a long activity log instead of a concise outcome and evidence summary.
