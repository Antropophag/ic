# AI-review pull/merge request

## Слои качества

Основной обязательный слой — deterministic checks: `make check`, `make e2e`,
PHPStan, PHPCS, ESLint, OpenAPI validation, Semgrep, Gitleaks, dependency audit,
repository contracts, historical regression guards и SonarQube Cloud. Детали
Sonar и CI остаются в [документации CI](ci.md).

После реализации и локальных проверок разработчик запускает независимый
read-only `$pr-review` относительно `main`. Skill находится в
`.agents/skills/pr-review/`, анализирует полный change set и связанный код, но не
редактирует файлы. Подтверждённые findings передаются в отдельный implementation-
проход, после которого deterministic checks и review итогового change set
повторяются. <!-- review-contract:final-change-set -->

Codex review дополняет deterministic checks и hosted review, но не заменяет их и
сам по себе не является merge gate.

## Hosted review

Qodo запускается автоматически при открытии PR и повторно при каждом push через
`.pr_agent.toml`. Не вызывайте `/agentic_review` вручную. Итоговый `Code Review by
Qodo` должен относиться к текущему head; `PR Summary` и состояние
`working`/`busy working` не являются завершённым review.

CodeRabbit остаётся дополнительным hosted reviewer. Findings Qodo, CodeRabbit,
Codex и человека проверяются по каждому достижимому сценарию отказа и
исправляются либо аргументированно отклоняются. AI-review не заменяет
обязательный pipeline и человеческий approve.

## Historical remediation

Каждый substantive historical finding оценивается на возможность permanent
deterministic guard. Guard добавляется при значимом риске повторения и только с
RED → GREEN evidence; тест на каждый bot comment не требуется. Authoritative
registry находится в
[historical-review-coverage.md](quality/historical-review-coverage.md).

Raw reviewer corpus и benchmark artifacts хранятся вне репозитория.
