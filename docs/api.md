# HTTP API

Все публичные маршруты доступны через сервис `frontend` под `/api/v1`.
`GET /api/v1/auth/me` возвращает CSRF token и текущего пользователя:

```json
{"csrfToken":"...","user":null}
```

Обычная авторизация использует `POST /api/v1/auth/login` с LDAP login/password
и серверную session cookie.

Development deployment дополнительно монтирует маршруты:

- `GET /api/v1/dev/users` — безопасный список активных seeded users;
- `POST /api/v1/dev/seed-requests` — подготовка development-данных.

Они отсутствуют в production и test route table. Header `X-Dev-User-ID`
принимается только когда `compose.dev.yaml` смонтировал development
конфигурацию. Аналогично `X-Test-User-ID` физически подключается только
`compose.test.yaml` и не является частью production-конфигурации.

Бизнес-маршруты заявок используют optimistic lock version и возвращают
структурированные JSON ошибки. Полный контракт закреплён Integration и
Playwright тестами.
