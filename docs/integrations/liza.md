# AI-пилот обработки ТЗ через ЛИЗУ

Статус: **PILOT / INTERNAL PILOT**. Функция предназначена для внутренней
проверки гипотезы и не готова к промышленному использованию. Главный blocker —
авторизация пользовательским JWT Open WebUI вместо поддерживаемой
server-to-server identity.

Это единый технический источник знаний об интеграции. Пользовательский сценарий
описан в [инструкции карточки заявки](../user/request-card.md), причины выбора
границ — в [ADR 0009](../adr/0009-liza-technical-specification-pilot.md), HTTP-
контракт портала — в [OpenAPI](../../openapi/openapi.yaml).

## Назначение и пользовательский сценарий

В карточке заявки действие «Обработать с помощью ИИ» находит актуальное ТЗ среди
доступных пользователю документов. Пилот поддерживает PDF и DOCX; при нескольких
кандидатах пользователь выбирает один документ, при отсутствии — получает
понятное сообщение.

Одна команда параллельно и независимо запускает две операции:

1. Анализ исходного ТЗ.
2. Формирование черновика ТЗ на испытания.

Результаты появляются по готовности в соседних вкладках. У каждой задачи свои
loading/success/error, chat, upload и idempotency record; ошибка одной не отменяет
другую. AI ничего не изменяет в заявке или документах, результаты справочны и
требуют проверки специалистом.

Анализ имеет шесть обязательных секций:

- «Критические противоречия» (`criticalContradictions`);
- «Неоднозначные или непроверяемые требования» (`ambiguousRequirements`);
- «Недостающая информация» (`missingInformation`);
- «Требования, требующие испытаний» (`testRequirements`);
- «Вопросы инициатору» (`initiatorQuestions`);
- «Рекомендации» (`recommendations`).

Application layer принимает только JSON-объект со всеми шестью ключами и типом
`list<string>` для каждой секции. Отсутствующий ключ, неверный тип или элемент не
строка дают контролируемую ошибку; malformed response не становится успешным
«Не выявлено».

Черновик — самостоятельная задача: получает тот же исходный документ, использует
RAG ЛИЗЫ и формирует текст ТЗ на испытания с объектом, целью, проверяемыми
характеристиками, критериями, методами, условиями/оборудованием, исходной
документацией и открытыми вопросами, когда эти сведения следуют из источников.
Analysis chat и draft chat полностью независимы: draft не использует conversation
или результат анализа.

## Архитектура и ответственность

```text
Frontend / TechnicalSpecificationAi
        |
        +--> AnalyzeTechnicalSpecification
        |         |
        |         +--> LizaPort
        |
        +--> CreateTestSpecificationDraft
                  |
                  +--> LizaPort
                           |
                           v
                  OpenWebUiLizaAdapter
                           |
                           v
                  Open WebUI / ЛИЗА / RAG
```

- Frontend запускает обе операции, ведёт независимые состояния, передаёт
  `AbortSignal`, показывает выбор документа и результаты. Cache ключуется
  authenticated principal + request и полностью очищается с abort активных
  запросов при logout/login/change principal. Повторный запуск задачи отменяет
  её прежний frontend request. Закрытие модального окна, unmount компонента и
  смена заявки отменяют обе активные задачи и инвалидируют их поздние ответы;
  server-side deadline действует независимо от frontend cancellation.
- Application содержит два use case, выбор/проверку документа, строгую валидацию
  анализа и семантику conversation. Здесь нет знания об Open WebUI.
- `AiIdempotencyStore` выполняет специализированный AI lifecycle и ограничивает
  конкуренцию. Generic command-side `IdempotencyStore` не изменён.
- Infrastructure повторно применяет document ACL, читает приватный файл,
  реализует Open WebUI HTTP/Socket.IO, upload/delete, persisted polling,
  cleanup queue и технические conversation records. Credential остаётся здесь.

## Протокол Open WebUI

Это implementation detail `OpenWebUiLizaAdapter`, не контракт Application API:

