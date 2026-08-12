# Repository semantic invariants

Use these as review hypotheses, then verify them in canonical docs and code.

## Sources of truth

- `docs/product-spec.md` and `docs/business-rules.md` define observable behavior.
- `docs/architecture.md`, `docs/data-model.md`, and ADRs define boundaries.
- `openapi/openapi.yaml` is the HTTP contract; `docs/api.md` adds semantics.
- `docs/engineering-standards.md` is the Definition of Done.

## Access and workflow

- Enforce every action on the backend; frontend capability is not authorization.
- Recheck active identity, role/assignment, state, and `lock_version` at the
  transactional boundary.
- Keep transitions, audit, and notification outbox atomic.
- Validate raw JSON types before normalization.

## API and frontend

- Production mutations use session/CSRF and documented idempotency behavior.
- Trace fields across controller/DTO, OpenAPI, API client, Vue consumer, and tests.
- Abort or invalidate async work when route, selection, search, or lifetime changes.
- Distinguish partial success from failure so users do not repeat committed work.
- Compare specialized error schemas and exact response `$ref` with runtime errors.

## Data, files, and deployment

- Review migrations on fresh and populated schemas, including rollback/recovery.
- Bind Bitrix workspaces to their snapshot, validate MariaDB bounds, and reject
  symlinks/non-regular files before reading or hashing.
- Store documents outside web root; validate size, MIME, extension, and structure.
- Keep development/test identity code unreachable in production artifacts.
- Preserve offline deployment; do not introduce external runtime dependencies.

## Tests and integration effects

- Critical rules need positive, negative, and role scenarios; race fixes need
  reversed-order or close-during-request coverage.
- SQL and migrations need the real MariaDB contour.
- Review notification retry/lease changes for duplicates, loss, and recovery.
- Keep product docs, user help, audit, notifications, and changelog aligned.
