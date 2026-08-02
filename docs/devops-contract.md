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