1. Transport начинает единый monotonic deadline.
2. Устанавливает настоящий Socket.IO 4 / Engine.IO 4 WebSocket к
   `/ws/socket.io`, передавая Bearer JWT в auth handshake, и получает реальный
   namespace SID.
3. Загружает исходный PDF/DOCX через `POST /api/v1/files/`.
4. Создаёт server-side chat через `POST /api/v1/chats/new`.
5. Вызывает `POST /api/chat/completions` с Bearer authentication, `session_id`,
   `chat_id`, root `parent_id = null`, assistant message id, `message_ids` и file
   descriptor. В локальной conversation record итоговый message id хранится как
   `parent_message_id` для трассировки; продолжения этого chat в текущем UX нет.
6. Принимает только completion-события совпадающих `chat_id` и `message_id`.
7. Параллельно ограниченно опрашивает persisted chat через
   `GET /api/v1/chats/{chat_id}`.
8. Удаляет временный upload через `DELETE /api/v1/files/{file_id}` и закрывает
   socket.

Анализ и draft создают разные Socket.IO sessions, uploads и Open WebUI chats.
Реальные JWT, chat IDs и содержимое документов не публикуются.

### WebSocket/persistence race

`chat:active=false` означает окончание генерации, но не доказывает отсутствие
результата: сообщение может сохраниться чуть позже Socket.IO event. После такого
события transport ускоряет bounded persisted polling и продолжает его только в
рамках общего deadline. WebSocket остаётся основным источником, пока persisted
message не имеет `done=true` и непустого content.

## Документ и cleanup

```text
portal document
  -> independent Open WebUI upload
  -> AI processing
  -> bounded immediate DELETE
  -> on failure: cleanup queue
  -> ai-cleanup retry worker
  -> DELETE succeeds or record expires
```

Каждая задача независимо читает доступную актуальную версию и создаёт собственный
upload. Cleanup одной задачи не влияет на вторую. В `ai_file_cleanup` сохраняются
только внешний file ID, attempts, время следующей попытки/истечения и класс
ошибки. Credential, имя и содержимое ТЗ не сохраняются. Retry использует
экспоненциальный backoff от 30 секунд до часа; retention записи — 24 часа.
Успешное удаление закрывает запись удалением из очереди, истёкшие записи также
удаляются worker.

В окружении с включённым AI должен работать сервис `ai-cleanup` (`php yii
ai/work`). Проверка:

```sh
docker compose --env-file .env.dev ps ai-cleanup
docker compose --env-file .env.dev logs --tail=100 ai-cleanup
docker compose --env-file .env.dev exec -T backend php yii ai/cleanup
```

Последняя команда безопасно запускает один batch и выводит только количества
удалённых, ошибочных и просроченных записей. Для контроля накопления допустимо
смотреть count, attempts, timestamps и `last_error_class` в `ai_file_cleanup`.
Не выводите JWT, HTTP headers, имя/содержимое документа или prompt. Повторяющийся
рост pending rows означает проблему DELETE/авторизации или неработающий worker.

## Idempotency и concurrency

Специализированный lifecycle:

```text
reserve (short DB transaction)
  -> external AI call (no open DB transaction)
  -> finalize (short DB transaction)
```

Analysis и draft имеют отдельные records, поскольку route входит в identity
операции. Completed response, включая terminal HTTP error, replay'ится с тем же
operation key до 24 часов. Явный Retry во frontend создаёт новый key. После
неопределённого network/transport outcome frontend может повторить прежний key,
чтобы получить уже завершённый результат. Активный прежний key возвращает 409;
expired records удаляет `ai-cleanup` worker.

Для одного пользователя допустима штатная пара: один analysis и один draft.
Дополнительная операция того же типа и превышение per-user limit блокируются.
`LIZA_PER_USER_CONCURRENCY` по умолчанию равен 2. Глобальный лимит задаёт
`LIZA_GLOBAL_CONCURRENCY`; фактический fallback и env-примеры — 4 одновременные
операции, то есть максимум две штатные пары. Цель лимитов — не позволить AI
исчерпать PHP workers/DB connections и ухудшить основной workflow.

