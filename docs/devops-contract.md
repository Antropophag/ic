# Эксплуатационный контракт

Production Compose содержит четыре сервиса: `frontend`, `backend`, `scheduler`
и `mariadb`. `frontend` принимает HTTP, раздаёт статический production bundle
и проксирует `/api` и `/health` в PHP-FPM.

`.env.example` — production template. Секреты не коммитятся. `compose.yaml`
не задаёт режим приложения: он передаёт только настройки интеграций из `.env`.

```sh
cp .env.example .env
make doctor
make up
docker compose --env-file .env -f compose.yaml logs
docker compose --env-file .env -f compose.yaml down
```

`make up` при каждом запуске поднимает MariaDB и backend, применяет миграции,
выполняет `php yii admin/bootstrap` и только после их успешного завершения
поднимает frontend и scheduler. Ошибка миграции или bootstrap останавливает
Make с ненулевым кодом; уже поднятые MariaDB/backend остаются доступны для
диагностики. Команда обеспечивает роли `employee` и
`administrator` для пользователей из `BOOTSTRAP_ADMIN_AD_LOGINS`, не хранит
пароли и не меняет LDAP-аутентификацию. Операция идемпотентна и add-only:
удаление логина из конфигурации не отзывает роль, а отключённый локальный
профиль не включается автоматически. Список задаётся через запятую в формате
`sAMAccountName` (латинские буквы, цифры, `.`, `-`, `_`); пробелы вокруг
элементов обрезаются, пустой элемент в непустом списке является ошибкой.
Bootstrap не выполняет LDAP bind/search и не создаёт AD accounts: локальная
запись по настроенному логину подтверждается и обогащается профилем при первом
обычном LDAP-входе. Пустая или отсутствующая переменная означает успешный
пропуск без изменений. Ошибка любого элемента откатывает весь список и делает
`make up` неуспешным. Удаление логина из env не отзывает роль — отзыв выполняется
штатным административным механизмом. Назначения bootstrap записываются с
`assigned_by = NULL`, что означает deployment-оператор без authenticated actor.
Перед записью проверяется наличие production-ролей `employee` и
`administrator`, создаваемых миграциями; bootstrap не создаёт определения
ролей. Параллельный конфликт вставки или deadlock повторяет всю идемпотентную
операцию не более трёх раз с задержкой 50 мс между попытками. Уникальность обеспечивают
case-insensitive индекс `users.ad_login`, индекс `roles.code` и первичный ключ
`user_roles(user_id, role_id)`.

Ручной повтор:

```sh
docker compose --env-file .env -f compose.yaml exec backend php yii admin/bootstrap
```

Прямой `docker compose up -d` не выполняет миграции и bootstrap. Для production
deployment требуется `make up`; при прямом Compose-запуске эквивалентные команды
нужно выполнить явно до запуска frontend и scheduler.

Docker Compose и Podman Compose равноправны. Makefile определяет доступную
реализацию и экспортирует `COMPOSE`/`CONTAINER_ENGINE` в scripts. Публичные
`make logs` и `make down` однозначно относятся к development; production-like
deployment управляется явной Compose-командой выше.

Scheduler запускает `php yii notification/work`, использует тот же backend
image и завершается по SIGTERM с grace period 15 секунд. Разовая ручная
обработка:

```sh
docker compose --env-file .env -f compose.yaml exec backend php yii notification/send
sudo podman-compose --in-pod false --env-file .env -f compose.yaml exec backend php yii notification/send
```

Готовность: `/health/ready`; liveness: `/health/live`. Документы хранятся в
именованном volume, MariaDB — в отдельном volume.
