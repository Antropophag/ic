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

В защищённый `.env.dev` или `.env.prod` добавьте:

```dotenv
GRAFANA_ADMIN_USER=admin
GRAFANA_ADMIN_PASSWORD=<long-random-secret>
GRAFANA_PORT=3000
PROMETHEUS_RETENTION=15d
PROMETHEUS_RETENTION_SIZE=10GB
```

В Git не хранится пароль или production env. `GRAFANA_ADMIN_PASSWORD` обязателен;
Compose прекратит запуск при его отсутствии. На одном сервере нельзя одновременно
оставить default `GRAFANA_PORT` для dev и prod — одному из проектов назначьте
другой loopback port.

Сначала поднимите приложение штатной командой. Development:

```sh
make dev-up
COMPOSE_ENV_FILE=.env.dev docker compose -p ic-dev --env-file .env.dev \
  -f compose.yaml -f compose.dev.yaml -f compose.observability.yml \
  up -d grafana prometheus loki alloy node-exporter cadvisor blackbox-exporter
```

Production (после обязательного штатного `make prod-up`):

```sh
make prod-up
COMPOSE_ENV_FILE=.env.prod docker compose -p ic-prod --env-file .env.prod \
  -f compose.yaml -f compose.observability.yml \
  up -d grafana prometheus loki alloy node-exporter cadvisor blackbox-exporter
```

Проверка состояния:

```sh
COMPOSE_ENV_FILE=.env.prod docker compose -p ic-prod --env-file .env.prod \
  -f compose.yaml -f compose.observability.yml ps
COMPOSE_ENV_FILE=.env.prod docker compose -p ic-prod --env-file .env.prod \
  -f compose.yaml -f compose.observability.yml \
  exec -T grafana wget -q --spider http://127.0.0.1:3000/api/health
```

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
config validators, затем выполните ту же `up -d` команду. Named volumes
сохраняются. Перед несовместимым обновлением сделайте snapshot/backup этих
volumes.

## Остановка и полное удаление

Остановить только observability, сохранив данные:

```sh
COMPOSE_ENV_FILE=.env.prod docker compose -p ic-prod --env-file .env.prod \
  -f compose.yaml -f compose.observability.yml \
  stop grafana prometheus loki alloy node-exporter cadvisor blackbox-exporter
```

Удалить observability containers без воздействия на application containers:

```sh
COMPOSE_ENV_FILE=.env.prod docker compose -p ic-prod --env-file .env.prod \
  -f compose.yaml -f compose.observability.yml rm -f \
  grafana prometheus loki alloy node-exporter cadvisor blackbox-exporter
```

Named volumes сохраняются. Для полного удаления данных после отдельного backup
удалите **точно перечисленные** `<project>_grafana-data`,
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
