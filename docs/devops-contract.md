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
make logs
make down
```

`make up` при каждом запуске поднимает MariaDB и backend, применяет миграции,
выполняет `php yii admin/bootstrap` и только после их успешного завершения
поднимает frontend и scheduler. Ошибка миграции или bootstrap останавливает
Make с ненулевым кодом; уже поднятые MariaDB/backend остаются доступны для
диагностики. Команда обеспечивает роли `employee` и
`administrator` для пользователей из `BOOTSTRAP_ADMIN_AD_LOGINS`, не хранит
пароли и не меняет LDAP-аутентификацию. Операция идемпотентна и add-only:
удаление логина из конфигурации не отзывает роль, а отключённый локальный
профиль не включается автоматически. После первого успешного назначения логин
можно удалить из env: существующий активный administrator удовлетворяет startup-
инварианту, а add-only семантика сохраняет его роль. Список задаётся через запятую в формате
`sAMAccountName` (латинские буквы, цифры, `.`, `-`, `_`); пробелы вокруг
элементов обрезаются, и каждый элемент непустого списка обязан быть непустым.
Поэтому `,`, ` , `, `admin,`, `,admin` и `admin,,other` являются ошибкой
конфигурации до любых изменений БД.
Bootstrap не выполняет LDAP bind/search и не создаёт AD accounts: локальная
запись по настроенному логину подтверждается и обогащается профилем при первом
обычном LDAP-входе. Отсутствующая, пустая или состоящая только из пробелов
переменная означает успешный пропуск без изменений, только если существует
активный локальный пользователь с ролью `administrator`. Если такого пользователя
нет либо роль есть только у отключённых пользователей, bootstrap завершается
ошибкой и `make up` не запускает frontend и scheduler. Ошибка любого элемента
откатывает весь список и делает `make up` неуспешным. Удаление логина из env не отзывает роль — отзыв выполняется
штатным административным механизмом. Назначения bootstrap записываются с
`assigned_by = NULL`, что означает deployment-оператор без authenticated actor.
Перед записью проверяется наличие production-ролей `employee` и
`administrator`, создаваемых миграциями; bootstrap не создаёт определения
ролей. При параллельном конфликте вставки или deadlock вся идемпотентная операция
выполняется до трёх попыток (не более двух повторов) с задержкой 50 мс между
повторами. Уникальность обеспечивают case-insensitive индекс `users.ad_login`,
индекс `roles.code` и первичный ключ `user_roles(user_id, role_id)`.

Ручной повтор:

```sh
docker compose -p shlz-test-registry --env-file .env -f compose.yaml exec backend php yii admin/bootstrap
```

Прямой `docker compose up -d` не выполняет миграции и bootstrap. Для production
deployment требуется `make up`; при прямом Compose-запуске эквивалентные команды
нужно выполнить явно до запуска frontend и scheduler.

Docker Compose и Podman Compose равноправны. Makefile определяет доступную
реализацию и экспортирует `COMPOSE`/`CONTAINER_ENGINE` в scripts. Публичные
`make up`, `make down` и `make logs` однозначно относятся к production-like
deployment (`compose.yaml`, `.env`, project `shlz-test-registry`). Development
управляется отдельными `make dev-up`, `make dev-down` и `make dev-logs`
(`compose.yaml + compose.dev.yaml`, `.env.dev`, project
`shlz-test-registry-dev`). Test использует только `compose.test.yaml`,
`.env.test` и project `ic-test` через `make e2e`.

После изменения `.env` нужно снова выполнить `make up`, а после изменения
`.env.dev` — `make dev-up`: соответствующие контейнеры будут пересозданы с
новым environment. Обычный restart контейнера env-файл не перечитывает.
Разные project names исключают управление чужими контейнерами и orphan
warnings между окружениями. По умолчанию production-like и development оба
публикуют порт `8080`; для их одновременного запуска одному deployment нужен
другой `FRONTEND_PORT` в его env-файле.

Test deployment разделяет build, start, reset и teardown. `make e2e` ровно один
раз собирает актуальные backend/frontend images с layer cache и затем запускает
Compose только с `--no-build`. Scheduler использует тот же image, что backend.
Reset работает только внутри уже поднятого test deployment: очищает БД,
применяет migrations, seed, очищает Mailpit и test storage, не выполняя Docker,
Composer или npm build. Teardown удаляет test containers, network и volumes,
но сохраняет images и caches. Кастомный Samba AD fixture собирается вместе с
первым build только при отсутствии локального image и далее не пересобирается.

Scheduler запускает `php yii notification/work`, использует тот же backend
image и завершается по SIGTERM с grace period 15 секунд. Разовая ручная
обработка:

```sh
docker compose -p shlz-test-registry --env-file .env -f compose.yaml exec backend php yii notification/send
sudo podman-compose --in-pod false -p shlz-test-registry --env-file .env -f compose.yaml exec backend php yii notification/send
```

Готовность: `/health/ready`; liveness: `/health/live`. Документы хранятся в
именованном volume, MariaDB — в отдельном volume.
