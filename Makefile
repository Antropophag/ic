.DEFAULT_GOAL := help

COMPOSE ?= $(shell if docker compose version >/dev/null 2>&1; then printf 'docker compose'; elif command -v podman-compose >/dev/null 2>&1; then printf 'podman-compose'; elif podman compose version >/dev/null 2>&1; then printf 'podman compose'; fi)
CONTAINER_ENGINE ?= $(if $(findstring podman,$(COMPOSE)),podman,docker)
export COMPOSE CONTAINER_ENGINE

.PHONY: help doctor init dev up down logs check test e2e coverage schema-diagram \
	_check-backend _check-frontend _check-repository _dev-contract _test-up _test-reset _test-down

help:
	@echo "make doctor          Проверить локальные инструменты"
	@echo "make init            Создать .env.dev и подключить Git hooks"
	@echo "make dev             Поднять и подготовить development"
	@echo "make up              Поднять production-like deployment из .env"
	@echo "make down            Остановить development deployment из .env.dev"
	@echo "make logs            Показать логи development deployment"
	@echo "make check           Линтеры, анализ, unit, Vitest и production build"
	@echo "make test            Полная проверка: check + единый test deployment"
	@echo "make e2e             Integration, Playwright и runtime contracts"
	@echo "make coverage        Только отчёты покрытия"
	@echo "make schema-diagram  Обновить ER-диаграмму"

doctor:
	@test -n "$(COMPOSE)" || { echo "Нужен Docker Compose или Podman Compose." >&2; exit 1; }
	@command -v npm >/dev/null || { echo "Не найден npm." >&2; exit 1; }
	@command -v python3 >/dev/null || { echo "Не найден python3." >&2; exit 1; }
	@echo "Compose: $(COMPOSE)"
	@$(COMPOSE) version

init: doctor
	@test -f .env.dev || { cp .env.dev.example .env.dev; echo "Создан .env.dev"; }
	sh scripts/install-git-hooks.sh

dev: doctor
	sh scripts/dev.sh

up: doctor
	@test -f .env || { echo "Скопируйте .env.example в .env и заполните production-настройки." >&2; exit 2; }
	$(COMPOSE) --env-file .env -f compose.yaml up -d --build mariadb backend
	$(COMPOSE) --env-file .env -f compose.yaml run --rm backend php yii migrate/up --interactive=0
	$(COMPOSE) --env-file .env -f compose.yaml run --rm backend php yii admin/bootstrap
	$(COMPOSE) --env-file .env -f compose.yaml up -d frontend scheduler

down: doctor
	@test -f .env.dev || { echo "Для make down нужен .env.dev (make init)." >&2; exit 2; }
	COMPOSE_ENV_FILE=.env.dev $(COMPOSE) --env-file .env.dev -f compose.yaml -f compose.dev.yaml down --remove-orphans

logs: doctor
	@test -f .env.dev || { echo "Для make logs нужен .env.dev (make init)." >&2; exit 2; }
	COMPOSE_ENV_FILE=.env.dev $(COMPOSE) --env-file .env.dev -f compose.yaml -f compose.dev.yaml logs --tail=200

check: doctor _check-frontend _check-backend _check-repository
	git diff --check

test: check e2e

e2e: doctor
	sh scripts/e2e.sh

coverage: doctor
	mkdir -p backend/build/coverage
	$(CONTAINER_ENGINE) build --file docker/coverage.Dockerfile --tag shlz-test-registry-coverage .
	$(CONTAINER_ENGINE) run --rm --volume "$(CURDIR)/backend/build/coverage:/app/build/coverage" shlz-test-registry-coverage
	python3 scripts/check_coverage.py backend/build/coverage/clover.xml --minimum 90
	npm --prefix frontend ci --no-audit --no-fund
	npm --prefix frontend run coverage

schema-diagram:
	python3 scripts/gen_schema_diagram.py

_check-frontend:
	npm --prefix frontend ci --no-audit --no-fund
	npm --prefix frontend run lint
	npm --prefix frontend run audit
	npm --prefix frontend test
	npm --prefix frontend run build

_check-backend:
	$(CONTAINER_ENGINE) build --file docker/coverage.Dockerfile --tag shlz-test-registry-coverage .
	$(CONTAINER_ENGINE) run --rm shlz-test-registry-coverage composer validate --strict
	$(CONTAINER_ENGINE) run --rm shlz-test-registry-coverage composer lint
	$(CONTAINER_ENGINE) run --rm shlz-test-registry-coverage composer analyse
	$(CONTAINER_ENGINE) run --rm shlz-test-registry-coverage composer audit
	$(CONTAINER_ENGINE) run --rm shlz-test-registry-coverage composer test

_check-repository:
	@if test ! -x frontend/node_modules/.bin/markdownlint-cli2; then npm --prefix frontend ci --no-audit --no-fund; fi
	sh scripts/lint-repository.sh

_test-up:
	sh scripts/test-env.sh up

_dev-contract:
	sh scripts/dev-runtime-contract.sh

_test-reset:
	sh scripts/test-env.sh reset

_test-down:
	sh scripts/test-env.sh down
