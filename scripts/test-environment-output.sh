#!/bin/sh
set -eu

cd "$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)"
test_dir=$(mktemp -d)
cleanup() {
  rm -rf "$test_dir"
}
trap cleanup EXIT HUP INT TERM

mock=$test_dir/mock-runtime
cat >"$mock" <<'MOCK'
#!/bin/sh
set -eu
printf '%s\n' "$*" >>"${MOCK_RECORD:?}"

if [ -n "${MOCK_FAIL_PATTERN:-}" ]; then
  case "$*" in
  *"$MOCK_FAIL_PATTERN"*)
    echo "original runtime diagnostic" >&2
    exit "${MOCK_FAIL_STATUS:-47}"
    ;;
  esac
fi

case " $* " in
*" config "*)
  if [ -n "${EXPECT_GRAFANA_PASSWORD:-}" ] &&
    [ "${GRAFANA_ADMIN_PASSWORD:-}" != "$EXPECT_GRAFANA_PASSWORD" ]; then
    echo "unexpected Grafana password contract" >&2
    exit 49
  fi
  if [ "${EXPECT_GRAFANA_PASSWORD_UNSET:-0}" = 1 ] &&
    [ "${GRAFANA_ADMIN_PASSWORD+set}" = set ]; then
    echo "empty shell Grafana password was not unset" >&2
    exit 50
  fi
  if [ "${MOCK_FAIL:-}" = config ]; then
    echo "original compose diagnostic" >&2
    exit 42
  fi
  if [ "${MOCK_WARNING:-}" = 1 ]; then
    echo "verbose successful technical output"
    echo "compose warning retained" >&2
  fi
  ;;
*" ps -q mariadb "*)
  [ "${MOCK_PS_WARNING:-0}" -ne 1 ] || echo "compose ps warning retained" >&2
  echo mariadb-id
  ;;
*" ps -q backend "*) echo backend-id ;;
*" ps -q frontend "*)
  echo frontend-id
  [ "${MOCK_FRONTEND_REPLICAS:-1}" -eq 1 ] || echo frontend-id-2
  ;;
*" ps -q scheduler "*) echo scheduler-id ;;
*" ps -q grafana "*) echo grafana-id ;;
*" ps -q prometheus "*) echo prometheus-id ;;
*" ps -q loki "*) echo loki-id ;;
*" ps -q alloy "*) echo alloy-id ;;
*" ps -q node-exporter "*) echo node-exporter-id ;;
*" ps -q cadvisor "*) echo cadvisor-id ;;
*" ps -q blackbox-exporter "*) echo blackbox-exporter-id ;;
*" port frontend 8080 "*) echo 0.0.0.0:18081 ;;
*" port grafana 3000 "*) echo 127.0.0.1:13000 ;;
*" logs --tail=50 "*) echo "relevant service log" ;;
esac

if [ "${1:-}" = inspect ]; then
  last=
  for argument in "$@"; do last=$argument; done
  case "$last" in
  mariadb-id) echo healthy ;;
  frontend-id) echo "${MOCK_FRONTEND_STATE:-healthy}" ;;
  frontend-id-2) echo "${MOCK_FRONTEND_REPLICA_STATE:-healthy}" ;;
  backend-id | scheduler-id) echo running ;;
  grafana-id | prometheus-id | loki-id) echo healthy ;;
  alloy-id | node-exporter-id | blackbox-exporter-id) echo running ;;
  cadvisor-id) echo healthy ;;
  esac
fi
MOCK
chmod +x "$mock"

run_environment() {
  scope=$1
  shift
  COMPOSE=$mock CONTAINER_ENGINE=$mock DEV_PROJECT=ic-dev \
    DEV_ENV_FILE=${DEV_ENV_FILE_OVERRIDE:-.env.dev.example} MOCK_RECORD=$test_dir/record \
    sh scripts/environment.sh dev "$scope" "$@"
}

run_prod_environment() {
  scope=$1
  shift
  COMPOSE=$mock CONTAINER_ENGINE=$mock PROD_PROJECT=ic-prod \
    PROD_ENV_FILE=.env.example MOCK_RECORD=$test_dir/record \
    sh scripts/environment.sh prod "$scope" "$@"
}

: >"$test_dir/record"
success_output=$(NO_COLOR=1 run_environment app up)
printf '%s' "$success_output" | grep -q 'frontend       healthy'
printf '%s' "$success_output" | grep -q 'http://localhost:18081/api/docs/'
if printf '%s' "$success_output" | LC_ALL=C grep -q "$(printf '\033')"; then
  echo "NO_COLOR output contains ANSI escapes" >&2
  exit 1
fi

set +e
failure_output=$(MOCK_FAIL=config NO_COLOR=1 run_environment app up 2>&1)
failure_status=$?
set -e
[ "$failure_status" -eq 42 ]
printf '%s' "$failure_output" | grep -q 'original compose diagnostic'

warning_output=$(MOCK_WARNING=1 NO_COLOR=1 run_environment app up 2>&1)
printf '%s' "$warning_output" | grep -q 'compose warning retained'
if printf '%s' "$warning_output" | grep -q 'verbose successful technical output'; then
  echo "Successful technical output leaked with a warning" >&2
  exit 1
