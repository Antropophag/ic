# Покрытие исторических review-дефектов

Полный аудит оставшегося корпуса и пересчёт актуального бэклога приведены в
[аудите реклассификации](historical-review-reclassification.md). Устаревшая
метрика ниже сохранена для сопоставимости; без стабильной связи между семьями и
находками она не является воспроизводимым каталогом.

## Метод

`COVERED` требует воспроизводимого доказательства:

```text
историческое или семантически эквивалентное дефектное поведение → RED
актуальное поведение                                           → GREEN
```

Похожий тест, закрытый thread или зелёный pipeline без mutation proof дают не
более `PARTIAL`. Существенная находка не требует отдельного теста автоматически:
защита добавляется только для повторяемого риска с надёжным детерминированным oracle.

Снимок корпуса от 2026-08-11 содержит 157 PR, 395 inline comments Qodo и 415
inline comments CodeRabbit, включая 442 существенные находки. Исходный корпус
хранится вне репозитория.

## Покрытие, подтверждённое доказательствами

| Family | Historical source | Current guard | Evidence | Status |
|---|---|---|---|---|
| stale `lockVersion` после confirm | PR #236, Qodo | `frontend/src/confirmRequestAction.test.js` | reconstructed historical implementation RED; current GREEN | COVERED |
| malformed notification cursor | PR #224, Qodo | `frontend/src/registryPreferences.test.js` | historical weak regex RED; current GREEN | COVERED |
| repeated dev-tools render leaks listeners | PR #228, Qodo | `frontend/dev/dev-tools.test.js` | cleanup removal RED; current GREEN | COVERED |
| per-frame gradient allocation | PR #230, Qodo | `frontend/src/components/AetherRibbonMesh.test.js` | historical allocation RED; current GREEN | COVERED |
| reduced-motion transition snap | PR #233, Qodo | `frontend/src/components/AetherRibbonMesh.test.js` | transition snap removal RED; current GREEN | COVERED |
| tracked runtime environment files | PR #172, Qodo/CodeRabbit | `scripts/check-deployment-contracts.sh` | exact `b2368a1` RED; fixed `a6160d2` GREEN | COVERED |
| review-process contract drift | KEEP QODO reconciliation | `scripts/check-review-contracts.sh` and `scripts/test-review-contracts.sh` | disabled Qodo/missing skill fixtures RED; current repository GREEN | COVERED |
| exact login 503 schema | PR #232, Qodo | `frontend/scripts/check-openapi.mjs` | wrong but valid `HttpError` reference RED; current GREEN | COVERED |
| whitespace-only critical-action reason | PR #236, Qodo | `frontend/scripts/check-openapi.mjs` | permissive `.*` mutation RED; current GREEN | COVERED |
| non-regular migration workspace object | PR #225, Qodo/CodeRabbit | `frontend/scripts/bitrix-files.test.js` | historical `stat()` behavior RED; current `lstat()` GREEN | COVERED |
| late system-overview request after unmount | PR #250, Qodo/CodeRabbit | `frontend/src/components/AdminOverview.test.js` | removed abort RED; current GREEN | COVERED |
| imported file metadata exceeds DB bounds | PR #244, Qodo | `BitrixArchiveFileImporterTest::testRejectsDatabaseBoundViolationsBeforeImportingFiles` | removed bound checks RED; current MariaDB test GREEN | COVERED |
| simultaneous executor reassignment from one stale version | PR #16, Qodo | `RequestAssignmentConcurrencyTest::testTwoAssignmentsFromTheSameVersionProduceOneWinnerAndConsistentAudit` | historical missing-version mutation: two successes RED; two MariaDB sessions, current lock/version guard GREEN | COVERED |
| role assignment and audit atomicity | PR #89, Qodo | `UserRoleAuditAtomicityTest::testAssignRoleRollsBackDomainMutationWhenAuditWriteFails` | historical non-transactional boundary RED under controlled audit failure; transactional current code GREEN | COVERED |
| in-component out-of-order registry responses | PR #41/#213/#224, Qodo | `requestRegistryLoadLifecycle.test.js` | generation increment removal RED; controlled deferred responses GREEN | COVERED |
| concurrent same-key idempotency claim | PR #202, Qodo | `IdempotencyStoreConcurrencyTest` | key-scope mutation executes both operations RED; existing two-session guard GREEN | COVERED |
| populated archive-metadata rollback with duplicate document titles | PR #244 migration review family, CodeRabbit/Qodo | `BitrixArchiveMetadataMigrationTest::testRollbackRejectsPopulatedDuplicateTitlesBeforeChangingSchema` | recorded preflight-removal mutation RED; current MariaDB guard asserts the collision error and unchanged columns, unique indexes, and foreign keys before/after `safeDown()` GREEN | COVERED |
| file-import workspace/snapshot provenance | PR #244, CodeRabbit | `BitrixArchiveFileImporterTest::testRejectsWorkspaceWithWrongListIdBeforeReadingAssociations` and `testRejectsWorkspaceWithWrongFingerprintBeforeReadingAssociations` | missing command/importer binding mutation accepts the mismatched source context RED; independent verified list ID and snapshot fingerprint oracles GREEN | COVERED |
| file-import retry after partial availability | PR #244, Qodo | `BitrixArchiveFileImporterTest::testRetryAfterUnavailableFileSkipsCompletedDocumentAndImportsRemainder` | completed-document recognition removal raises duplicate-key error RED; retry creates remainder and skips prior write GREEN | COVERED |

