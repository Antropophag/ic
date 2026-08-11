# Эксплуатационный контракт

Локальный deployment имеет два пользовательских режима: development и
production. Test — внутренний одноразовый контур `make e2e`. Все режимы
работают из одной рабочей папки, но используют разные project names, env-файлы
и volumes.

## Локальная разработка

```sh
make init
make dev-up
make dev-status
make dev-restart
make dev-logs SERVICE=backend
make env-status
```

Development использует project `ic-dev`, `.env.dev`, `compose.yaml` и
`compose.dev.yaml`. `dev-up` выполняет build, поднимает сервисы, применяет
migrations, seed и break-glass provisioning. `dev-down` удаляет containers и
network, сохраняя named volumes. `dev-reset` явно удаляет volumes project
`ic-dev`, после чего выполняет полный `dev-up`.

## Промышленная эксплуатация

Production Compose содержит `frontend`, `backend`, `scheduler` и `mariadb`.
`frontend` принимает HTTP, раздаёт production bundle и проксирует `/api` и
`/health` в PHP-FPM. Используются только project `ic-prod`, `compose.yaml` и
`.env.prod`; `.env.example` остаётся шаблоном без секретов.
Backend runtime требует `ext-pcntl`: административная LDAP StartTLS probe
использует process alarm, чтобы гарантированно завершаться по timeout. Штатный
backend image устанавливает и проверяет это расширение при build.

```sh
cp .env.example .env.prod
# заполнить настройки
make prod-up
make prod-status
make prod-restart
make prod-logs
make prod-logs SERVICE=backend
make prod-down
```

Lifecycle-команды выводят только этапы операции, readiness ключевых сервисов и
пользовательские URL. Полный Compose output временно сохраняется и печатается
при ошибке с исходным exit code. `dev-logs`/`prod-logs` остаются нативными
потоковыми `compose logs --follow`; `SERVICE=backend` ограничивает вывод одним
сервисом. `LOG_TAIL` задаёт неотрицательное число последних строк либо `all` и
по умолчанию равен `100`. Цвет отключается автоматически без TTY, при
`TERM=dumb`, в CI и с `NO_COLOR=1`; `FORCE_COLOR=1` включает его явно. При timeout readiness команда
показывает фактический статус, последние 50 строк логов и завершается ошибкой.
MariaDB проверяется штатным database healthcheck. Frontend healthcheck обращается
к `/health/ready` через nginx и тем самым проверяет reverse proxy, backend, БД и
доступность document storage; backend и scheduler дополнительно должны оставаться
в состоянии `running`. `healthcheck.start_interval` требует Docker Compose
2.20.2+ и Docker Engine 25.0+ (API 1.44+); эквивалентный Podman provider должен
поддерживать это поле Compose specification.

`prod-up` сначала выполняет явный build backend, scheduler и frontend. Только
после успешного build сервисы запускаются с `--no-build`: backend не может быть
обновлён отдельно от frontend. Затем команда применяет миграции,
выполняет идемпотентный `php yii admin/provision-break-glass`, затем
`php yii admin/bootstrap` и только после их успешного завершения
поднимает frontend и scheduler. Ошибка migration или bootstrap останавливает
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
ошибкой и `prod-up` не запускает frontend и scheduler. Ошибка любого элемента
откатывает весь список и делает `prod-up` неуспешным. Удаление логина из env не отзывает роль — отзыв выполняется
штатным административным механизмом. Назначения bootstrap записываются с
`assigned_by = NULL`, что означает deployment-оператор без authenticated actor.
Перед записью проверяется наличие production-ролей `employee` и
`administrator`, создаваемых миграциями; bootstrap не создаёт определения
ролей. При параллельном конфликте вставки или deadlock вся идемпотентная операция
выполняется до трёх попыток (не более двух повторов) с задержкой 50 мс между
повторами. Уникальность обеспечивают case-insensitive индекс `users.ad_login`,
индекс `roles.code` и первичный ключ `user_roles(user_id, role_id)`.

После релиза контракта `Idempotency-Key` уже открытый в браузере старый bundle
не совместим с изменяющими API: backend отклонит его POST с `422` до доменной
операции. Это безопасный отказ, а не fallback без защиты. Перед обновлением
следует уведомить пользователей о необходимости сохранить черновики и
перезагрузить вкладки после завершения `prod-up`; при обращении с ошибкой `422`
первым действием является reload страницы. Одновременная сборка образов
сокращает окно между backend и frontend, но не обновляет уже открытые вкладки.

Ручной повтор:

```sh
COMPOSE_ENV_FILE=.env.prod docker compose -p ic-prod --env-file .env.prod -f compose.yaml exec backend php yii admin/bootstrap
```

