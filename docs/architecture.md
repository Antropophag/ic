# Архитектура deployment

## Application architecture

Сейчас код имеет переходную слоистость: Domain остаётся независимым, но часть
Application input models наследует `yii\base\Model`, один document service
обращается к Infrastructure/Yii, а HTTP controllers напрямую собирают и вызывают
Infrastructure. Эти исключения не описывают целевую архитектуру и не означают,
что переход уже завершён.

Целевое направление зависимостей — `Http/Console → Application → Domain`.
Infrastructure реализует persistence, read models и внешние технические границы
Application/Domain; обратные зависимости Application/Domain на Infrastructure
не допускаются. Миграция идёт небольшими волнами по реальным responsibilities.
Текущие исключения перечислены exact baseline автоматического architecture guard:
новое нарушение запрещено, а удалённое исключение требует уменьшить baseline.

Подробности и целевое дерево зафиксированы в
[ADR 0008](adr/0008-layer-boundaries-and-incremental-refactoring.md).

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

Перед production POST-командами заявок и администрирования `ApiController`
создаёт внешнюю транзакционную границу идемпотентности в MariaDB. Вложенные
транзакции repository работают через savepoint, поэтому доменное изменение,
audit/outbox и сохранённый HTTP-ответ фиксируются атомарно. До транзакции
connection-level lock MariaDB по SHA-256 области `actor + method + path + key`
сериализует полностью одновременные claim; unique index той же области остаётся
окончательной защитой, в том числе при смешанном rollout версий. Fingerprint не
позволяет использовать ключ для другого payload. Это инфраструктурный слой:
domain policies, optimistic locking и repository API не знают об HTTP-ключах.

До открытия outer transaction backend canonicalizes JSON либо form fields и
streaming-хеширует временные upload-файлы. Внутри транзакции находятся claim
ключа, файловое копирование, доменная команда, audit, notification outbox и
снимок успешного HTTP-ответа. SMTP worker, LDAP и внешние HTTP-вызовы этой
транзакцией не охватываются. Crash процесса после rename файла, но до DB commit,
может оставить orphan: обычный exception/rollback компенсируется немедленным
удалением, а hard crash требует эксплуатационной сверки storage с БД.

Expired cleanup использует `idx_idempotency_expiry`, запускается вероятностно и
ограничен 100 строками. Unique index `actor_id + http_method + route + key_hash`
страхует сериализацию одинаковых ключей; ожидающий запрос после commit либо
replay-ит результат, либо получает `409` при другом fingerprint. Ожидание lock
ограничено 30 секундами, после чего возвращается безопасный `409`. Если первая транзакция
откатывается, её claim исчезает и ожидающий retry может стать новой попыткой.
Контролируемый `4xx` после отката repository savepoint удаляет claim, но
фиксирует намеренный denied audit; неожиданный exception и любой `5xx`
откатывают claim, audit и остальные DB-эффекты внешней транзакции.

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