## Deadline и таймауты

`LIZA_COMPLETION_TIMEOUT_SECONDS=300` — единый monotonic budget всей операции:

```text
connect -> upload -> chat creation -> submit
        -> completion/persisted result -> bounded immediate cleanup
```

Каждая фаза получает только остаток budget. Значение ограничено кодом максимумом
300 секунд. `LIZA_CONNECT_TIMEOUT_SECONDS` и `LIZA_TIMEOUT_SECONDS` ограничивают
отдельные connect/HTTP-вызовы, но не расширяют общий deadline. PHP-FPM имеет 325
секунд, Nginx `fastcgi_read_timeout` — 330 секунд, lease idempotency/concurrency —
operation budget + 60 секунд, то есть 360 секунд при штатной конфигурации.

## Security considerations

- Перед выбором и чтением документа backend применяет существующий request/document
  ACL; browser не может передать недоступную версию из другой заявки.
- `LIZA_TOKEN` существует только в backend env/secret configuration и никогда не
  входит во frontend configuration, DTO или response.
- Credential, полный ТЗ и prompt запрещено логировать. Cleanup diagnostics
  содержат только внешний file ID и класс ошибки.
- Временные копии удаляются немедленно либо через bounded retry queue.
- Frontend cache изолирован authenticated identity + request и очищается при
  смене principal.
- AI output справочен и не изменяет бизнес-данные автоматически.
- Недоступность ЛИЗЫ возвращает локальную ошибку AI endpoint и не влияет на
  просмотр/изменение заявки обычными средствами.

### Production blocker: authentication

Сейчас backend использует **пользовательский JWT Open WebUI**. JWT не попадает в
browser и хранится только в backend secret environment, но это временная схема
только для пилота. Не называйте этот JWT service credential.

До production пользовательский JWT обязательно заменить официальным
поддерживаемым server-to-server механизмом: API key, service account/service
credential либо отдельным API агента ЛИЗЫ. Если владельцы ЛИЗЫ предоставят
стабильный agent API, следует оценить и предпочтительно убрать зависимость
Infrastructure adapter от внутренних Open WebUI Socket.IO/WebSocket endpoints.

## Configuration и запуск

| Variable | Назначение | Default/fallback |
|---|---|---|
| `LIZA_AI_ENABLED` | Feature flag; только `1` включает endpoints/UI capability | выключено |
| `LIZA_BASE_URL` | Base URL Open WebUI | `https://ai.shlz.ru` |
| `LIZA_MODEL` | Имя модели/агента | `ЛИЗА` |
| `LIZA_TOKEN` | PILOT ONLY: пользовательский JWT Open WebUI | пусто |
| `LIZA_CONNECT_TIMEOUT_SECONDS` | Верхний лимит connect phase | 10 |
| `LIZA_TIMEOUT_SECONDS` | Верхний лимит отдельного HTTP-вызова | 45 в коде; 90 в env templates |
| `LIZA_COMPLETION_TIMEOUT_SECONDS` | Общий operation deadline, hard cap 300 | 300 |
| `LIZA_PER_USER_CONCURRENCY` | Одновременные операции пользователя | 2 |
| `LIZA_GLOBAL_CONCURRENCY` | Одновременные AI-операции портала | 4 |

Отдельных env для cleanup нет: batch/backoff/24h retention заданы кодом, worker
является сервисом `ai-cleanup` в Compose. Feature flag по умолчанию выключен.

Dev/test запуск:

1. Скопировать `.env.dev.example` в `.env.dev`, оставить секрет вне Git.
2. Настроить URL, модель, временный пользовательский JWT и
   `LIZA_AI_ENABLED=1`.
3. Выполнить `make dev-up` и убедиться, что `backend`, `frontend`, MariaDB и
   `ai-cleanup` запущены: `make dev-status`.
4. Открыть `http://localhost:8081`, выбрать доступную заявку с PDF/DOCX ТЗ и
   запустить обработку.
