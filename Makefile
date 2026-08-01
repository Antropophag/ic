.DEFAULT_GOAL := help

.PHONY: help init up down setup check test backend-quality coverage backend-integration frontend-quality frontend-coverage e2e test-env-up test-env-reset test-env-down test-env-destroy test-env-logs repo-quality demo-bundle frontend-build schema-diagram schema-diagram-check

help:
	@echo "up                    Build and start the development stack"
	@echo "init                  Start the stack and apply database migrations"
	@echo "down                  Stop the development stack"
	@echo "setup                 Enable repository Git hooks"
	@echo "check                 Run the same checks as CI before push"
	@echo "test                  Run backend unit and MariaDB integration tests"
	@echo "backend-quality       Run PHP style, static analysis and dependency audit"
	@echo "coverage              Enforce backend domain/application coverage >= 90%"
	@echo "backend-integration   Run Infrastructure repository tests against real MariaDB"
	@echo "frontend-quality      Run frontend lint and dependency audit"
	@echo "frontend-coverage     Enforce frontend logic coverage >= 80%"
	@echo "e2e                   Run the production-like Playwright stand"
	@echo "test-env-up/reset/down Manage the isolated test stand"
	@echo "repo-quality          Lint workflows, Dockerfiles, shell, YAML and Markdown"
	@echo "demo-bundle           Build an offline Windows demo bundle"
	@echo "frontend-build        Verify the production frontend build"
	@echo "schema-diagram        Regenerate ER diagram from migrated MariaDB"
	@echo "schema-diagram-check  Fail if the committed ER diagram is stale"

up:
	docker compose up -d --build

init:
	sh scripts/init-dev.sh

down:
	docker compose down

setup:
	sh scripts/install-git-hooks.sh

check:
	sh scripts/check.sh

test:
	docker build --file docker/coverage.Dockerfile --tag shlz-test-registry-coverage .
	docker run --rm shlz-test-registry-coverage vendor/bin/phpunit
	sh scripts/backend-integration.sh
	npm --prefix frontend ci --no-audit --no-fund
	npm --prefix frontend test

backend-quality:
	docker build --file docker/coverage.Dockerfile --tag shlz-test-registry-coverage .
	docker run --rm shlz-test-registry-coverage composer lint
	docker run --rm shlz-test-registry-coverage composer analyse
	docker run --rm shlz-test-registry-coverage composer audit

coverage:
	mkdir -p backend/build/coverage
	@if command -v php >/dev/null 2>&1 && test -f backend/vendor/bin/phpunit && \
		php -r 'exit(extension_loaded("xdebug") || extension_loaded("pcov") ? 0 : 1);'; then \
		cd backend && XDEBUG_MODE=coverage php vendor/bin/phpunit --coverage-clover build/coverage/clover.xml; \
	elif command -v docker >/dev/null 2>&1; then \
		docker build --file docker/coverage.Dockerfile --tag shlz-test-registry-coverage . && \
		docker run --rm --volume "$(CURDIR)/backend/build/coverage:/app/build/coverage" shlz-test-registry-coverage; \
	else \
		echo "Backend coverage requires PHP with Xdebug/PCOV or Docker." >&2; \
		exit 1; \
	fi
	python3 scripts/check_coverage.py backend/build/coverage/clover.xml --minimum 90

backend-integration:
	sh scripts/backend-integration.sh

frontend-coverage:
	npm --prefix frontend ci --no-audit --no-fund
	npm --prefix frontend run coverage

e2e:
	sh scripts/e2e.sh

test-env-up:
	sh scripts/test-env.sh up

test-env-reset:
	sh scripts/test-env.sh reset

test-env-down:
	sh scripts/test-env.sh down

test-env-destroy:
	sh scripts/test-env.sh destroy

test-env-logs:
	sh scripts/test-env.sh logs

frontend-quality:
	npm --prefix frontend ci --no-audit --no-fund
	npm --prefix frontend run lint
	npm --prefix frontend run audit

repo-quality:
	@if test ! -x frontend/node_modules/.bin/markdownlint-cli2; then \
		command -v npm >/dev/null 2>&1 || { echo "Repository quality requires npm or installed frontend dependencies." >&2; exit 1; }; \
		npm --prefix frontend ci --no-audit --no-fund; \
	fi
	sh scripts/lint-repository.sh

demo-bundle:
	sh scripts/build-demo-bundle.sh

frontend-build:
	cd frontend && npm ci && npm run build

schema-diagram:
	python3 scripts/gen_schema_diagram.py

schema-diagram-check:
	python3 scripts/gen_schema_diagram.py --check
