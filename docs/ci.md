# Непрерывная интеграция

GitHub Actions и GitLab CI используют Makefile как источник истины.

| Логическая проверка | GitHub job | GitLab job | Команда |
|---|---|---|---|
| lint, OpenAPI, static analysis, Unit, Vitest, build | `check` | `check` | `make check` |
| coverage thresholds | `coverage` | `coverage` | `make coverage` |
| Docker test deployment suite | `e2e` | `e2e` | `make e2e` |
| Semgrep | `analyze` | `analyze` | `semgrep scan` |
| secrets | `secrets` | `secrets` | Gitleaks |
| SonarQube Cloud | `sonar` | — | импорт существующих coverage reports и анализ new code |

E2E job обязателен и не использует `allow_failure`. Он поднимает один test
deployment, выполняет Integration, Playwright, LDAP/SMTP contracts, MariaDB
reconnect и SIGTERM scheduler, затем всегда запускает teardown. Playwright
report вместе с container status и последними логами сохраняется при падении.
Обе CI-системы выполняют один и тот же `make e2e`; hosted Docker/BuildKit cache
между запусками отдельно не настраивается.

Публичные URL test deployment вычисляются через `compose port`. GitLab DinD
задаёт только `COMPOSE_PUBLISHED_HOST=docker`; номера опубликованных портов не
дублируются в CI-конфигурации.

Podman Compose поддерживается Makefile и scripts для локального или
эксплуатационного запуска, но отдельный CI job не выполняется: он дублировал
полный test deployment suite без дополнительного покрытия приложения.

OpenAPI отдельно запускается командой `make openapi-validate` и также входит в
`make check`. Проверка работает по lock-файлу без сетевых запросов: валидирует
обязательные поля OpenAPI, разрешает `$ref` и проверяет инфраструктурный срез,
security/idempotency и локальную конфигурацию Swagger UI.

## SonarQube Cloud

GitHub job `sonar` запускается только после deterministic jobs и анализирует
`main` и pull request через проект `Antropophag_ic`. Анализ выполняется в CI;
Automatic Analysis для проекта должен быть выключен, поскольку эти режимы нельзя
использовать одновременно. Токен хранится только в GitHub Secret `SONAR_TOKEN`.

Sonar импортирует Clover, уже создаваемый backend coverage job, и LCOV из того же
Vitest coverage run. Миграционная baseline зафиксирована на последнем проверенном
анализе `main`; для pull request new code всегда является diff относительно target
branch. Historical findings остаются baseline debt и не должны сами по себе
блокировать PR. Подробности issue и data-flow доступны по ссылке из GitHub check
в SonarQube Cloud.

Sonar дополняет, но не заменяет PHPStan, PHPCS, ESLint, Semgrep, Gitleaks,
dependency audit, тесты, historical guards, Codex review, Qodo и CodeRabbit.
Статический анализ не подтверждает бизнес-контракты, runtime concurrency,
устаревшие async responses, browser lifecycle, viewport/zoom и reduced-motion;
эти риски остаются за тестами, guards и semantic review.
