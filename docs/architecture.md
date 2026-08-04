# Архитектура deployment

Во всех окружениях запускается одна кодовая база:

```text
frontend (Nginx + production Vue build)
    -> backend (PHP-FPM/Yii)
        -> MariaDB
scheduler (тот же backend image, php yii notification/work)
```

Различия окружений не зашиты в приложение. `compose.dev.yaml` физически
подключает development target фронтенда и файлы `deployment/dev/*`.
`compose.test.yaml` подключает `deployment/test/*`, Samba AD и Mailpit.
Production не получает ни одного из этих файлов.

Чтение заявок для HTTP/UI разделено без дополнительного слоя: `RequestController`
обращается к `RequestQuery` за реестром, карточкой, комментариями и списками
исполнителей/экспертов. Изменяющие транзакционные операции, аудит и постановка
уведомлений в outbox остаются в `RequestRepository`.

Development identity — обычная запись `users` и ролей в MariaDB. Development
bootstrap до запуска Vue устанавливает fetch interceptor; dev-модуль получает
безопасный список (`id`, отображаемое имя, должность), сохраняет выбор в браузере
и добавляет настроенный deployment-ом header.

Break-glass identity — отдельная стабильная техническая запись `users` с
единственной штатной ролью `administrator`; при включённой конфигурации
deployment provisioner создаёт её после migrations и обычного seed/bootstrap,
не сохраняя пароль или его hash. При выключенной конфигурации provisioner не
обращается к данным identity.
Перед LDAP-маршрутом типизированная конфигурация сравнивает введённый
логин с единственным настроенным emergency login. Точное совпадение направляется
в изолированный `BreakGlassAuthenticator`, остальные значения — без fallback в
существующий `LdapAuthenticator`. Оба успешных пути используют общую session
логику `AuthController`, а активность identity проверяется `CurrentUser` на
каждом запросе.

Notification scheduler не является микросервисом: это долгоживущая Yii CLI
команда из того же образа backend. Семантика SMTP — at-least-once; outbox lease
не делает внешний SMTP идемпотентным.