Прямой `docker compose up -d` не выполняет миграции и bootstrap. Для production
deployment требуется `make prod-up`; при прямом Compose-запуске эквивалентные команды
нужно выполнить явно до запуска frontend и scheduler.

## Аварийный break-glass вход

Break-glass предназначен только для восстановления административного доступа
при недоступности LDAP/AD. Он не заменяет LDAP, не является повседневной
административной учётной записью и никогда не включается автоматически после
ошибки каталога. Введённый логин должен в точности, включая регистр, совпасть с
`BREAK_GLASS_LOGIN`; любой другой логин обрабатывается только LDAP.
Зарезервированное внутреннее значение `__break_glass__` запрещено использовать
как `BREAK_GLASS_LOGIN` и никогда не передаётся LDAP.

При включённой конфигурации deployment-команда `admin/provision-break-glass`
идемпотентно создаёт одну стабильную техническую identity `__break_glass__`
перед обычным bootstrap и оставляет ей только существующую роль
`administrator`. При пустых обоих значениях команда успешно завершается без
создания или изменения identity. Пароля и password hash в таблице `users` нет.
Аутентификация включена только когда одновременно заданы:

```dotenv
BREAK_GLASS_LOGIN=emergency.admin
BREAK_GLASS_PASSWORD_HASH='$2y$...'
```

Одинарные кавычки обязательны для значения в Compose env-файле: они сохраняют
символы `$` внутри password hash без interpolation. Не используйте двойные
кавычки и не подставляйте открытый пароль. Пустые оба значения выключают вход;
неполная конфигурация или строка, не распознанная `password_verify()`, приводит
к fail-closed отказу и security-событию.

### Генерация и передача секрета

Создайте длинный уникальный пароль на доверенной административной машине и не
передавайте его через аргументы процесса или shell history. Следующая команда
считывает пароль без отображения, передаёт его PHP через stdin и печатает только
hash, совместимый с `password_verify()`:

```sh
read -r -s -p 'Break-glass password: ' break_glass_secret
printf '\n'
break_glass_hash=$(printf '%s' "$break_glass_secret" |
  docker run --rm -i php:8.3-cli php -r \
    '$p = stream_get_contents(STDIN); echo password_hash($p, PASSWORD_DEFAULT), PHP_EOL;')
unset break_glass_secret
printf '%s\n' "$break_glass_hash"
unset break_glass_hash
```

Поместите результат в одинарных кавычках в защищённую/masked переменную
deployment либо в локальный `.env.prod`, недоступный из Git. Открытый пароль передайте
ответственным лицам по отдельному одобренному защищённому каналу; рекомендуется
dual control и хранение в корпоративном secrets vault с журналом доступа.

### Включение, проверка, отключение и ротация

1. Задайте уникальный `BREAK_GLASS_LOGIN` и password hash в `.env.prod`.
2. Выполните штатный `make prod-up`: он применит migrations, подготовит техническую
   identity и затем проверит administrator bootstrap.
3. При последующих изменениях переменных повторяйте `make prod-up`, чтобы backend
   получил новое environment. Обычный restart уже созданного контейнера env-файл
   не перечитывает.
4. В согласованное окно откройте обычную форму входа и войдите настроенным
   логином. Проверьте доступ к административной панели и событие
   `authentication.break_glass_succeeded` в «Журнале действий».
5. Завершите сессию. После аварийного использования немедленно сгенерируйте
   новый пароль/hash, обновите защищённую переменную и снова выполните `make prod-up`.
6. Для отключения очистите обе переменные и пересоздайте backend через
   `make prod-up`. Это блокирует новые входы, но уже выданная штатная session живёт
   до logout/истечения либо отключения технической identity. Не удаляйте
   техническую строку напрямую из БД.

Пять попыток с неверным паролем с одного IP либо двадцать попыток суммарно за
15 минут временно блокируют дальнейшую локальную проверку; отклонённые во время
блокировки запросы не продлевают окно, а внешняя ошибка остаётся общей. Счётчики
хранятся в отдельной таблице общей БД, обновляются внутри сериализованной
транзакции и действуют для всех backend-процессов и контейнеров. Приложение
использует `REMOTE_ADDR`, переданный штатным nginx, и не доверяет произвольному
`X-Forwarded-For`. События
`authentication.break_glass_succeeded`, `authentication.break_glass_denied` и
`authentication.break_glass_configuration_error` находятся в read-only журнале
администратора. Они содержат техническую identity, время, IP, нормализованный
User-Agent и безопасный классификатор причины, но не пароль, hash или LDAP
credentials. Успешное использование дополнительно записывается как security
warning в container logs.

## Тесты

