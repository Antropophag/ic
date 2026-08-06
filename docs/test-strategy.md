# Стратегия тестирования

Проект использует три framework: PHPUnit, Vitest и Playwright. Основной
backend-уровень — Integration с настоящей MariaDB; браузер защищает только
крупные пользовательские маршруты.

| Уровень | Что защищает | Команда |
|---|---|---|
| PHPUnit Unit | чистые workflow, policy, mapper, шаблоны | `make check` |
| PHPUnit Integration | SQL, транзакции, документы, outbox, LDAP и break-glass auth | `make e2e` |
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
make check
make e2e
```

Порядок `make e2e`: один build backend/frontend images, последовательный запуск
MariaDB, Samba AD, Mailpit и backend, reset до запуска frontend/scheduler,
health checks, backend Integration, повторный reset, Playwright и затем
разрушающие runtime contracts. При повторном reset frontend и scheduler
останавливаются на время замены схемы и возвращаются в рабочее состояние.
При любом исходе teardown удаляет containers, network и test volumes, но
сохраняет images, dependency caches и Playwright diagnostics.

Playwright сначала запускает обычные сценарии в параллельном проекте
`chromium`. После их успешного завершения зависимый проект
`stateful-chromium` одним worker последовательно выполняет
`idempotency.e2e.js` и `notifications.e2e.js`, чтобы они не конкурировали за
общее состояние тестовой БД. Если `chromium` падает, зависимый проект
пропускается. Полный порядок воспроизводится командой `make e2e`; при уже
поднятом test-окружении отдельный проект можно запустить из `frontend` командой
`npx playwright test --project=stateful-chromium --no-deps`.

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
| Пользовательские тексты | Vitest/PHPUnit для текстовых контрактов и соответствующий Playwright для критического сценария |

## Проверка пользовательских текстов

Автоматические тесты фиксируют точную формулировку только там, где текст является
частью продуктового контракта: сообщает статус, ограничение, необратимое
последствие или следующий шаг. Для обычной навигации Playwright предпочитает
доступные роли и имена контролов, а не случайные фрагменты пояснительного текста.

При изменении пользовательского сценария проверяются:

- подпись действия, подтверждение и сообщение о результате;
- ошибки валидации, пустые и недоступные состояния;
- тема и ключевой призыв к действию в email-уведомлении;
- ключевые подписи и экранирование данных в формируемом PDF;
- связанная HTML-инструкция и демонстрационные fixtures;
- отсутствие прежней терминологии в production-коде и E2E-сценариях.

## Frontend test contour

Vitest проверяет production-модули и физически отдельный development contour.

| Файлы | Что защищают | Дополнительный runtime contract |
|---|---|---|
| `dev/dev-tools.test.js`, `src/bootstrap.test.js` | dev identity interceptor, same-origin header и его установка до первого application request | dev-код присутствует только в development image и отсутствует в production image |
| `src/*.test.js` | API client, реестр, deep links, guards и диалоги | production build и Playwright-сценарии |

Удалённый destructive demo seed UI не имеет заменяемого production-поведения;
development seed проверяется отдельным deployment contract.
