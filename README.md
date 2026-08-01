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
make init
```

Приложение открывается на `http://localhost:8080`, readiness endpoint —
`http://localhost:8080/health/ready`. Миграции выполняются отдельной
идемпотентной командой, а не автоматически при старте web-контейнера.

Окружения разделены явно:

- `.env` — локальная незакоммиченная production-конфигурация с LDAP-входом;
- `.env.dev` — локальная незакоммиченная конфигурация режима с переключателем
  пользователей, которую создают из безопасного `.env.dev.example` и используют
  в `make up`, `make init` и `make down`;
- `.env.test` — изолированный стенд с настоящим входом через Samba AD,
  test-reset и скрытой test identity для автоматических сценариев.

Production-конфигурацию создают из `.env.example`: `cp .env.example .env`.
Dev-конфигурацию создают отдельно: `cp .env.dev.example .env.dev`, затем
заполняют локальные LDAP/SMTP-параметры.
Файлы `.env*` содержат только значения настроек. Выбор режима выполняет
Compose: `compose.yaml` задаёт `APP_ENV=prod`, `compose.dev.yaml` переопределяет
его на `dev`, а `compose.test.yaml` задаёт `test`.
Режим авторизации не является feature flag: `prod` и `test` показывают LDAP-форму,
а только `dev` включает локальный переключатель. Test identity не появляется в UI.

Dev-пользователи (`dev/seed`) заведены с адресами на зарезервированном
недоставляемом домене `*@example.invalid` — письма о заявках, созданных под
ними, технически «отправляются» (попадают в `notification_outbox` и уходят из
него без ошибки), но реально никуда не долетают. Чтобы при ручном
тестировании полного цикла получать эти письма на свой ящик, укажите его в
`.env.dev`:

```dotenv
NOTIFICATION_TEST_REDIRECT_EMAIL=you@example.com
```

Только для локального/тестового контура — не задавайте эту переменную в
production. Письмо в этом случае реально уходит на указанный адрес: тема
получает пометку `[Тест, настоящий получатель: Имя <email>]`, а тело письма
начинается строкой `Письмо адресовано: ...` с исходным адресатом, за которой
следует оригинальный текст. После изменения `.env.dev` пересоберите и
перезапустите контейнеры (`make up`), чтобы
`backend`/`scheduler` подхватили новое значение. `scheduler` — не отдельное
приложение или микросервис, а долгоживущий PHP CLI-процесс из того же
backend-образа. Команда `notification/work` загружает Yii один раз и
непрерывно обрабатывает outbox; при пустой очереди типичная задержка новой
отправки составляет до 2 секунд.

Разовую обработку одной пачки можно запустить вручную:

```bash
docker compose -f compose.yaml -f compose.dev.yaml --env-file .env.dev exec backend php yii notification/send
```

Быстрые unit и integration-тесты:

```bash
make check
make test
```

Полный изолированный стенд использует production-образы приложения, MariaDB
11.4, Samba AD `IC.TEST`, Mailpit и scheduler:

```bash
make test-env-up
make test-env-reset
make e2e
make test-env-logs
make test-env-down
```

Mailpit UI: `http://localhost:18025`. Пользователи, матрица рисков и
диагностика описаны в `docs/test-strategy.md`.

## Демо на чистой Windows-машине

Если на целевой машине есть доступ к GitHub и Docker Hub, самый быстрый путь —
скачать код (ZIP или `git clone`) и запустить `scripts\demo-up.ps1` прямо из
репозитория: он сам поставит WSL 2/Docker Desktop и соберёт образы из
исходников. Пошагово — раздел «Быстрый путь: клон с GitHub» в
`docs/demo-workstation.md`.

Основной корпоративный сценарий (закрытый контур, TLS inspection) не требует
Composer или npm на демонстрационной машине. Заранее сформируйте автономный
комплект:

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
