# Покрытие исторических review-дефектов

## Метод

`COVERED` требует воспроизводимого доказательства:

```text
historical или семантически эквивалентное buggy behavior → RED
current behavior                                      → GREEN
```

Похожий тест, resolved thread или зелёный pipeline без mutation proof дают не
более `PARTIAL`. Substantive finding не требует отдельного теста автоматически:
guard добавляется только для повторяемого риска с надёжной deterministic oracle.

Corpus snapshot от 2026-08-11 содержит 157 PR, 395 Qodo и 415 CodeRabbit inline
comments, включая 442 substantive findings. Raw corpus хранится вне репозитория.

## Evidence-backed coverage

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

Unchanged guards reuse preserved evidence only where the final execution path and
oracle remain semantically unchanged. The adapted review-contract guard has fresh
positive and negative fixtures in this branch.

## Reclassified and partial families

| Family | Source | Status | Reason |
|---|---|---|---|
| archived mutation bypass via string route ID | PR #244, Qodo | FALSE/QUESTIONABLE | exact historical branch stayed GREEN because Yii coerces the bound route argument to `int` |
| registry `mine` identity collision | PR #247, Qodo | PARTIAL | corrected collision fixture exists, but independent production mutation proof was not completed |
| file-import workspace/snapshot identity | PR #244, CodeRabbit | PARTIAL | current command takes `listId` from the snapshot; isolated command-boundary proof remains outstanding |

## Metric

Manual reclassification removes the disproved archived string-ID family from the
provisional denominator. `PARTIAL` is not counted.

| State | Covered | Current automatable denominator | Lower bound |
|---|---:|---:|---:|
| Before Wave 1 | 5 | 164 | 3.0% |
| After Wave 1 | 7 | 164 | 4.3% |
| After Wave 2 reconciliation | 12 | 163 | 7.4% |

This is an evidence-backed lower bound, not an estimate of all CI coverage.

## Remaining high-value backlog

1. simultaneous assignment/claim and stale authorization with explicit barriers;
2. atomic role assignment and audit under a controlled duplicate insert;
3. populated migration rollback with duplicate document titles;
4. command-boundary binding of file-import workspace to snapshot identity;
5. out-of-order admin and registry requests beyond component disposal.
