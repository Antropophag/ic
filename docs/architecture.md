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

Development identity — обычная запись `users` и ролей в MariaDB. Отдельный
dev script получает безопасный список (`id`, отображаемое имя, должность),
сохраняет выбор в браузере и добавляет настроенный deployment-ом header.

Notification scheduler не является микросервисом: это долгоживущая Yii CLI
команда из того же образа backend. Семантика SMTP — at-least-once; outbox lease
не делает внешний SMTP идемпотентным.
