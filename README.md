# Регистратор заявок на проведение испытаний АО «ЩЛЗ»

Внутренний корпоративный портал для регистрации заявок, проведения испытаний,
формирования экспертного заключения и контроля службы безопасности.

## Структура

- `docs/product-spec.md` — согласованные требования и бизнес-процесс;
- `docs/architecture.md` — архитектура, модель данных и интеграции;
- `docs/data-model.md` — автоматически сгенерированная ER-диаграмма MariaDB;
- `docs/api.md` — версионируемый контракт HTTP API;
- `docs/business-rules.md` — нумерованные бизнес-правила;
- `docs/test-strategy.md` — автоматические проверки и quality gates;
- `docs/engineering-standards.md` — правила кода, документации и Definition of Done;
- `docs/roadmap.md` — укрупнённый план дальнейших работ;
- `docs/devops-contract.md` — требования к готовому Docker-окружению;
- `docs/adr/` — журнал архитектурных решений;
- `docs/on-premise-handoff.md` — перенос в локальный GitLab и закрытый контур;
- `docs/ai-review.md` — локальный AI-review и политика merge request;
- `tests/acceptance/` — приёмочные сценарии бизнес-процесса;
- `prototype/` — кликабельный UX-прототип на Vue 3;
- `frontend/` — рабочая копия согласованного интерфейса;
- `backend/` — Yii2 API, миграции и изолированная доменная логика.

## Актуализация ER-диаграммы

После применения миграций к локальной MariaDB:

```bash
make schema-diagram
```

Генератор читает `information_schema` реально развёрнутой БД. Проверка
`make schema-diagram-check` предназначена для quality gate в GitLab CI.

## Проверки до push

Один раз после клонирования включите версионируемые Git hooks:

```bash
make setup
```

После этого `git push` автоматически запускает `make check`. Та же сборка и
unit-тесты выполняются в merge request pipeline локального GitLab.

## Запуск прототипа

```bash
cd prototype
npm install
npm run dev
```

После запуска Vite выведет локальный адрес приложения.

## Запуск рабочего контура

```bash
cp .env.example .env
make init
```

Приложение открывается на `http://localhost:8080`, readiness endpoint —
`http://localhost:8080/health/ready`. Миграции выполняются отдельной
идемпотентной командой, а не автоматически при старте web-контейнера.

Проверка работающего контура с созданием тестовой заявки:

```bash
make smoke
```

## Демо на чистой Windows-машине

Основной корпоративный сценарий не требует Composer или npm на демонстрационной
машине. Заранее сформируйте автономный комплект:

```bash
make demo-bundle
```

Перенесите папку `dist-demo` на рабочую машину и запустите PowerShell от имени
администратора:

```powershell
Set-ExecutionPolicy -Scope Process Bypass
.\demo-up.ps1 -InstallPrerequisites
```

После установки и обязательной перезагрузки повторите команду без параметра:

```powershell
.\demo-up.ps1
```

Скрипт создаст локальные секреты, загрузит готовые Docker-образы, применит
миграции и откроет портал на `http://localhost:8080`. Подробности и варианты для
машины с ограниченным интернетом описаны в `docs/demo-workstation.md`.
