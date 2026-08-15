# Observability

Первая итерация добавляет self-hosted стек Grafana OSS, Prometheus, Loki и
Grafana Alloy как удаляемый Compose overlay. Код приложения, основной Compose,
Nginx и lifecycle-команды не меняются.

## Архитектура

- **Grafana** автоматически получает Prometheus/Loki datasources и три dashboard;
- **Prometheus** хранит host/container/runtime metrics и вычисляет alert rules;
- **Loki** хранит технические container logs;
- **Alloy** обнаруживает Docker containers и передаёт их stdout/stderr в Loki;
- **node_exporter** публикует CPU, RAM, load и filesystem metrics хоста;
- **cAdvisor** публикует container state и resource usage;
- **blackbox_exporter** проверяет существующий `http://frontend:8080/health/ready`.

Prometheus endpoint в Yii не добавляется. `audit_events`, `request_transitions` и
`notification_outbox` являются application/business data и не заменяются Loki.
Loki предназначен для технических runtime/application logs.

Все сервисы находятся в default network того же Compose project. Prometheus,
Loki, Alloy и exporters не публикуют ports на host. Grafana — единственный новый
published port, по умолчанию `127.0.0.1:3000`; это безопасная точка для SSH
tunnel или локального reverse proxy, но не готовый публичный production URL.

Alloy получает read-only доступ к `/var/run/docker.sock`, а cAdvisor — read-only
host mounts и `privileged` для container accounting. Это чувствительные
операционные полномочия: доступ к их контейнерам и внутренней сети должен быть
ограничен администраторами сервера.

Стандартный nginx access log содержит request URI. Перед отправкой в Loki Alloy
заменяет 64-символьный token в существующем
`/api/v1/document-links/<token>/download` на `[REDACTED]`. Authorization headers,
cookies и request body штатный nginx log format не пишет. Приложению всё равно
не следует передавать secrets через произвольные query parameters: такие URI
могут попасть в access log без дополнительной redaction.

## Конфигурация и запуск

Production требует добавить в защищённый `.env.prod`:

```dotenv
GRAFANA_ADMIN_PASSWORD=<long-random-secret>
GRAFANA_ADMIN_USER=admin
GRAFANA_PORT=3000
PROMETHEUS_RETENTION=15d
PROMETHEUS_RETENTION_SIZE=10GB
```

В Git не хранится пароль или production env. Для production
`GRAFANA_ADMIN_PASSWORD` обязателен: `make prod-obs-up` и `make prod-stack-up`
завершаются ошибкой при его отсутствии. Production fallback и anonymous access
отсутствуют. На одном сервере нельзя одновременно оставить default
`GRAFANA_PORT` для dev и prod — одному из проектов назначьте другой loopback
port.

Development не требует настройки Grafana: Make использует локальные credentials
`admin`/`admin`, если `GRAFANA_ADMIN_PASSWORD` не задан в shell или `.env.dev`.
Grafana всё равно публикуется только на loopback. Явные значения в `.env.dev`
переопределяют этот dev-only default.

Приложение и observability можно поднять одной командой:

```sh
make dev-stack-up
make prod-stack-up
```

Или управлять overlay отдельно после запуска приложения:

```sh
make dev-obs-up
make dev-obs-status
make dev-obs-logs
make dev-obs-restart
make dev-obs-down

make prod-obs-up
make prod-obs-status
make prod-obs-logs
make prod-obs-restart
make prod-obs-down
```

`SERVICE` и `LOG_TAIL` работают так же, как для application logs:

```sh
make dev-obs-logs SERVICE=loki LOG_TAIL=all
```

`obs-up` проверяет health Grafana, Prometheus и Loki, состояние exporters и
Alloy. `stack-up` сначала полностью поднимает приложение и дожидается frontend
`/health/ready`, затем запускает observability. Compose project, env-файл и
overlay-аргументы централизованы в lifecycle-скрипте и вручную не передаются.

Grafana provisioning находится в `observability/grafana/provisioning`, dashboard
JSON — в `observability/grafana/dashboards`, Prometheus alerts — в
`observability/prometheus/rules.yml`. Alert rules видны через Prometheus
datasource. Contact point намеренно не задан: SMTP/webhook credentials должны
поступать из согласованного secret store на следующем этапе.

