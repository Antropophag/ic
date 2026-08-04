# Стратегия тестирования

Проект использует три framework: PHPUnit, Vitest и Playwright. Основной
backend-уровень — Integration с настоящей MariaDB; браузер защищает только
крупные пользовательские маршруты.

| Уровень | Что защищает | Команда |
|---|---|---|
| PHPUnit Unit | чистые workflow, policy, mapper, шаблоны | `make check` |
| PHPUnit Integration | SQL, транзакции, документы, outbox, LDAP adapter | `make e2e` |
| Vitest | frontend-логика и API client | `make check` |
| Playwright | вход и критические маршруты через реальный `frontend` | `make e2e` |
| Runtime contracts | LDAP/SMTP/MariaDB/scheduler recovery | `make e2e` |

## Единый стенд

```text
Playwright
    -> frontend
        -> backend
            -> MariaDB 11.4
            -> Samba AD (IC.TEST)
            -> Mailpit
    -> scheduler -> notification outbox
```

`compose.test.yaml` и `.env.test` являются единственным test deployment.
Integration не создаёт отдельную MariaDB или специальную Docker network.
Reset выполняет миграции с нуля, очищает test storage и Mailpit, затем
идемпотентно загружает пользователей. Защита reset основана на имени БД с
`_test` и точном test storage path. Reset не собирает images и не устанавливает
Composer/npm dependencies; без запущенного backend он завершается ошибкой.

Test identity физически подключён файлом `deployment/test/web.php`. В обычном
и development deployment заголовок `X-Test-User-ID` не настроен. Dev API,
наоборот, отсутствует в test и production конфигурациях.

Основной bootstrap проверяет только фиксированные пути
`/app/deployment/web.php` и `/app/deployment/console.php`. Production-образ не
содержит эти файлы; development и test монтируют разные read-only fragments
вне web root. Test console fragment содержит только `test/reset`, development
console fragment — только `dev/seed`.

| Возможность | Production | Development | Test |
|---|---:|---:|---:|
| Dev endpoint и identity | нет | да | нет |
| Test identity/reset | нет | нет | да |
| Standalone dev frontend script | нет | да | нет |

## Полный прогон

```sh
make test
```

Порядок `make e2e`: один build backend/frontend images, последовательный запуск
MariaDB, Samba AD, Mailpit и backend, reset до запуска frontend/scheduler,
health checks, backend Integration, повторный reset, Playwright и затем
разрушающие runtime contracts. При повторном reset frontend и scheduler
останавливаются на время замены схемы и возвращаются в рабочее состояние.
При любом исходе teardown удаляет containers, network и test volumes, но
сохраняет images, dependency caches и Playwright diagnostics.

Runtime contracts проверяют реальный LDAP bind и группы, восстановление LDAP,
SMTP failure/recovery, reconnect scheduler после рестарта MariaDB и SIGTERM.
Playwright artifacts находятся в `frontend/playwright-report`.

| Изменение | Какие проверки запускать |
|---|---|
| Workflow/policy | `make check` и соответствующий Integration |
| SQL, migration, filter | `make e2e` |
| UI | Vitest и соответствующий Playwright |
| LDAP | Identity Integration и runtime contracts |
| SMTP/outbox | Notification Integration и runtime contracts |
| Compose/infrastructure | `make e2e` |

## Frontend test contour

До инфраструктурного рефакторинга Vitest выполнял 101 тест. Удалены ровно 12
тестов вместе с двумя больше не существующими production-модулями:

| Удалённый файл | Тестов | Что защищал | Текущее покрытие |
|---|---:|---|---|
| `src/devUsers.test.js` | 7 | встроенный в Vue выбор dev identity | 4 теста физически отдельного `dev/dev-tools.js` плюс development runtime contract |
| `src/demoSeed.test.js` | 5 | destructive demo seed UI | demo deployment и функция удалены, заменяемого production-поведения нет |

Production Vitest-набор сохранил 89 тестов. Standalone development tools
добавляют ещё 4, поэтому `npm test`/`make check` выполняют 93 теста: загрузка
безопасного списка, отказ endpoint, same-origin identity header,
`localStorage`, отображение ролей и переключение после reload.
