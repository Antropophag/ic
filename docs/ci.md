# Непрерывная интеграция

GitHub Actions и GitLab CI используют Makefile как источник истины.

| Логическая проверка | GitHub job | GitLab job | Команда |
|---|---|---|---|
| lint, OpenAPI, static analysis, Unit, Vitest, build | `check` | `check` | `make check` |
| coverage thresholds | `coverage` | `coverage` | `make coverage` |
| Docker test deployment suite | `e2e` | `e2e` | `make e2e` |
| Semgrep | `analyze` | `analyze` | `semgrep scan` |
| secrets | `secrets` | `secrets` | Gitleaks |

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
