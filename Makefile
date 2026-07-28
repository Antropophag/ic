.DEFAULT_GOAL := help

.PHONY: help init up down setup check coverage frontend-coverage smoke demo-bundle frontend-build schema-diagram schema-diagram-check

help:
	@echo "up                    Build and start the development stack"
	@echo "init                  Start the stack and apply database migrations"
	@echo "down                  Stop the development stack"
	@echo "setup                 Enable repository Git hooks"
	@echo "check                 Run the same checks as CI before push"
	@echo "coverage              Enforce backend domain/application coverage >= 90%"
	@echo "frontend-coverage     Enforce frontend logic coverage >= 80%"
	@echo "smoke                 Check the running API end-to-end"
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

coverage:
	mkdir -p backend/build/coverage
	docker build --file docker/coverage.Dockerfile --tag shlz-test-registry-coverage .
	docker run --rm --volume "$(CURDIR)/backend/build/coverage:/app/build/coverage" shlz-test-registry-coverage
	python3 scripts/check_coverage.py backend/build/coverage/clover.xml --minimum 90

frontend-coverage:
	npm --prefix frontend ci --no-audit --no-fund
	npm --prefix frontend run coverage

smoke:
	sh scripts/smoke.sh

demo-bundle:
	sh scripts/build-demo-bundle.sh

frontend-build:
	cd frontend && npm ci && npm run build

schema-diagram:
	python3 scripts/gen_schema_diagram.py

schema-diagram-check:
	python3 scripts/gen_schema_diagram.py --check