fi

ps_warning_output=$(MOCK_PS_WARNING=1 NO_COLOR=1 run_environment app status 2>&1)
printf '%s' "$ps_warning_output" | grep -q 'compose ps warning retained'
printf '%s' "$ps_warning_output" | grep -q 'mariadb.*healthy'

set +e
unhealthy_output=$(MOCK_FRONTEND_STATE=unhealthy NO_COLOR=1 run_environment app up 2>&1)
unhealthy_status=$?
set -e
[ "$unhealthy_status" -ne 0 ]
printf '%s' "$unhealthy_output" | grep -q 'frontend.*unhealthy'
printf '%s' "$unhealthy_output" | grep -q 'relevant service log'

set +e
MOCK_FRONTEND_STATE=unhealthy NO_COLOR=1 run_environment app status >/dev/null 2>&1
unhealthy_status=$?
set -e
[ "$unhealthy_status" -ne 0 ]

set +e
timeout_output=$(MOCK_FRONTEND_STATE=starting SERVICE_READY_TIMEOUT=0 NO_COLOR=1 run_environment app up 2>&1)
timeout_status=$?
set -e
[ "$timeout_status" -ne 0 ]
printf '%s' "$timeout_output" | grep -q 'frontend.*starting.*ожидалось: healthy'

set +e
SERVICE_READY_TIMEOUT=invalid NO_COLOR=1 run_environment app up >/dev/null 2>&1
invalid_timeout_status=$?
replica_output=$(MOCK_FRONTEND_REPLICAS=2 MOCK_FRONTEND_REPLICA_STATE=unhealthy \
  NO_COLOR=1 run_environment app status 2>&1)
replica_status=$?
runtime_output=$(MOCK_FAIL_PATTERN='frontend-id' MOCK_FAIL_STATUS=48 \
  NO_COLOR=1 run_environment app up 2>&1)
runtime_status=$?
set -e
[ "$invalid_timeout_status" -eq 2 ]
[ "$replica_status" -ne 0 ]
printf '%s' "$replica_output" | grep -q 'frontend.*unhealthy'
[ "$runtime_status" -eq 48 ]
printf '%s' "$runtime_output" | grep -q 'original runtime diagnostic'
printf '%s' "$runtime_output" | grep -q 'relevant service log'

color_output=$(FORCE_COLOR=1 run_environment app status)
printf '%s' "$color_output" | LC_ALL=C grep -q "$(printf '\033')"

: >"$test_dir/record"
NO_COLOR=1 SERVICE=backend run_environment app logs >/dev/null
grep -q 'logs --follow --tail=100 backend' "$test_dir/record"
NO_COLOR=1 SERVICE=frontend LOG_TAIL=all run_environment app logs >/dev/null
grep -q 'logs --follow --tail=all frontend' "$test_dir/record"

set +e
NO_COLOR=1 SERVICE=unknown run_environment app logs >/dev/null 2>&1
invalid_service_status=$?
NO_COLOR=1 LOG_TAIL=invalid run_environment app logs >/dev/null 2>&1
invalid_tail_status=$?
set -e
[ "$invalid_service_status" -eq 2 ]
[ "$invalid_tail_status" -eq 2 ]

set +e
MOCK_FAIL_PATTERN='stop frontend scheduler backend mariadb' MOCK_FAIL_STATUS=43 NO_COLOR=1 \
  run_environment app down >/dev/null 2>&1
down_failure_status=$?
MOCK_FAIL_PATTERN='ps -q mariadb' MOCK_FAIL_STATUS=44 NO_COLOR=1 \
  run_environment app status >/dev/null 2>&1
status_failure_status=$?
MOCK_FAIL_PATTERN='logs --follow' MOCK_FAIL_STATUS=45 NO_COLOR=1 \
  run_environment app logs >/dev/null 2>&1
logs_failure_status=$?
MOCK_FAIL_PATTERN='stop frontend scheduler backend mariadb' MOCK_FAIL_STATUS=46 NO_COLOR=1 \
  run_environment app restart >/dev/null 2>&1
restart_failure_status=$?
set -e
[ "$down_failure_status" -eq 43 ]
[ "$status_failure_status" -eq 44 ]
[ "$logs_failure_status" -eq 45 ]
[ "$restart_failure_status" -eq 46 ]

down_output=$(NO_COLOR=1 run_environment app down)
printf '%s' "$down_output" | grep -q 'Приложение остановлено'

: >"$test_dir/record"
restart_output=$(NO_COLOR=1 run_environment app restart)
[ "$(printf '%s' "$restart_output" | grep -c 'IC · Разработка')" -eq 1 ]
grep -q 'stop frontend scheduler backend mariadb' "$test_dir/record"
grep -q 'rm -f frontend scheduler backend mariadb' "$test_dir/record"
if grep -q -- '--remove-orphans' "$test_dir/record"; then
  echo "Application lifecycle must not remove observability orphans" >&2
  exit 1
