# HTTP API

Все публичные маршруты доступны через сервис `frontend` под `/api/v1`.
`GET /api/v1/auth/me` возвращает CSRF token и текущего пользователя:

```json
{"csrfToken":"...","user":null}
```

Обычная авторизация использует `POST /api/v1/auth/login` с LDAP login/password
и серверную session cookie. Тот же endpoint принимает заранее настроенный
break-glass логин, но локальная проверка выполняется только при точном совпадении
логина. Неверный пароль для совпавшего логина возвращает тот же общий `401`
`Неверный логин или пароль`, что и отклонённая LDAP-аутентификация, без
специального признака аварийной identity. Все остальные логины всегда передаются
LDAP, включая случаи недоступности каталога. Успешный локальный вход создаёт ту
же session cookie, что LDAP-вход.

POST-маршруты, принимающие DTO в JSON, требуют непустое тело в формате JSON
object с `Content-Type: application/json` (параметр `charset` допускается):

- отсутствующий или другой `Content-Type` возвращает `415`;
- пустое или некорректное JSON-тело возвращает `400`;
- JSON array, scalar и `null` возвращают `400`;
- `{}` проходит разбор JSON object и передаётся в DTO validation;
- корректный JSON object, не прошедший валидацию DTO, сохраняет прежний ответ
  `422` в формате `{"errors": {...}}`.

Маршруты без DTO body, включая logout и отзыв роли, этого тела не требуют.
Загрузка документов и отчётов использует `multipart/form-data`.

Development deployment дополнительно монтирует маршруты:

- `GET /api/v1/dev/users` — безопасный список активных seeded users;
- `POST /api/v1/dev/seed-requests` — подготовка development-данных; запрос
  требует выбранного активного пользователя с ролью `administrator`.

Они отсутствуют в production и test route table. Header `X-Dev-User-ID`
принимается только когда `compose.dev.yaml` смонтировал development
конфигурацию. Аналогично `X-Test-User-ID` физически подключается только
`compose.test.yaml` и не является частью production-конфигурации.

Бизнес-маршруты заявок используют optimistic lock version и возвращают
структурированные JSON ошибки. Полный контракт закреплён Integration и
Playwright тестами.

## Read-only журналы администратора

Только активный пользователь с ролью `administrator` может читать журналы.
Оба ответа имеют форму `{items, hasMore, nextCursor}`, сортируются по
`createdAt DESC, id DESC` и используют непрозрачный `cursor`; `limit` по
умолчанию 50 и не может превышать 100.

`GET /api/v1/admin/audit-events` принимает `actorId`, `eventType`,
`entityType`, `entityId`, `requestId`, `result=all|denied`,
`dateFrom`, `dateTo`, `limit`, `cursor`. Даты имеют формат `YYYY-MM-DD`.
Элемент содержит raw `eventType`, title, actor, entity, ruleId, result и
whitelist `details`. Полный `payload_json` не выдаётся. Причины, тексты,
токены и внутренние данные исключены; неизвестный тип становится безопасным
«Системным событием» без details.

`GET /api/v1/admin/notifications` принимает `status`, `requestId`,
`eventType`, `recipient`, `dateFrom`, `dateTo`, `problematic=1`, `limit`,
`cursor`. Допустимые статусы: `pending`, `sending`, `sent`, `failed`.
`health` равен `failed` для окончательной ошибки, `stale` для `sending` с
истёкшим lease либо `pending`, просроченного более чем на 300 секунд (текущий
размер lease worker), `retrying` при более чем одной попытке, иначе `normal`.
Поле `body` не выбирается. Raw `last_error` не раскрывается: API возвращает
нейтральное «Ошибка SMTP».
