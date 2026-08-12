.DEFAULT_GOAL := help

COMPOSE ?= $(shell if docker compose version >/dev/null 2>&1; then printf 'docker compose'; elif command -v podman-compose >/dev/null 2>&1; then printf 'podman-compose'; elif podman compose version >/dev/null 2>&1; then printf 'podman compose'; fi)
CONTAINER_ENGINE ?= $(if $(findstring podman,$(COMPOSE)),podman,docker)
PROD_PROJECT := ic-prod
DEV_PROJECT := ic-dev
TEST_PROJECT := ic-test
PROD_ENV_FILE := .env.prod
DEV_ENV_FILE := .env.dev
TEST_ENV_FILE := .env.test
PROD_COMPOSE := $(COMPOSE) -p $(PROD_PROJECT) --env-file $(PROD_ENV_FILE) -f compose.yaml
DEV_COMPOSE := $(COMPOSE) -p $(DEV_PROJECT) --env-file $(DEV_ENV_FILE) -f compose.yaml -f compose.dev.yaml
export COMPOSE CONTAINER_ENGINE PROD_PROJECT DEV_PROJECT TEST_PROJECT PROD_ENV_FILE DEV_ENV_FILE TEST_ENV_FILE

.PHONY: help doctor _doctor init up down logs dev-up dev-down dev-restart dev-status dev-reset dev-logs \
	prod-up prod-down prod-restart prod-status prod-reset prod-logs env-status check e2e coverage schema-diagram \
	openapi-validate _check-backend _check-frontend _check-repository _dev-contract _test-up _test-reset _test-down

help:
	@echo "make help            Показать эту справку"
	@echo "make doctor          Проверить локальные инструменты"
	@echo "make init            Создать .env.dev и подключить Git hooks"
	@echo ""
	@echo "Промышленная эксплуатация:"
	@echo "  make prod-up       Собрать и поднять deployment из .env.prod"
	@echo "  make prod-down     Остановить deployment без удаления данных"
	@echo "  make prod-restart  Перезапустить production deployment"
	@echo "  make prod-status   Показать готовность production services"
	@echo "  make prod-reset    Удалить production volumes и поднять чистый deployment"
	@echo "  make prod-logs     Показать логи production deployment"
	@echo ""
	@echo "Разработка:"
	@echo "  make dev-up        Поднять и подготовить deployment из .env.dev"
	@echo "  make dev-down      Остановить deployment из .env.dev"
	@echo "  make dev-restart   Перезапустить development deployment"
	@echo "  make dev-status    Показать готовность development services"
	@echo "  make dev-reset     Удалить dev volumes и поднять чистый deployment"
	@echo "  make dev-logs      Показать логи deployment"
	@echo "  make env-status    Показать активные локальные окружения без секретов"
	@echo ""
	@echo "Проверки качества:"
	@echo "  make check         Линтеры, анализ, unit, Vitest и production build"
	@echo "  make e2e           Integration, Playwright и runtime contracts"
	@echo "  make coverage      Только отчёты покрытия"
	@echo "  make openapi-validate Проверить OpenAPI и локальные Swagger UI assets"
	@echo "  make schema-diagram Обновить ER-диаграмму"

_doctor:
	@test -n "$(COMPOSE)" || { echo "Нужен Docker Compose или Podman Compose." >&2; exit 1; }
	@command -v npm >/dev/null || { echo "Не найден npm." >&2; exit 1; }
	@command -v python3 >/dev/null || { echo "Не найден python3." >&2; exit 1; }

doctor: _doctor
	@echo "Compose: $(COMPOSE)"
	@$(COMPOSE) version

init: doctor
	@test -f .env.dev || { cp .env.dev.example .env.dev; echo "Создан .env.dev"; }
	sh scripts/install-git-hooks.sh

up down logs:
	@echo "Неоднозначная команда '$@' отключена." >&2
	@echo "Используйте make dev-$@ или make prod-$@." >&2
	@exit 2

prod-up: _doctor
	@sh scripts/environment.sh prod up

prod-down: _doctor
	@sh scripts/environment.sh prod down

prod-restart: _doctor
	@sh scripts/environment.sh prod restart

prod-status: _doctor
	@sh scripts/environment.sh prod status

prod-logs: _doctor
	@sh scripts/environment.sh prod logs

prod-reset: doctor
	@test -f $(PROD_ENV_FILE) || { echo "Для make prod-reset нужен .env.prod." >&2; exit 2; }
	@printf "Будут безвозвратно удалены volumes проекта $(PROD_PROJECT). Введите $(PROD_PROJECT): "; \
		read -r answer; test "$$answer" = "$(PROD_PROJECT)" || { echo "Отменено." >&2; exit 2; }
	COMPOSE_ENV_FILE=$(PROD_ENV_FILE) $(PROD_COMPOSE) down --volumes --remove-orphans
	$(MAKE) prod-up

dev-up: _doctor
	@sh scripts/environment.sh dev up

dev-down: _doctor
	@sh scripts/environment.sh dev down

dev-restart: _doctor
	@sh scripts/environment.sh dev restart

dev-status: _doctor
	@sh scripts/environment.sh dev status

dev-reset: doctor
	@test -f $(DEV_ENV_FILE) || { echo "Для make dev-reset нужен .env.dev (make init)." >&2; exit 2; }
	@echo "Удаление dev volumes проекта $(DEV_PROJECT)."
	COMPOSE_ENV_FILE=$(DEV_ENV_FILE) $(DEV_COMPOSE) down --volumes --remove-orphans
	$(MAKE) dev-up

dev-logs: _doctor
	@sh scripts/environment.sh dev logs

env-status: doctor
	sh scripts/env-status.sh

check: doctor _check-frontend _check-backend _check-repository
	git diff --check

e2e: doctor
	sh scripts/e2e.sh

coverage: doctor
	mkdir -p backend/build/coverage
	python3 -m venv backend/build/coverage/python-venv
	backend/build/coverage/python-venv/bin/pip install --disable-pip-version-check -r scripts/requirements-coverage.txt
	COVERAGE_FILE=backend/build/coverage/.coverage backend/build/coverage/python-venv/bin/coverage run --include='scripts/check_coverage.py' -m unittest discover -s scripts/tests
	COVERAGE_FILE=backend/build/coverage/.coverage backend/build/coverage/python-venv/bin/coverage xml -o backend/build/coverage/python.xml
	$(CONTAINER_ENGINE) build --file docker/coverage.Dockerfile --tag shlz-test-registry-coverage .
	COMPOSE='$(COMPOSE)' CONTAINER_ENGINE='$(CONTAINER_ENGINE)' sh scripts/backend-coverage.sh
	python3 scripts/check_coverage.py backend/build/coverage/clover.xml --minimum 90
	npm --prefix frontend ci --no-audit --no-fund
	npm --prefix frontend run coverage

openapi-validate:
	npm --prefix frontend ci --no-audit --no-fund
	npm --prefix frontend run openapi:validate

schema-diagram: doctor
	@test -f $(DEV_ENV_FILE) || { echo "Для make schema-diagram нужен .env.dev (make init)." >&2; exit 2; }
	COMPOSE_ENV_FILE=$(DEV_ENV_FILE) SCHEMA_COMPOSE_COMMAND='$(DEV_COMPOSE)' python3 scripts/gen_schema_diagram.py

_check-frontend:
	npm --prefix frontend ci --no-audit --no-fund
	npm --prefix frontend run openapi:validate
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
