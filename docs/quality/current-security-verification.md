# Проверка актуальных security/auth findings

## Scope

Проверка выполнена на `origin/main` `019122bc9b7eb5c32b63f290bbd0207d9ef99218`,
куда входит merge commit PR #265. Это read-only/runtime verification трёх
finding, а не Wave 5 и не реализация исправлений:

1. F074 — необработанный bearer credential в `notification_outbox`, finding
   `3670867986`, PR #61;
2. F055 — неактивная break-glass identity, finding `3716538261`, PR #196;
3. F192 — проверка SMTP peer, finding `3667810191`, PR #51.

Production-код, тесты, migrations и deployment configuration не изменялись.

## Method

Проверены актуальные code paths, schema/migrations, product и operations
contracts, существующие tests, locked dependency source и исходные review
comments GitHub. Runtime-сценарии выполнены в disposable `ic-test`: существующий
integration fixture создал отчёт и уведомление, после чего строка outbox была
прочитана до rollback; отдельно запущен сценарий аутентификации отключённой
break-glass identity. Значение bearer token не печаталось и не сохранялось в
артефактах.

## Finding A — persisted bearer credentials

### Historical source/family

F074, finding `3670867986`, PR #61. Reviewer утверждал, что URL в notification
body сохраняет raw token в `notification_outbox`, нарушает ACL-005 и позволяет
читателю БД/backup скачать документ. Reclassification повторил этот вывод и
дополнительно назвал finding current bug с HIGH confidence. Часть исходной
формулировки о «бессрочном» хранении была уже сужена исправлением в том же PR:
после успешной доставки worker затирает `body`. До доставки и после окончательной
ошибки credential остаётся в строке.

### Current path and secret model

`DocumentRepository::issueDocumentLink()` и эквивалент в `RequestRepository`
создают 32 random bytes и кодируют их как 64 hex characters. Это не ID и не
signed URL, а reusable bearer token. В `document_download_links` сохраняются
только SHA-256 hash, document version ID и `created_at`; срока истечения,
one-time/used marker и отдельного revoke marker схема не содержит.

Raw token передаётся в `DocumentDownloadUrl::build()`, полный URL включается в
`body`, а `NotificationOutbox::enqueue()` сохраняет `body` без преобразования в
MariaDB `TEXT`. Worker читает body для каждой попытки и затирает его только после
успешного `send()`. После пяти неуспешных попыток строка имеет status `failed`, но
body не очищается. Токен остаётся повторно используемым, пока существуют и не
soft-delete соответствующие document/version rows; time-based expiry нет.

Bearer-link как feature является явным продуктовым контрактом ACL-003..006:
скачивание не требует session, а ссылку можно переслать другому сотруднику.
Finding относится не к этому feature, а к нарушению отдельного ACL-005:
«в БД хранится только хэш».

### Reproduction and persistence

Disposable fixture вызвал публичный `uploadReport()` и создал pending
notification. Проверка строки и download lookup дала:

```text
row persisted: YES
raw credential recoverable from DB row: YES
matching hash stored separately: YES
credential usable via repository boundary: YES
row status: pending
URL shape: https://.../api/v1/document-links/<REDACTED>/download
```

Таким образом, наличие `token_hash` не защищает credential, дублированный в
outbox body.

### Exposure

- Confirmed: любой principal с прямым read-доступом к `notification_outbox.body`
  видит credential для pending/sending/retrying/failed rows; штатный worker читает
  его до отправки.
- Potential: штатные полные MariaDB backups/dumps сохранят такие строки, если не
  выполняется отдельная фильтрация/очистка. Репозиторий предписывает MariaDB
  backup, но конкретный production backup artifact не исследовался.
- Not observed: admin notification API намеренно исключает `body`; application
  logs, exception text, audit export и dead-letter storage вне этой же строки не
  содержат body в проверенных paths. Отдельного dead-letter store нет.

### Verdict and severity

