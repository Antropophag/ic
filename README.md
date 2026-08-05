# Реестр заявок на испытания

Корпоративное приложение на Yii 2, Vue 3, MariaDB и Nginx. HTTP-входной
контейнер `frontend` раздаёт собранный Vue и проксирует `/api` и `/health` в
backend. Административная панель включает управление пользователями и ролями,
read-only журнал действий и журнал доставки уведомлений.

## Локальная разработка

Нужны Git, Node.js 22 и Docker Compose либо Podman Compose. При использовании
Docker требуются Compose 2.20.2+ и Engine 25.0+ (API 1.44+): frontend healthcheck
использует `start_interval`.

```sh
make init
make dev-up
```

`make init` создаёт `.env.dev` из `.env.dev.example` и подключает Git hooks.
`make dev-up` работает только с project `ic-dev`, файлами `compose.yaml` и
`compose.dev.yaml`, конфигурацией `.env.dev`. Команда собирает development
frontend и backend, применяет миграции, выполняет идемпотентный seed и выводит
URL портала. Повторный запуск сохраняет БД и документы.

`.env.dev` задаёт `COMPOSE_PROJECT_NAME=ic-dev`, автоматически подключает
development overlay через `COMPOSE_FILE` и публикует frontend на `8081`.
Базовый Compose можно запустить напрямую:

```sh
docker compose --env-file .env.dev up -d
```

Для lifecycle с build, migrations и seed используйте `make dev-up`.

Development-БД обязана оканчиваться на `_dev`: seed проверяет фактически
подключённую БД до записи. Переключатель пользователя и dev API подключаются
только development-сборкой и файлами `deployment/dev/*`; production bundle их
не содержит.

```sh
make dev-down   # остановить, данные сохранить
make dev-restart
make dev-status
make dev-reset  # удалить dev volumes, создать БД и seed заново
make dev-logs                     # потоковые логи всех сервисов
make dev-logs SERVICE=backend     # потоковые логи одного сервиса
make env-status
```

## Промышленная эксплуатация

Production использует только project `ic-prod`, `compose.yaml` и `.env.prod`.

```sh
cp .env.example .env.prod
# заполнить production-настройки и секреты
make prod-up
```

Подготовленный deployment можно запустить напрямую:

```sh
docker compose --env-file .env.prod up -d
```

Штатный `make prod-up` дополнительно гарантирует build, migrations и
provisioning.

`make prod-up` сначала явно собирает актуальные backend, scheduler и frontend,
а затем запускает только эти собранные images. После `git pull` достаточно
повторить `make prod-up`: новая серверная часть не может остаться со старым
frontend. Команда применяет миграции и выполняет administrator/break-glass
provisioning. Данные в named volumes сохраняются.

```sh
make prod-down  # остановить, данные сохранить
make prod-restart
make prod-status
make prod-logs
make prod-logs SERVICE=backend
make prod-reset # с подтверждением удалить production volumes и поднять чистую БД
```

Lifecycle-команды показывают компактный статус сервисов и опубликованные URL.
Compose output полностью выводится при ошибке; timeout готовности дополнительно
показывает последние логи проблемного сервиса. Цвет включается только в
интерактивном терминале. Для отключения используйте `NO_COLOR=1`, для
принудительного включения — `FORCE_COLOR=1`.
Frontend healthcheck обращается через nginx к `/health/ready`, поэтому отражает
готовность всего приложения: reverse proxy, backend, БД и document storage.

