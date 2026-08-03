# HTTP API

Все публичные маршруты доступны через сервис `frontend` под `/api/v1`.
`GET /api/v1/auth/me` возвращает CSRF token и текущего пользователя:

```json
{"csrfToken":"...","user":null}
```

Обычная авторизация использует `POST /api/v1/auth/login` с LDAP login/password
и серверную session cookie.

Все POST-запросы этих контроллеров принимают непустой JSON object с
`Content-Type: application/json` (параметр `charset` допускается):

- отсутствующий или другой `Content-Type` возвращает `415`;
- пустое или некорректное JSON-тело возвращает `400`;
- JSON array, scalar и `null` возвращают `400`;
- корректный JSON object, не прошедший валидацию DTO, сохраняет прежний ответ
  `422` в формате `{"errors": {...}}`.

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