Test использует только project `ic-test`, `compose.test.yaml` и tracked
`.env.test`. Публичная точка входа — `make e2e`; внутренние `_test-*` нужны CI
для orchestration и гарантированного cleanup, но не образуют ручной lifecycle.
CI-конфигурация и test images `shlz-test-registry-test-*` не изменены.

Docker Compose и Podman Compose равноправны. Makefile определяет provider и
передаёт scripts project/env metadata. Неоднозначные `make up`, `make down` и
`make logs` всегда завершаются ошибкой с рекомендацией выбрать `dev-*` или
`prod-*`.

После изменения `.env.prod` нужно снова выполнить `make prod-up`, а после изменения
`.env.dev` — `make dev-up`: соответствующие контейнеры будут пересозданы с
новым environment. Обычный restart контейнера env-файл не перечитывает.
`APP_VERSION`, `APP_COMMIT_SHA` и `APP_BUILD_TIMESTAMP` описывают собранный
artifact и могут быть переданы deployment-командой поверх env-файла. Их
автоматическое формирование CI/CD в текущий контракт не входит.
Разные project names исключают управление чужими контейнерами и orphan
warnings между окружениями. Env-файлы являются источником Compose metadata:
production использует `COMPOSE_PROJECT_NAME=ic-prod`, `COMPOSE_FILE=compose.yaml`
и `FRONTEND_PORT=8080`; development — `COMPOSE_PROJECT_NAME=ic-dev`, оба
Compose-файла и `FRONTEND_PORT=8081`. Поэтому оба окружения можно одновременно
запустить прямыми командами `docker compose --env-file .env.prod up -d` и
`docker compose --env-file .env.dev up -d`. Make lifecycle остаётся
предпочтительным, потому что дополнительно выполняет build, migrations, seed и
provisioning. Backend, scheduler и MariaDB наружу не публикуются; Swagger UI
доступен через frontend port. Для уже существующих локальных env-файлов новые
`COMPOSE_PROJECT_NAME`, `COMPOSE_FILE`, `COMPOSE_ENV_FILE` и `FRONTEND_PORT`
нужно перенести из соответствующего example без замены секретов.

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
COMPOSE_ENV_FILE=.env.prod docker compose -p ic-prod --env-file .env.prod -f compose.yaml exec backend php yii notification/send
sudo env COMPOSE_ENV_FILE=.env.prod podman-compose --in-pod false -p ic-prod --env-file .env.prod -f compose.yaml exec backend php yii notification/send
```

Готовность: `/health/ready`; liveness: `/health/live`. Документы хранятся в
именованном volume, MariaDB — в отдельном volume.

## Жизненный цикл данных

- `dev-up`, `prod-up` и соответствующие `down` не удаляют volumes;
- `dev-reset` без скрытых шагов выполняет `down --volumes`, затем новый
  `dev-up`; это штатный способ получить чистую development БД;
- `prod-reset` требует вручную ввести `ic-prod`, затем выполняет
  `down --volumes` и полный `prod-up`; это аварийная разрушительная операция,
  не средство обновления;
- `make e2e` всегда удаляет test volumes в cleanup;
- `env-status` читает только имя БД и Compose metadata, не печатает env,
  credentials или значения секретов.

### Миграция старых Compose project names

Смена project name намеренно не переименовывает и не удаляет volumes. Старые
projects `shlz-test-registry` и `shlz-test-registry-dev` можно вернуть до
завершения миграции, поэтому rollback остаётся доступным.

1. До обновления остановите старый контур **без** `--volumes`:

   ```sh
   COMPOSE_ENV_FILE=.env docker compose -p shlz-test-registry --env-file .env -f compose.yaml down
   COMPOSE_ENV_FILE=.env.dev docker compose -p shlz-test-registry-dev --env-file .env.dev \
     -f compose.yaml -f compose.dev.yaml down
   ```

2. Для production сделайте штатный backup MariaDB и отдельный backup volume
   `shlz-test-registry_document-data`. Создайте `.env.prod`, выполните
   `make prod-up`, затем восстановите DB и документы в
   `ic-prod_mariadb-data`/`ic-prod_document-data`. Не копируйте живой datadir.
3. Development данные обычно не переносятся: `make dev-reset` создаёт чистую
   `ic-dev` БД и seed. Если данные нужны, примените тот же backup/restore между
   `shlz-test-registry-dev_*` и `ic-dev_*`.
4. Проверьте `make env-status`, `/health/ready`, вход администратора и документы.
5. Только после подтверждения и истечения rollback-окна удалите старые volumes
   явными командами `docker volume rm`; `prod-up`/`dev-up` этого не делают.

Для rollback остановите новый project без volumes и снова запустите старую
команду Compose с исходным env-файлом. Production migration должна выполняться
согласно действующему backup/restore runbook; `prod-reset` для переноса данных
использовать нельзя.