fi
grep -q 'stop frontend scheduler backend' "$test_dir/record"
grep -q 'run --rm backend php yii migrate/up --interactive=0' "$test_dir/record"
grep -q 'up -d --no-build --force-recreate backend frontend scheduler' "$test_dir/record"
migration_line=$(grep -n 'run --rm backend php yii migrate/up --interactive=0' "$test_dir/record" | tail -1 | cut -d: -f1)
application_start_line=$(grep -n 'up -d --no-build --force-recreate backend frontend scheduler' "$test_dir/record" | tail -1 | cut -d: -f1)
[ "$migration_line" -lt "$application_start_line" ]

: >"$test_dir/record"
obs_output=$(EXPECT_GRAFANA_PASSWORD=admin NO_COLOR=1 run_environment obs up)
printf '%s' "$obs_output" | grep -q 'grafana.*healthy'
printf '%s' "$obs_output" | grep -q 'http://localhost:13000'
grep -q -- '-f compose.yaml -f compose.dev.yaml -f compose.observability.yml config --quiet' "$test_dir/record"
grep -q 'up -d grafana prometheus loki alloy node-exporter cadvisor blackbox-exporter' "$test_dir/record"

: >"$test_dir/record"
GRAFANA_ADMIN_PASSWORD=custom-dev-password EXPECT_GRAFANA_PASSWORD=custom-dev-password \
  NO_COLOR=1 run_environment obs status >/dev/null

dev_env_with_password=$test_dir/.env.dev
cp .env.dev.example "$dev_env_with_password"
printf '\nGRAFANA_ADMIN_PASSWORD=from-env-file\n' >>"$dev_env_with_password"
: >"$test_dir/record"
GRAFANA_ADMIN_PASSWORD='' EXPECT_GRAFANA_PASSWORD_UNSET=1 \
  DEV_ENV_FILE_OVERRIDE=$dev_env_with_password NO_COLOR=1 run_environment obs up >/dev/null

: >"$test_dir/record"
NO_COLOR=1 SERVICE=loki run_environment obs logs >/dev/null
grep -q 'logs --follow --tail=100 loki' "$test_dir/record"
set +e
NO_COLOR=1 SERVICE=backend run_environment obs logs >/dev/null 2>&1
invalid_obs_service_status=$?
set -e
[ "$invalid_obs_service_status" -eq 2 ]

: >"$test_dir/record"
obs_down_output=$(NO_COLOR=1 run_environment obs down)
printf '%s' "$obs_down_output" | grep -q 'Observability остановлен'
grep -q 'stop grafana prometheus loki alloy node-exporter cadvisor blackbox-exporter' "$test_dir/record"
grep -q 'rm -f grafana prometheus loki alloy node-exporter cadvisor blackbox-exporter' "$test_dir/record"
if grep -Eq 'stop .*mariadb|rm -f .*mariadb|down' "$test_dir/record"; then
  echo "Observability down must not remove application services" >&2
  exit 1
fi

: >"$test_dir/record"
NO_COLOR=1 run_environment stack up >/dev/null
application_start_line=$(grep -n 'up -d --no-build --force-recreate backend frontend scheduler' "$test_dir/record" | tail -1 | cut -d: -f1)
observability_start_line=$(grep -n 'up -d grafana prometheus loki alloy node-exporter cadvisor blackbox-exporter' "$test_dir/record" | tail -1 | cut -d: -f1)
[ "$application_start_line" -lt "$observability_start_line" ]

: >"$test_dir/record"
stack_down_output=$(NO_COLOR=1 run_environment stack down)
printf '%s' "$stack_down_output" | grep -q 'Полный стек остановлен'
grep -q -- '-f compose.observability.yml down' "$test_dir/record"
if grep -q -- '--volumes' "$test_dir/record"; then
  echo "Stack down must preserve named volumes" >&2
  exit 1
fi

: >"$test_dir/record"
NO_COLOR=1 run_environment app reset >/dev/null
grep -q 'volume rm ic-dev_mariadb-data' "$test_dir/record"
grep -q 'volume rm ic-dev_document-data' "$test_dir/record"
if grep -Eq 'grafana-data|prometheus-data|loki-data|alloy-data|remove-orphans' "$test_dir/record"; then
  echo "Application reset must preserve observability containers and volumes" >&2
  exit 1
fi

: >"$test_dir/record"
NO_COLOR=1 run_prod_environment obs status >/dev/null
if grep -q '^GRAFANA_ADMIN_PASSWORD=' .env.example; then
  echo "Production example must not provide a Grafana password fallback" >&2
  exit 1
fi

temporary_output_dir=$test_dir/output
mkdir "$temporary_output_dir"
TMPDIR=$temporary_output_dir NO_COLOR=1 run_environment app status >/dev/null
[ -z "$(find "$temporary_output_dir" -mindepth 1 -print -quit)" ]
set +e
TMPDIR=$temporary_output_dir MOCK_FAIL=config NO_COLOR=1 \
  run_environment app up >/dev/null 2>&1
temporary_failure_status=$?
set -e
[ "$temporary_failure_status" -eq 42 ]
[ -z "$(find "$temporary_output_dir" -mindepth 1 -print -quit)" ]

echo "Environment output contracts passed"
