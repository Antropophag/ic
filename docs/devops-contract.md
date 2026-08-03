# Эксплуатационный контракт

Production Compose содержит четыре сервиса: `frontend`, `backend`, `scheduler`
и `mariadb`. `frontend` принимает HTTP, раздаёт статический production bundle
и проксирует `/api` и `/health` в PHP-FPM.

`.env.example` — production template. Секреты не коммитятся. `compose.yaml`
не задаёт режим приложения: он передаёт только настройки интеграций из `.env`.

```sh
cp .env.example .env
make doctor
make up
docker compose --env-file .env -f compose.yaml logs
docker compose --env-file .env -f compose.yaml down
```

`make up` после запуска контейнеров применяет миграции и выполняет
`php yii admin/bootstrap`. Команда обеспечивает роли `employee` и
`administrator` для пользователей из `BOOTSTRAP_ADMIN_AD_LOGINS`, не хранит
пароли и не меняет LDAP-аутентификацию. Операция идемпотентна и add-only:
удаление логина из конфигурации не отзывает роль, а отключённый локальный
профиль не включается автоматически. При запуске Compose без Make эквивалентные
команды миграции и bootstrap необходимо выполнить явно.

Docker Compose и Podman Compose равноправны. Makefile определяет доступную
реализацию и экспортирует `COMPOSE`/`CONTAINER_ENGINE` в scripts. Публичные
`make logs` и `make down` однозначно относятся к development; production-like
deployment управляется явной Compose-командой выше.

Scheduler запускает `php yii notification/work`, использует тот же backend
image и завершается по SIGTERM с grace period 15 секунд. Разовая ручная
обработка:

```sh
docker compose --env-file .env -f compose.yaml exec backend php yii notification/send
sudo podman-compose --in-pod false --env-file .env -f compose.yaml exec backend php yii notification/send
```

Готовность: `/health/ready`; liveness: `/health/live`. Документы хранятся в
именованном volume, MariaDB — в отдельном volume.
