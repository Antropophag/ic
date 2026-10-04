# ADR 0008: границы слоёв и постепенный рефакторинг

## Статус

Принято.

## Контекст

Domain уже изолирован, но Application содержит Yii validation models и отдельные
зависимости на Infrastructure, а HTTP controllers напрямую используют persistence,
read models и другие adapters. Big-bang rewrite создаст слишком большой риск для
workflow, ACL, аудита, идемпотентности и документов. Нужна проверяемая граница,
которая не меняет runtime-поведение и позволяет уменьшать долг по частям.

## Решение

Целевое дерево backend отражает ответственности, а не формальную полноту папок:

```text
backend/src/
├── Domain/
│   ├── Request/
│   └── Identity/
├── Application/
│   ├── Request/
│   │   ├── Command/
│   │   ├── Query/
│   │   ├── UseCase/
│   │   └── Port/
│   ├── Document/
│   └── Identity/
├── Infrastructure/
│   ├── Persistence/{Request,Identity}/
│   ├── ReadModel/Request/
│   ├── Document/
│   ├── Notification/
│   ├── Ldap/
│   └── Deployment/
├── Http/
│   ├── Controller/
│   └── Request/
└── Console/
```

Каталоги появляются только вместе с переносом реальной ответственности; пустые
заготовки не создаются.

## Dependency direction

```text
Http / Console -> Application -> Domain
Infrastructure -> Application / Domain
```

Domain зависит только от Domain и PHP stdlib. Application зависит от Domain,
собственных DTO, use cases и ports, но не от Infrastructure, Http, Console или
Yii. Infrastructure не зависит от Http/Console. Console пока может обращаться к
Infrastructure как interface adapter/composition root. HTTP не принимает
бизнес-решения: controller валидирует и переводит транспортный контракт.

Architecture guard анализирует production PHP через `token_get_all()`. Каждое
текущее запрещённое отношение записано как `source file → exact dependency`.
Новая зависимость, в том числе в уже allowlisted файле, запрещена; исчезнувшая
зависимость делает baseline устаревшим и требует удалить запись.

## Incremental migration

Следующие волны переносят по одному use case или boundary. Текущие
`Application\*\*Input`, наследующие `yii\base\Model`, рассматриваются как HTTP
validation models. Их целевой поток:

```text
HTTP request validation model
        ↓
plain Application command/query DTO
        ↓
Application use case
```

Persistence и query implementations остаются в Infrastructure; специализированный
SQL read model не обязан получать универсальный repository interface. Port
создаётся только для реальной технической границы. Это не DDD rewrite и не
требование создавать интерфейс для каждого запроса.

## Последствия

- существующий долг видим и может только уменьшаться;
- новые большие production-файлы и PHP-методы блокируются, а текущие hotspots не
  могут расти без явной смены baseline;
- RequestRepository, RequestController, DocumentRepository, RequestQuery и Vue
  components остаются на месте в этой итерации;
- API, DB schema, workflow, ACL, audit, notifications, idempotency, document
  storage и deployment не меняются;
- постепенный перенос потребует удалять соответствующие baseline entries в той
  же change set.
