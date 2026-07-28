# Контракт поставки для DevOps

## Ожидаемый быстрый старт

После появления полного приложения тестовый контур должен запускаться одной
командой:

```bash
docker compose up -d --build
```

Затем отдельная идемпотентная команда выполняет readiness check, миграции и
создание первой аварийной учётной записи администратора.

Для демонстрационной Windows-машины pipeline формирует автономный комплект с
готовыми образами. Целевая машина не выполняет `composer install` или `npm ci` и
не зависит от внешних package registry; см. `demo-workstation.md`.

## Сервисы Compose

| Сервис | Назначение |
|---|---|
| `gateway` | Nginx, TLS termination либо upstream корпоративного proxy |
| `backend` | неизменяемый PHP-FPM образ приложения |
| `frontend` | статическая production-сборка Vue |
| `worker` | очередь email, PDF и фоновых операций из того же backend image |
| `scheduler` | периодические задания без встроенного cron в web-контейнере |
| `mariadb` | локально/test; production может использовать управляемый сервер |
| `mailpit` | только development/test, перехват почты |

Для очереди на первом этапе используется database-backed queue Yii2, чтобы не
вводить Redis без необходимости. Масштабирование не требует изменения доменной
логики.

## Требования к образам

- multi-stage build;
- фиксированные версии базовых образов и dependency lock-файлы;
- non-root runtime user;
- read-only root filesystem, где возможно;
- healthcheck/readiness endpoint;
- graceful shutdown worker;
- отсутствие исходных секретов в слоях;
- OCI labels с commit SHA и номером релиза;
- отдельные persistent volumes только для документов и БД development-контура.

## Наблюдаемость

- структурированные JSON-логи в stdout/stderr;
- request/correlation ID проходит через HTTP, очередь и аудит;
- `/health/live` не проверяет внешние зависимости;
- `/health/ready` проверяет БД и доступность файлового хранилища;
- метрики очереди: pending, retry, failed;
- SMTP/LDAP credentials никогда не выводятся в health response.

## Резервное копирование

- MariaDB: регулярный логический/физический backup по политике предприятия;
- документы: согласованная snapshot/backup политика;
- БД и документы восстанавливаются на одну согласованную временную точку;
- restore drill документируется и регулярно проверяется;
- контейнерные образы не являются резервной копией данных.

## Перед production

DevOps получает заполненные runbook'и: prerequisites, install, configuration,
deploy, upgrade, rollback, backup, restore, monitoring и troubleshooting. Команды
проверяются на чистом тестовом сервере, а не только на машине разработчика.
