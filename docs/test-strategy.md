# Стратегия тестирования

Проект использует только существующие PHPUnit, Vitest и Playwright. Только E2E не
используется: браузер подтверждает крупный маршрут, но дорого и неточно диагностирует ошибки
SQL, optimistic locking, lease/backoff и матрицы переходов.

## Карта контуров

| Набор | Что защищает | Реальный риск | Скорость/стабильность | Решение |
|---|---|---|---|---|
| PHPUnit Unit | workflow, policy, mapper, шаблоны | неверное чистое правило | быстро/стабильно | оставить содержательные таблицы |
| PHPUnit Integration | MariaDB, транзакции, storage, outbox, LDAP | production-интеграции | средне/стабильно | основной backend-слой |
| Vitest | чистое поведение frontend | UI-state и навигация | быстро/стабильно | оставить |
| Playwright | крупные маршруты | компоненты не работают вместе | медленнее | 8 независимых сценариев |
| `smoke.sh` | live/ready и один API-маршрут | образ не запускается | быстро | сокращён с 990 строк |
| Runtime contracts | рестарты AD/MariaDB/SMTP | долгоживущие соединения | медленно | отдельная проверка |

## Матрица сценариев

| Бизнес-сценарий | Основной уровень | Тестовый файл |
|---|---|---|
| LDAP bind, неверный пароль, профиль, disabled/unknown | Integration/runtime | `LdapAuthenticatorTest.php`, `test-runtime-contracts.sh` |
| Роли и запрет чужих операций | Unit + Integration | policy tests, `RequestRepositoryTest.php` |
| Создание, validation, lock, аудит, комментарии | Integration | `RequestRepositoryTest.php` |
| Назначение, пауза, возврат, отказ, отзыв | Unit/Integration | `RequestWorkflowTest.php`, `RequestRepositoryTest.php` |
| Фильтры и пагинация | Integration | `RequestRepositoryTest.php` |
| Upload/MIME/version/download/ACL/storage | Integration | `DocumentRepositoryTest.php` |
| Положительный и отрицательный workflow | E2E | `critical-flow.e2e.js` |
| Outbox, retry, failed recovery, lease | Integration | `NotificationOutboxProcessorTest.php` |
| Реальный SMTP и отсутствие обычного дубля | E2E | `notifications.e2e.js` |
| Редкий SMTP-дубль после send до `sent` | документированная at-least-once семантика | `docs/integrations/notifications.md` |
| Legacy/CSV import и invalid/partial/idempotency | Unit/Integration | import tests |
| Bitrix transport | Unit | `BitrixListClientTest.php` |
| Рестарт MariaDB при worker | Runtime | `test-runtime-contracts.sh` |

## Production-like стенд

```text
Playwright -> gateway -> backend -> MariaDB 11.4
                         |       -> Samba AD DC (IC.TEST)
                         |       -> Mailpit
                         ` scheduler (тот же backend image)
```

`compose.test.yaml` имеет отдельный project name, БД `ic_test` и отдельные test volumes.
Reset зарегистрирован только при `APP_ENV=test` и требует `_test` в имени БД.

Выбран Samba AD: приложение выполняет UPN bind и ищет профиль по `sAMAccountName`.
Приложение не преобразует AD-группы в роли — роли хранятся в MariaDB. Samba проверяет
фактический AD-контракт, который OpenLDAP не воспроизводит полностью.

Домен: `IC.TEST`; общий пароль: `TestPassword1!`. Пользователи: `initiator`, `ic_manager`,
`laboratory_manager`, `executor`, `expert`, `security_officer`, `administrator`,
`employee_without_roles`, `disabled_user`. Группы: `ICManagers`, `LaboratoryManagers`,
`Executors`, `Experts`, `SecurityOfficers`, `Administrators`.

Mailpit UI: <http://localhost:18025>.

## Запуск и диагностика

```sh
make check
make test
make test-env-up
make test-env-reset
make e2e
make test-env-logs
make test-env-down
```

`test/reset` накатывает migrations, seed пользователей, очищает storage и Mailpit. E2E
artifacts находятся в `frontend/test-results` и `frontend/playwright-report`.
`X-Test-User-ID` работает только при `YII_ENV=test`; вне test заголовок игнорируется, а
reset-controller не зарегистрирован.

| Изменение | Какие тесты запускать |
|---|---|
| Workflow | Unit + RequestRepository integration |
| SQL/filter | Integration |
| UI | Frontend unit + соответствующий E2E |
| LDAP | Identity integration + AD runtime/E2E |
| SMTP/outbox | Notification integration + SMTP E2E |
| Compose/infrastructure | Smoke + runtime contracts |

Coverage остаётся метрикой Domain/Application, а не всего backend Infrastructure.
