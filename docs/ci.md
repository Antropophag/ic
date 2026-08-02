# Непрерывная интеграция

GitHub Actions и GitLab CI используют Makefile как источник истины.

| Логическая проверка | GitHub job | GitLab job | Команда |
|---|---|---|---|
| lint, static analysis, Unit, Vitest, build | `check` | `check` | `make check` |
| coverage thresholds | `coverage` | `coverage` | `make coverage` |
| Docker production-like suite | `e2e` | `e2e` | `make e2e` |
| Semgrep | `analyze` | `analyze` | `semgrep scan` |
| secrets | `secrets` | `secrets` | Gitleaks |

E2E job обязателен и не использует `allow_failure`. Он поднимает один test
deployment, выполняет Integration, Playwright, LDAP/SMTP contracts, MariaDB
reconnect и SIGTERM scheduler, затем всегда запускает teardown. Playwright
report сохраняется при падении.

Podman Compose поддерживается Makefile и scripts для локального или
эксплуатационного запуска, но отдельный CI job не выполняется: он дублировал
полный production-like suite без дополнительного покрытия приложения.