**Primary verdict: CONFIRMED_CURRENT_BUG. Severity: Medium.** Credential даёт
анонимный доступ и нарушает явный ACL-005. Preconditions ограничены read-доступом
к основной БД/backup; scope — документы, чьи notification rows ещё не были
успешно отправлены либо окончательно failed. Impact усиливают отсутствие expiry
и повторное использование; admin UI credential не раскрывает.

### Remediation direction

- Affected boundary: создание document link → durable outbox payload → worker.
- Desired invariant: durable outbox и его ошибки не содержат usable credential;
  authoritative persistent representation остаётся hash-only.
- Smallest safe direction: хранить opaque document/version notification
  reference и создавать delivery credential на контролируемой dispatch boundary,
  сохранив retry semantics и продуктовый bearer-link contract.
- Required guard: enqueue/retry integration assertion, что persisted body не
  позволяет восстановить credential, при этом доставленное письмо содержит
  рабочую ссылку.
- Data cleanup: existing pending/sending/failed rows могут содержать
  non-expiring credentials. Нужны inventory и policy для purge/reissue. Для sent
  rows body уже очищен. Простое ожидание expiry не помогает, потому что expiry в
  текущей модели отсутствует.

## Finding B — inactive break-glass identity

### Historical source/family

F055, finding `3716538261`, PR #196. Reviewer точно указал, что provisioner не
читает `is_active`, назначает единственную роль `administrator` существующей
зарезервированной identity и потребовал выбрать policy: reject либо reactivate.
Reclassification корректно зафиксировал provisioning path, но его запись в
«кандидаты в актуальные дефекты» не доказывала usable authentication.

### Authentication flow and `is_active` semantics

```text
submitted credentials
→ LoginAuthenticator selects BreakGlassAuthenticator only for exact configured login
→ technical identity is loaded under transaction/row lock
→ is_active and exactly-one-administrator-role are validated
→ password/rate limit are validated
→ AuthController creates session only after success
→ CurrentUser rechecks is_active on every protected request
→ role/capability checks run for that active actor
```

`is_active=false` therefore blocks every login path and also invalidates an
already-created session on its next request. The documented immediate-revocation
contract is explicit (AUTH-003 and break-glass runbook), so this is not merely an
LDAP flag.

### Reproduction

Existing integration setup provisioned break-glass, set the technical user to
inactive, and attempted authentication with the correct password. Targeted test
`testDisabledIdentityIsDeniedDespiteCorrectPassword` passed. The authenticator
emitted `identity_disabled`/configuration denial and threw before controller
session creation.

```text
Inactive login succeeds: NO
Session created: NO
Admin capability usable: NO
Blocking layer: BreakGlassAuthenticator active-state validation
Existing-session layer: CurrentUser active-state validation on every request
```

### Verdict and severity

**Primary verdict: ALREADY_SAFE. Severity: none.** An inactive technical row may
retain/receive the administrator role during provisioning, but it cannot produce
usable authenticated administrator access. Exact deterministic regression
coverage already exists, and HTTP/protected boundaries share `CurrentUser`.

The remaining question—whether deployment should fail when provisioning finds
an inactive reserved identity, instead of keeping a safely disabled identity—is
an operational/product invariant, not a demonstrated auth vulnerability. No
production remediation or additional security guard is justified by this
finding alone.

## Finding C — SMTP peer verification

### Historical source/family

F192, finding `3667810191`, PR #51. Original reviewer objected to unconditional
`verify_peer=0` and recommended trusted corporate CA; an unsafe mode, if needed,
should be explicit and not default. Reclassification inferred «secure production
default; no DSN oracle». That inference is only true for the application-code
fallback, not for the checked-in production env template.

### Effective configuration and dependency default