Unchanged guards reuse preserved evidence only where the final execution path and
oracle remain semantically unchanged. The adapted review-contract guard has fresh
positive and negative fixtures in this branch.

## Reclassified and partial families

| Family | Source | Status | Reason |
|---|---|---|---|
| archived mutation bypass via string route ID | PR #244, Qodo | FALSE/QUESTIONABLE | exact historical branch stayed GREEN because Yii coerces the bound route argument to `int` |
| registry `mine` identity collision | PR #247, Qodo | PARTIAL | corrected collision fixture exists, but independent production mutation proof was not completed |

## Wave 3 candidate audit

| Family | Historical source | Classification | Decision |
|---|---|---|---|
| stale executor authorization during start | PR #14, Qodo | CURRENT + ALREADY COVERED | current row lock and active-assignee check share the transaction; the stronger recurring assignment race from PR #16 was selected |
| simultaneous executor reassignment | PR #16, Qodo | CURRENT + UNCOVERED | added a real two-session optimistic-lock/audit guard |
| duplicate role insert | PR #84, Qodo | CURRENT + PARTIAL | existing uniqueness handling covers the duplicate; the uncovered role/audit transaction boundary from PR #89 was selected |
| role mutation survives audit failure | PR #89, Qodo | CURRENT + UNCOVERED | current production bug fixed with one transaction boundary and a controlled MariaDB failure guard |
| stale registry/dashboard/notification response | PR #41/#213/#224, Qodo | CURRENT + PARTIAL | disposal/reopen tests existed; added same-activation reversed-completion coverage |
| same-key duplicate mutation | PR #202, Qodo | CURRENT + ALREADY COVERED | existing two-process guard proved by mutation; no duplicate test added |
| details refresh overwrites a new comment | PR #22, Qodo | CURRENT + PARTIAL | same sequencing primitive is now guarded at the shared lifecycle boundary; component-specific duplication rejected |
| notification atomic claim | PR #51, Qodo | CURRENT + ALREADY COVERED | current claim integration tests already use competing workers; no stronger Wave 3 oracle found |

## Metric

Manual reclassification removes the disproved archived string-ID family from the
provisional denominator. `PARTIAL` is not counted.

| State | Covered | Current automatable denominator | Lower bound |
|---|---:|---:|---:|
| Before Wave 1 | 5 | 164 | 3.0% |
| After Wave 1 | 7 | 164 | 4.3% |
| After Wave 2 reconciliation | 12 | 163 | 7.4% |
| After Wave 3 reclassification | 13 | 163 | 8.0% |
| After Wave 3 guards | 16 | 163 | 9.8% |
| Before Wave 4 | 16 | 163 | 9.8% |
| After Wave 4 reclassification | 16 | 163 | 9.8% |
| After Wave 4 guards | 19 | 163 | 11.7% |

This is an evidence-backed lower bound, not an estimate of all CI coverage.

## Исторический снимок бэклога после Wave 4

Этот раздел зафиксирован 11 августа 2026 года и не является текущим планом
следующей волны. Актуальное решение — `TARGETED MINI-WAVE` для MariaDB retry
oracle и SMTP peer verification — приведено в
[аудите реклассификации](historical-review-reclassification.md#решение).

### Оставшийся на тот момент high-value бэклог

1. migration retry after non-transactional MariaDB DDL failure (PR #200);
2. destructive rollback policy for populated department snapshots (PR #200);
3. other high-risk provenance and data-integrity families.

### Аудит кандидатов Wave 4 на тот момент

| Family | Historical source | Classification | Decision |
|---|---|---|---|
| archive-metadata rollback after duplicate visible titles | PR #244, CodeRabbit/Qodo migration family | CURRENT + UNCOVERED | added populated MariaDB preflight guard before any rollback DDL |
| workspace/snapshot identity | PR #244, CodeRabbit | CURRENT + PARTIAL | strengthened to verified snapshot fingerprint at the import boundary |
| import-files retry after partial availability | PR #244, Qodo | CURRENT + PARTIAL | proved exact observable rerun contract: completed write skipped, remainder imported |
| department snapshot migration rerun after DDL failure | PR #200, CodeRabbit | CURRENT + PARTIAL | deferred: released migration policy and isolated mid-DDL failure need a dedicated remediation scope |
| destructive department snapshot rollback | PR #200, Qodo | CURRENT + PARTIAL | deferred: product rollback policy must be resolved before changing a released migration |
| CHECK-constraint rollback portability | PR #244, Qodo | NOT DETERMINISTICALLY AUTOMATABLE IN CURRENT MATRIX | production and CI contract is MariaDB; cross-engine portability has no supported runtime target |
