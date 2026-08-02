# Continuous integration

GitHub Actions и GitLab CI используют Makefile как источник истины.

| Логическая проверка | GitHub job | GitLab job | Команда |
|---|---|---|---|
| lint, static analysis, Unit, Vitest, build | `check` | `check` | `make check` |
| coverage thresholds | `coverage` | `coverage` | `make coverage` |
| Docker production-like suite | `e2e` | `e2e` | `make e2e` |
| Podman production-like suite | `podman` | `podman` | `make e2e` с Podman |
| Semgrep | `analyze` | `analyze` | `semgrep scan` |
| secrets | `secrets` | `secrets` | Gitleaks |

Оба E2E job обязательны и не используют `allow_failure`. Они поднимают один
test deployment, выполняют Integration, Playwright, LDAP/SMTP contracts,
MariaDB reconnect и SIGTERM scheduler, затем всегда запускают teardown.
Playwright report сохраняется при падении.

GitHub Podman job запускает `podman-compose` и Podman через `sudo`. Samba AD
устанавливает ACL при provision, поэтому полный стенд требует rootful Podman.
GitLab Podman job выполняется от root и требует runner, на котором разрешён
container runtime; это инфраструктурное требование runner, а не режим
приложения.