Bootstrap создаёт локальные профили-заглушки для логинов из
`BOOTSTRAP_ADMIN_AD_LOGINS` и назначает им `employee` и `administrator`.
Семантика add-only: удаление логина из env не отзывает роли, отключённый профиль
не активируется. Break-glass включается только одновременной настройкой
`BREAK_GLASS_LOGIN` и `BREAK_GLASS_PASSWORD_HASH`; это не LDAP fallback.
Эксплуатационные детали приведены в
[DevOps-контракте](docs/devops-contract.md#аварийный-break-glass-вход).

## Тесты

Test — внутреннее одноразовое окружение `ic-test`, использующее только
`compose.test.yaml` и tracked `.env.test`. Его не нужно поднимать вручную:

```sh
make check
make e2e
make coverage
```

- `make check` — lint, OpenAPI, PHPStan, Unit, Vitest, dependency audit,
  production build и repository contracts;
- `make e2e` — build test images, clean migrations/seed, Integration,
  Playwright, LDAP/SMTP/MariaDB recovery и SIGTERM scheduler, затем полное
  удаление test containers/network/volumes;
- `make coverage` — backend/frontend coverage.

Внутренние `_test-*` существуют только для CI cleanup и orchestration
`make e2e`; это не пользовательский lifecycle. Test images сохраняют
исторические имена `shlz-test-registry-test-*`: это image names, а не Compose
project name.

## Жизненный цикл данных

| Команда | Containers | Named volumes | Build/migrations/seed |
|---|---|---|---|
| `make dev-up` | создаёт/пересоздаёт | сохраняет | build, migrate, dev seed |
| `make dev-down` | удаляет | сохраняет | нет |
| `make dev-reset` | удаляет и создаёт | **удаляет** | полный `dev-up` |
| `make prod-up` | создаёт/пересоздаёт | сохраняет | полный build, migrate, provisioning |
| `make prod-down` | удаляет | сохраняет | нет |
| `make prod-reset` | удаляет и создаёт | **удаляет после подтверждения** | полный `prod-up` |
| `make e2e` | создаёт на время теста | всегда удаляет | автоматический test lifecycle |

Команды `make up`, `make down` и `make logs` намеренно завершаются ошибкой и
подсказывают выбрать `dev-*` либо `prod-*`. Это исключает управление не тем
project из одной рабочей папки.

`make env-status` не выводит environment целиком или секреты. Он показывает
активные локальные окружения, project name, Compose-файлы, имя БД, volumes и
images. Если одновременно запущены development и production, будут показаны
оба. Шаблоны публикуют production на `8080`, development на `8081`; любое
значение можно переопределить через `FRONTEND_PORT`. Backend, MariaDB и
scheduler доступны только во внутренней Compose network. Swagger UI использует
тот же frontend port и отдельно не публикуется.

## Миграция с предыдущих версий

Если `.env.dev` или `.env.prod` созданы из старых шаблонов, перенесите в них
Compose metadata из актуальных `.env.dev.example` или `.env.example`, не
заменяя секреты. В development согласуйте `APP_PUBLIC_URL` с выбранным
`FRONTEND_PORT`.

Новые project names не подхватывают старые named volumes автоматически.
Существующие production данные `shlz-test-registry_*` и development данные
`shlz-test-registry-dev_*` не удаляются. До первого `prod-up`/`dev-up`
остановите старый project без `--volumes`, перенесите или восстановите данные и
только затем используйте новый lifecycle. Точные безопасные команды и rollback
описаны в [DevOps-контракте](docs/devops-contract.md#миграция-старых-compose-project-names).

## Команды

```text
make help
make doctor
make init
make env-status

make dev-up
make dev-down
make dev-restart
make dev-status
make dev-reset
make dev-logs

make prod-up
make prod-down
make prod-restart
make prod-status
make prod-reset
make prod-logs

make check
make e2e
make coverage
make openapi-validate
make schema-diagram
```

Для Podman передайте provider обычным способом, например:

```sh
COMPOSE="podman-compose --in-pod false" CONTAINER_ENGINE=podman make e2e
```

OpenAPI-контракт проверяется offline командой `make openapi-validate`. В
запущенном development deployment спецификация доступна по
`http://localhost:8081/api/openapi.yaml`, Swagger UI — по
`http://localhost:8081/api/docs/` (с учётом `FRONTEND_PORT`).

Полный test deployment с Samba AD требует rootful Podman. Подробности:
[стратегия тестирования](docs/test-strategy.md), [API](docs/api.md),
[эксплуатационный контракт](docs/devops-contract.md).