Locked dependency is Symfony Mailer `v7.4.14`. Its installed
`EsmtpTransportFactory` sets both `verify_peer=false` and
`verify_peer_name=false` when DSN `verify_peer` is false. Otherwise it leaves PHP
SSL context defaults in force. PHP defaults are `verify_peer=true`,
`verify_peer_name=true`, `allow_self_signed=false`; Symfony 7.4 documentation
likewise states that SMTP peer verification is enabled by default.

Reflection at the current application boundary produced redacted DSNs:

```text
SMTP_VERIFY_PEER unset → verify_peer=1
SMTP_VERIFY_PEER=1     → verify_peer=1
SMTP_VERIFY_PEER=0     → verify_peer=0
```

Effective contours:

| Contour | TLS | verify_peer | verify_peer_name | allow_self_signed | Source |
|---|---|---:|---:|---:|---|
| application fallback | STARTTLS/SMTP auto-TLS | true | true | false | `Mailer`, Symfony/PHP defaults |
| production template | STARTTLS/SMTP auto-TLS | false | false | false/not relevant | `.env.example` explicit `0` |
| development example | STARTTLS/SMTP auto-TLS | true | true | false | `.env.dev.example` explicit `1` |
| test | plaintext fake SMTP contour | false | false | false/not relevant | `.env.test`, `SMTP_SECURE=none`, explicit `0` |

The production template documents `0` as a deliberate exception for the current
self-signed corporate relay. It has no configured `cafile`, CA mount,
peer fingerprint or equivalent server-authentication mechanism. TLS can encrypt
the connection, but this contour does not authenticate the peer.

References for underlying defaults: [Symfony Mailer 7.4 TLS peer
verification](https://symfony.com/doc/7.4/mailer.html#tls-peer-verification) and
[PHP SSL context options](https://www.php.net/manual/en/context.ssl.php).

### Verdict and severity

**Primary verdict: NEEDS_PRODUCT_DECISION. Severity: policy-dependent (potential
Medium).** The application/library default is secure and lacks a deterministic
regression guard, but the repository's production template intentionally
overrides it to an unauthenticated-peer mode. The repository documents the
exception rather than prohibiting it, so current technical evidence cannot call
that deployment choice a contract violation. Security/operations must decide
whether the internal network plus self-signed relay is an accepted trust model or
whether corporate CA/pinning is required.

If authenticated SMTP peer identity is required, the production contour is a
Medium current bug: an on-path actor can impersonate the relay and observe SMTP
credentials/content. If the exception is accepted, a future guard should assert
the code fallback `verify_peer=1` and separately make the approved production
exception visible; a test of the fallback alone must not claim production is
verified.

## Result

| Finding | Verdict | Severity | Production fix? | Guard needed? |
|---|---|---|---|---|
| F074 persisted bearer credential | CONFIRMED_CURRENT_BUG | Medium | Yes, separate security PR | Yes, after redesign |
| F055 inactive break-glass identity | ALREADY_SAFE | none | No | No; exact guard exists |
| F192 SMTP peer verification | NEEDS_PRODUCT_DECISION | potential Medium | Depends on approved trust policy | Yes after policy; guard both fallback and effective contour |

## Next work

1. Separate security PR: redesign the durable notification/link boundary, add
   regression coverage, and inventory/purge or reissue exposed pending/failed
   credentials. Migration is needed only if the selected representation changes
   schema; data cleanup is required regardless because links do not expire.
2. Separate security/operations decision: approve the SMTP relay trust model. If
   peer authentication is required, configure corporate CA/pinning and then add
   an effective-production-config guard. If the exception is accepted, record
   its owner and review condition before adding a narrower code-default guard.
3. No auth-fix PR for F055. Preserve the existing inactive-login and per-request
   revocation guards. A provisioning-policy change, if desired operationally,
   belongs in a separately approved task and is not security remediation proven
   by this audit.
4. A final targeted guard mini-wave may still contain the independently verified
   MariaDB retry work from the earlier audit. SMTP belongs there only after the
   policy decision; bearer remediation must remain separate.
