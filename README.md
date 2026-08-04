# Реестр заявок на испытания

Корпоративное приложение на Yii 2, Vue 3, MariaDB и Nginx. HTTP-входной
контейнер называется `frontend`: он раздаёт собранный Vue и проксирует API в
тот же backend, который используется во всех deployment-окружениях.

Административная панель включает управление пользователями и ролями, а также
read-only журналы действий и доставки уведомлений с серверной пагинацией.
Тело писем и полный audit payload в браузер не передаются.

## Быстрый старт разработки

Нужны Git, Node.js 22 и Docker Compose либо Podman Compose.

```sh
make init
make dev-up
```

`make init` создаёт `.env.dev` из безопасного шаблона и подключает Git hooks.
`make dev-up` собирает стек, применяет миграции, идемпотентно создаёт обычные
записи пользователей и выводит URL `http://localhost:8080`. Development-БД
обязана оканчиваться на `_dev`: seed проверяет фактически подключённую БД до
любой записи и отказывается работать с production/test именами.

Development-переключатель пользователя появляется в правом нижнем углу.
Он показывает имя, должность и роли; выбор сохраняется в `localStorage`.
Инструмент инициализируется до запуска приложения только development-сборкой
образа `frontend`; production bundle не содержит его код. Backend-маршруты
переключателя также подключаются только файлом `deployment/dev/web.php`,
смонтированным `compose.dev.yaml`.

## Окружения

| Deployment | Compose | Конфигурация | Compose project | Назначение |
|---|---|---|---|---|
| production-like | `compose.yaml` | `.env` из `.env.example` | `shlz-test-registry` | эксплуатация и локальная проверка production-сборки |
| development | `compose.yaml` + `compose.dev.yaml` | `.env.dev` | `shlz-test-registry-dev` | разработка, seed и физически подключённые dev-tools |
| test | `compose.test.yaml` | `.env.test` | `ic-test` | единый стенд Integration, Playwright, LDAP, SMTP и runtime contracts |

Приложение не выбирает режим через специальный runtime-флаг. Различия задаёт deployment:
образом, Compose-файлом, env-конфигурацией внешних сервисов и смонтированными
конфигурационными фрагментами.

Production-like запуск:

```sh
cp .env.example .env
# заполнить секреты
make up
```

`make up` применяет миграции и идемпотентно назначает роль администратора
AD-пользователям из `BOOTSTRAP_ADMIN_AD_LOGINS`. Для ещё не входившего
пользователя создаётся локальный профиль-заглушка; первый успешный LDAP-вход
обновит его данными AD, не меняя назначенные роли. Список имеет add-only
семантику: удаление логина из `.env` само по себе не отзывает ранее назначенную
роль. Отключённый локальный профиль команда не активирует. Bootstrap не
проверяет AD и не создаёт учётные записи в AD: существование и профиль
подтверждаются обычным LDAP-входом. Отсутствующая, пустая или состоящая только
из пробелов переменная пропускает bootstrap, только если в локальной БД уже есть
активный пользователь с ролью `administrator`; иначе deployment завершается
ошибкой. В непустом списке каждый элемент после разделения запятыми обязан быть
непустым. Ошибка любого элемента откатывает весь список. При каждом
`make up` сначала запускаются MariaDB и backend, затем выполняются миграции и
bootstrap обычных и, когда механизм включён, аварийной административных
identity, и только после их успеха запускаются frontend и scheduler.

Для осознанного аварийного доступа при недоступности LDAP существует одна
локальная техническая identity с ролью `administrator`. Механизм выключен, пока
одновременно не заданы `BREAK_GLASS_LOGIN` и `BREAK_GLASS_PASSWORD_HASH`; пароль
в БД и репозитории не хранится. Это не автоматический fallback: любой логин,
кроме точного настроенного значения, продолжает проходить только через LDAP.
Порядок включения, проверки и ротации приведён в
[эксплуатационном контракте](docs/devops-contract.md#аварийный-break-glass-вход).

## Команды

```text
make help
make doctor
make init

Production-like:
make up
make down
make logs

Development:
make dev-up
make dev-down
make dev-logs

Quality:
make check
make test
make e2e
make coverage
make schema-diagram
```

- `make check` — lint, PHPStan, unit, Vitest, dependency audit, production build
  и проверки репозитория без полного стенда.
- `make test` — `check`, затем весь единый test deployment.
- `make e2e` — однократная сборка application images, запуск и reset test
  deployment, backend Integration, Playwright, LDAP, SMTP, MariaDB reconnect и
  graceful shutdown scheduler.
- `make coverage` — только backend/frontend coverage.
- `make up`, `make down` и `make logs` всегда управляют только production-like
  deployment (`compose.yaml`, `.env`, project `shlz-test-registry`).
- `make dev-up`, `make dev-down` и `make dev-logs` всегда управляют только
  development deployment (`compose.yaml + compose.dev.yaml`, `.env.dev`,
  project `shlz-test-registry-dev`).

После изменения `.env` повторите `make up`, после изменения `.env.dev` —
`make dev-up`: команды пересоздают контейнеры и передают им актуальное
environment. Простой restart уже созданного контейнера env-файл не перечитывает.
Production-like и development изолированы Compose project names, но по
умолчанию публикуют один порт `8080`, поэтому для одновременного запуска одному
из них нужно задать другой `FRONTEND_PORT` в соответствующем env-файле.

Внутренние стадии build, start, reset и teardown test-стенда не являются
публичными Make-командами. Reset требует уже собранный и поднятый deployment и
никогда не собирает images или dependencies. При сбое `make e2e` состояние и
логи контейнеров сохраняются в `frontend/playwright-report/deployment`, после
чего containers, network и test volumes удаляются.

Пока стенд поднят, reset можно повторять без пересборки:

```sh
COMPOSE="docker compose" CONTAINER_ENGINE=docker \
  TEST_PROJECT=ic-test TEST_ENV_FILE=.env.test \
  sh scripts/test-env.sh reset
```

Для Podman scripts получают выбранные команды через `COMPOSE` и
`CONTAINER_ENGINE`. Переносимый вариант с самостоятельным provider:

```sh
COMPOSE="podman-compose --in-pod false" CONTAINER_ENGINE=podman make e2e
```

Форма `podman compose` тоже поддерживается, но её аргументы зависят от
настроенного внешнего Compose provider; `--in-pod` относится именно к
`podman-compose`, а не к Docker Compose provider. Полный стенд с Samba AD
требует rootful Podman: rootless provision не может установить необходимые
ACL. В этом случае обе команды передаются с `sudo`.

Test deployment управляется только через `make e2e`; production-like и
development targets не вызывают его внутренние build/start/reset/teardown
стадии.

## Test deployment

Сервисы: `frontend`, `backend`, `scheduler`, MariaDB 11.4, Samba AD и Mailpit.
Портал доступен на `http://localhost:18080`, Mailpit —
`http://localhost:18026`. Все backend Integration тесты исполняются внутри
этого же `backend`, после тех же миграций и seed, что используются Playwright.

Домен AD: `IC.TEST`, пароль тестовых учётных записей:
`TestPassword1!`. Основные логины: `initiator`, `ic_manager`,
`laboratory_manager`, `executor`, `expert`, `security_officer`,
`administrator`, `employee_without_roles`, `disabled_user`.

Подробности: [стратегия тестирования](docs/test-strategy.md),
[API](docs/api.md), [эксплуатационный контракт](docs/devops-contract.md).