## Данные и retention

Named volumes `<project>_grafana-data`, `<project>_prometheus-data` и
`<project>_loki-data` переживают container recreation. Alloy также использует
`<project>_alloy-data` для collector state. Runtime data в working tree не
пишутся.

Loki хранит данные 14 дней (`336h`). Prometheus хранит не более 15 дней и не
более 10 GB; срабатывает первый достигнутый предел. Это консервативный старт для
одного сервера рядом с приложением. Значения Prometheus можно уменьшить через
env; изменение retention не удаляет application data. Собственные Docker logs
observability-сервисов ограничены тремя файлами по 10 MB на container. Rotation
уже существующих application containers остаётся политикой Docker daemon и не
переопределяется overlay.

Для обновления сначала измените явно pinned image tag, проверьте release notes и
config validators, затем выполните `make dev-obs-up` либо `make prod-obs-up`.
Named volumes сохраняются. Перед несовместимым обновлением сделайте
snapshot/backup этих volumes.

## Остановка и полное удаление

Остановить и удалить только observability containers, сохранив данные:

```sh
make dev-obs-down
make prod-obs-down
```

Остановить приложение и observability вместе, также сохранив все named volumes:

```sh
make dev-stack-down
make prod-stack-down
```

Обычные `dev-down`/`prod-down` и `dev-restart`/`prod-restart` адресно управляют
только приложением: запущенный observability-контур не удаляется. Named volumes
сохраняются при всех `*-down` и `*-restart`. Для полного удаления данных после
отдельного backup удалите **точно перечисленные** `<project>_grafana-data`,
`<project>_prometheus-data`, `<project>_loki-data` и `<project>_alloy-data` через
`docker volume rm`. Не используйте `compose down --volumes`: эта команда также
удалит MariaDB и document volumes приложения. После удаления каталога
`observability/` и `compose.observability.yml` исходный deployment остаётся
функционально идентичным.

## Dashboards и alerts

- **Infrastructure overview**: observability targets, CPU, RAM, load,
  filesystem, container visibility, CPU/RAM и starts/restarts;
- **IC runtime overview**: `/health/ready`, probe latency, application container
  visibility/resources и active alerts;
- **IC logs**: Loki entry point с low-cardinality `environment`, `service` и
  `container` labels для frontend/nginx, backend/PHP-FPM, scheduler, MariaDB и
  observability containers данного `ic-dev`/`ic-prod` project. Значение `All`
  в фильтрах выбирает только непустые labels, как требует Loki; доступны также
  независимые фильтры по environment и service.

Prometheus rules покрывают readiness, отсутствие application containers,
недоступность observability scrape targets, filesystem/memory pressure и
повторные container starts. Последний сигнал отражает изменения start timestamp,
а не Docker restart counter: cAdvisor не предоставляет надёжный универсальный
counter для всех Compose runtimes.

## Ограничения и следующие этапы

- Для production URL нужны согласованные DNS, TLS и минимальный reverse-proxy
  location к loopback Grafana; существующий application nginx не изменён.
- LDAP/OAuth и Grafana role mapping требуют отдельной security-интеграции.
- Нужен contact point из корпоративного SMTP/webhook secret store и routing
  policy; сейчас alerts вычисляются и видны в UI, но наружу не отправляются.
- Docker socket и privileged cAdvisor требуют отдельного hardening review; при
  необходимости их можно изолировать socket proxy с минимальным API allowlist.
- В Docker Desktop/WSL bind mount `/var/lib/docker` может не соответствовать
  storage root Docker daemon. В таком окружении cAdvisor scrape остаётся
  доступен, но container series/Compose labels могут быть неполными; полноценную
  проверку container panels и alerts нужно повторить на целевом Linux server.
- Nginx не экспортирует Prometheus counters; текущая итерация использует только
  readiness и container telemetry. Container stdout/stderr могут содержать те
  данные, которые уже пишет приложение. Текущая конфигурация не добавляет
  cookies, authorization headers, tokens, request IDs, usernames, URL paths или
  message contents в labels.
- Wave 2 отдельно добавит application metrics: HTTP request count/latency/5xx,
  notification outbox pending/failed, worker health, document processing
  failures, requests by status и request age. Это потребует backend design и не
  входит в infrastructure-only итерацию.
