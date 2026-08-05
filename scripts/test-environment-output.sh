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
  if [ "${MOCK_FAIL:-}" = config ]; then
    echo "original compose diagnostic" >&2
    exit 42
  fi
  if [ "${MOCK_WARNING:-}" = 1 ]; then
    echo "verbose successful technical output"
    echo "compose warning retained" >&2
  fi
  ;;
*" ps -q mariadb "*) echo mariadb-id ;;
*" ps -q backend "*) echo backend-id ;;
*" ps -q frontend "*) echo frontend-id ;;
*" ps -q scheduler "*) echo scheduler-id ;;
*" port frontend 8080 "*) echo 0.0.0.0:18081 ;;
*" logs --tail=50 "*) echo "relevant service log" ;;
esac

if [ "${1:-}" = inspect ]; then
  last=
  for argument in "$@"; do last=$argument; done
  case "$last" in
  mariadb-id) echo healthy ;;
  frontend-id) echo "${MOCK_FRONTEND_STATE:-healthy}" ;;
  backend-id | scheduler-id) echo running ;;
  esac
fi
MOCK
chmod +x "$mock"

run_environment() {
  COMPOSE=$mock CONTAINER_ENGINE=$mock DEV_PROJECT=ic-dev \
    DEV_ENV_FILE=.env.dev.example MOCK_RECORD=$test_dir/record \
    sh scripts/environment.sh dev "$@"
}

: >"$test_dir/record"
success_output=$(NO_COLOR=1 run_environment up)
printf '%s' "$success_output" | grep -q 'frontend       healthy'
printf '%s' "$success_output" | grep -q 'http://localhost:18081/api/docs/'
if printf '%s' "$success_output" | LC_ALL=C grep -q "$(printf '\033')"; then
  echo "NO_COLOR output contains ANSI escapes" >&2
  exit 1
fi

set +e
failure_output=$(MOCK_FAIL=config NO_COLOR=1 run_environment up 2>&1)
failure_status=$?
set -e
[ "$failure_status" -eq 42 ]
printf '%s' "$failure_output" | grep -q 'original compose diagnostic'

warning_output=$(MOCK_WARNING=1 NO_COLOR=1 run_environment up 2>&1)
printf '%s' "$warning_output" | grep -q 'compose warning retained'
if printf '%s' "$warning_output" | grep -q 'verbose successful technical output'; then
  echo "Successful technical output leaked with a warning" >&2
  exit 1
fi

set +e
unhealthy_output=$(MOCK_FRONTEND_STATE=unhealthy NO_COLOR=1 run_environment up 2>&1)
unhealthy_status=$?
set -e
[ "$unhealthy_status" -ne 0 ]
printf '%s' "$unhealthy_output" | grep -q 'frontend.*unhealthy'
printf '%s' "$unhealthy_output" | grep -q 'relevant service log'

set +e
MOCK_FRONTEND_STATE=unhealthy NO_COLOR=1 run_environment status >/dev/null 2>&1
unhealthy_status=$?
set -e
[ "$unhealthy_status" -ne 0 ]

set +e
timeout_output=$(MOCK_FRONTEND_STATE=starting SERVICE_READY_TIMEOUT=0 NO_COLOR=1 run_environment up 2>&1)
timeout_status=$?
set -e
[ "$timeout_status" -ne 0 ]
printf '%s' "$timeout_output" | grep -q 'frontend.*starting.*expected healthy'

color_output=$(FORCE_COLOR=1 run_environment status)
printf '%s' "$color_output" | LC_ALL=C grep -q "$(printf '\033')"

: >"$test_dir/record"
NO_COLOR=1 SERVICE=backend run_environment logs >/dev/null
grep -q 'logs --follow --tail=100 backend' "$test_dir/record"
NO_COLOR=1 SERVICE=frontend LOG_TAIL=all run_environment logs >/dev/null
grep -q 'logs --follow --tail=all frontend' "$test_dir/record"

set +e
NO_COLOR=1 SERVICE=unknown run_environment logs >/dev/null 2>&1
invalid_service_status=$?
NO_COLOR=1 LOG_TAIL=invalid run_environment logs >/dev/null 2>&1
invalid_tail_status=$?
set -e
[ "$invalid_service_status" -eq 2 ]
[ "$invalid_tail_status" -eq 2 ]

set +e
MOCK_FAIL_PATTERN='down --remove-orphans' MOCK_FAIL_STATUS=43 NO_COLOR=1 \
  run_environment down >/dev/null 2>&1
down_failure_status=$?
MOCK_FAIL_PATTERN='ps -q mariadb' MOCK_FAIL_STATUS=44 NO_COLOR=1 \
  run_environment status >/dev/null 2>&1
status_failure_status=$?
MOCK_FAIL_PATTERN='logs --follow' MOCK_FAIL_STATUS=45 NO_COLOR=1 \
  run_environment logs >/dev/null 2>&1
logs_failure_status=$?
MOCK_FAIL_PATTERN='down --remove-orphans' MOCK_FAIL_STATUS=46 NO_COLOR=1 \
  run_environment restart >/dev/null 2>&1
restart_failure_status=$?
set -e
[ "$down_failure_status" -eq 43 ]
[ "$status_failure_status" -eq 44 ]
[ "$logs_failure_status" -eq 45 ]
[ "$restart_failure_status" -eq 46 ]

down_output=$(NO_COLOR=1 run_environment down)
printf '%s' "$down_output" | grep -q 'Environment stopped'

: >"$test_dir/record"
restart_output=$(NO_COLOR=1 run_environment restart)
[ "$(printf '%s' "$restart_output" | grep -c 'IC · Development')" -eq 1 ]
grep -q 'down --remove-orphans' "$test_dir/record"
grep -q 'up -d --build --force-recreate' "$test_dir/record"

temporary_output_dir=$test_dir/output
mkdir "$temporary_output_dir"
TMPDIR=$temporary_output_dir NO_COLOR=1 run_environment status >/dev/null
[ -z "$(find "$temporary_output_dir" -mindepth 1 -print -quit)" ]
set +e
TMPDIR=$temporary_output_dir MOCK_FAIL=config NO_COLOR=1 \
  run_environment up >/dev/null 2>&1
temporary_failure_status=$?
set -e
[ "$temporary_failure_status" -eq 42 ]
[ -z "$(find "$temporary_output_dir" -mindepth 1 -print -quit)" ]

echo "Environment output contracts passed"