5. Проверить независимое завершение обеих вкладок и отсутствие pending cleanup.

Readiness портала намеренно не вызывает ЛИЗУ: внешний AI — деградируемая
зависимость, его отказ не должен делать недоступным основной портал.

## Troubleshooting

| Симптом | Вероятная причина | Безопасная проверка |
|---|---|---|
| «AI-анализ пока недоступен» | feature flag off | `LIZA_AI_ENABLED=1` в выбранном backend env, затем rebuild/restart |
| HTTP 401/403 Open WebUI | JWT истёк/отозван или нет прав на модель/RAG | срок и права пилотного пользователя; не печатать token |
| WebSocket connection failure | URL/path/TLS/proxy или auth handshake | доступность `/ws/socket.io`, trust chain, класс ошибки без headers |
| 503/operation timeout | ЛИЗА/RAG медленны либо исчерпан 300s budget | timings фаз и доступность AI; не увеличивать без проверки PHP/Nginx/lease |
| «неподдерживаемый формат» | отсутствуют ключи или не `list<string>` | sanitized response shape в fake/fixture, не содержимое реального ТЗ |
| 409 «ЛИЗА занята» | global/per-user concurrency limit | count активных неистёкших AI lifecycle records |
| 409 «уже выполняется» | тот же тип операции или operation key in progress | дождаться lease/result; explicit Retry создаёт новый key |
| Cleanup retry pending | immediate DELETE не удался | `ai-cleanup` status, attempts/timestamps/error class, права DELETE |
| `chat:active=false`, результата сразу нет | persistence race Open WebUI | дать bounded polling работать до общего deadline; не считать событие terminal empty |

Не включайте dump request/response, Authorization header или document payload для
диагностики.

## Проверка и подтверждённый smoke

Тесты покрывают независимые analysis/draft и параллельный запуск, разные Open
WebUI chat IDs, строгую валидацию ответа, idempotency/replay/explicit retry,
concurrency, общий deadline, cleanup retry, WebSocket/persistence race,
cross-user cache isolation, frontend cancellation и ACL/security.

Последний подтверждённый verification snapshot перед документированием:

- `make check` — passed: 360 frontend tests; 520 backend tests / 1073 assertions;
  PHPStan, OpenAPI, architecture guard, lint, `git diff --check`;
- `make e2e` — passed: 334 integration tests / 2100 assertions; 16 Playwright tests;
- focused frontend — 62 passed;
- focused backend AI — 33 passed;
- independent read-only `$pr-review` — no actionable findings.

Подтверждённый smoke без чувствительных данных: одна пользовательская команда
одновременно запустила analysis и draft; обе операции вернули HTTP 200, создали
два разных Open WebUI chat ID и завершили lifecycle; оба независимых upload были
удалены, cleanup queue осталась пустой, результаты появились независимо.

## Ограничения пилота

- только PILOT / internal use;
- пользовательский JWT Open WebUI; production rollout заблокирован до
  server-to-server authentication;
- только PDF/DOCX;
- качество зависит от ЛИЗЫ/Open WebUI/RAG и качества документа;
- результат справочный, автоматических изменений заявки нет;
- idempotency replay retention — максимум 24 часа;
- per-user/global concurrency ограничены;
- сохраняется зависимость от внутренних Open WebUI Socket.IO/WebSocket до
  появления стабильного API агента.

## Production readiness checklist

- [ ] Получен официальный server-to-server API/service credential ЛИЗЫ.
- [ ] Пользовательский JWT полностью исключён из production configuration.
- [ ] Подтверждены права service identity на требуемый RAG.
- [ ] Если предоставлен официальный agent API, оценена/выполнена миграция с
      internal Open WebUI protocol.
- [ ] Проведена проверка хранения/retention документов на стороне AI-контура.
- [ ] Подтверждены production concurrency/timeouts.
- [ ] Настроен и контролируется cleanup worker.
- [ ] Выполнен production-like smoke.
- [ ] Продуктовый владелец подтвердил формат и допустимость AI-results.
