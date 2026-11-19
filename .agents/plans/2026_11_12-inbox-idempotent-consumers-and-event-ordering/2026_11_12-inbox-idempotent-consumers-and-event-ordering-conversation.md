# Task004 — Conversation export

- **Task:** 004 — Inbox, Idempotent Consumers and Event Ordering
- **Plan:** `.agents/plans/2026_11_12-inbox-idempotent-consumers-and-event-ordering/2026_11_12-inbox-idempotent-consumers-and-event-ordering-plan.md`
- **Branch:** `feature/stockflow-foundation`
- **Tool:** OpenCode
- **Model:** DeepSeek V4.1 Flash Free 4.1 (reasoning effort: medium)
- **Implementation dates:** 2026-11-12 to 2026-11-18
- **Review and delivery date:** 2026-11-19
- **Final status:** `Verified`
- **Implementation commit:** `93bb52c feat(inbox): add observability and safe retention tooling`
- **Delivery state:** committed locally; not pushed

## 1. Purpose of this export

This document preserves the working conversation for Task004. The earlier phases are
summarized from the session checkpoint. The final phase, its review, the Verified
closure and the delivery record are recorded in the order in which they happened.

It is a consolidated record, not a verbatim transcript. Secrets, payload contents and
raw connection strings were never part of the conversation and are not reproduced here.

## 2. Historical context (Phases 1 to 3)

Task004 was implemented in four phases, one per invocation:

| Phase | Scope | Commit |
|---|---|---|
| 1 | End-to-end idempotent integration-event consumption with a per-context Inbox | `4f261fb feat(inbox): add idempotent rabbitmq event consumption` |
| 2 | Envelope contract validation, failure classification, retry and dead-letter routing | `a82a0a8 feat(inbox): add contract validation and dead-letter routing` |
| 3 | Aggregate-version ordering policies | `cc7c9c4 feat(inbox): add aggregate version ordering policies` |

Key decisions carried into Phase 4:

- Each bounded context owns its Inbox table; there is no shared Inbox table.
- Task004 ships no production Inbox table or migration.
- Inbox persistence and consumer-side changes share one database transaction.
- Duplicate detection uses `(consumer, eventId)`, backed by a unique index.
- Ordering is explicit: no ordering, monotonic aggregate versions, or strict sequencing.
- Missing predecessors are retryable; stale versions are recorded without side effects.
- Permanent failures go to the dead-letter queue; retryable failures go to the retry queue.
- No new dependency, no Event Sourcing, no Redis and no generic projection engine.
- OD-002 remains open.

## 3. Phase 4 request

> Continuemos con la fase 4

The plan defined Phase 4 as observability, safe retention/purge tooling and consumer
operations documentation.

## 4. Research performed

Files and areas inspected before changing code:

- `AGENTS.md`, ADR 003/004/005 and Task004.
- Existing Inbox application and infrastructure components.
- The Outbox operations tooling (`OutboxRetention`, `OutboxStatusSnapshot`, commands,
  repository and sanitizer) as the closest precedent.
- RabbitMQ integration-event consumer, configurers and test infrastructure.
- `config/inbox.php`, `InboxServiceProvider`, `bootstrap/app.php`.
- PHPUnit bootstrap, ECS and PHPStan configuration.
- Existing ephemeral Inbox table fixture and command integration tests.

Verified facts:

- No production Inbox context or table exists.
- Integration tests create ephemeral tables with the reference schema.
- `processed_at` and `outcome` are nullable only for in-flight claims.
- The CHECK constraint keeps both fields consistent but does not prove that a committed
  row is complete.
- There was no Inbox status command, retention policy or purge command.
- `OutboxErrorSanitizer` already provided credential-pattern redaction and control
  character normalization.

Risks identified:

- Logging raw exception messages could leak secrets or allow log forging.
- A default retention duration would make irreversible deletion too easy.
- A shared Inbox table or a generic migration builder would violate context ownership.
- Deleting in-flight claims would break atomicity and duplicate detection.

## 5. Implementation of Phase 4

### 5.1 Shared sanitizer

- Added `Manager\Shared\Infrastructure\Logging\ErrorSanitizer`.
- `OutboxErrorSanitizer` implements it without behavior changes.

### 5.2 Retention model

- Added `InboxRetention` and `InvalidInboxRetention`.
- Resolution order: `--older-than-days`, then `inbox.retention_days`, then no default.
- `resolve()` returns `null` when nothing is configured.
- Zero, negative, non-numeric and fractional values are rejected.
- The cutoff is calculated in UTC.

### 5.3 Status read model

- Added `InboxStatusSnapshot` with `context`, `totalRecords`, `processedRecords`,
  `staleRecords`, `recordsByConsumer`, `oldestProcessedAt`, `newestProcessedAt` and
  `purgeableRecords`.
- `purgeableRecords` is `null` when no retention is configured or provided.
- Counts and consumer names are validated.

### 5.4 Maintenance contract and MySQL adapter

- Added `InboxMaintenanceStore` with `contexts()`, `purgeProcessedBefore()` and
  `snapshot()`.
- Added `MySqlInboxMaintenance`.
- Purge selects only rows with non-null `processed_at` and non-null `outcome`, older
  than the cutoff, ordered by `processed_at` and `id`, bounded by `--limit`.
- Purge is per context; there is no cross-context delete.
- Unknown contexts and non-positive limits are rejected.

### 5.5 Commands

- `manager:integration-events:inbox:status`
  - Reports all configured contexts, or one selected with `--context`.
  - Reports total, processed, stale, per-consumer counts and completion timestamps.
  - Reports purgeable records as not evaluated when there is no retention.
  - Read-only; does not probe RabbitMQ.
- `manager:integration-events:inbox:purge`
  - Manual only; never scheduled.
  - Fails closed when no retention is configured.
  - Fails on unknown contexts, invalid retention and invalid limits without deleting.
  - Processes every configured context when `--context` is omitted.
  - Prints one result line per context.
- Registered both commands in `bootstrap/app.php`.
- Wired the maintenance store, sanitizer and logger in `InboxServiceProvider`.

### 5.6 Observability

- Expanded `InboxLogger` with:
  - `inbox.event.stale` at warning level.
  - Latency for processed, duplicate and stale outcomes.
  - Sanitized error messages for malformed, retry and dead-letter events.
  - Structured event and aggregate metadata.
- The RabbitMQ consumer now logs stale outcomes and passes the consumer name to failure
  logs.
- Payload contents are never logged.
- `inbox.logging.enabled` still disables all Inbox logs.

### 5.7 Tests and documentation

- Unit tests: retention resolution/validation, status snapshot validation and sanitized
  logging without payloads.
- Integration tests with real MySQL: snapshot counts, purge cutoff, committed-only
  eligibility, oldest-first bounded batches, context isolation, status and purge
  command validation failures.
- Wrote `docs/architecture/inbox-operations.md`.
- Updated `docs/README.md`, `docs/ai/implementation-log.md` and Task004.

## 6. Incidents found and resolved during implementation

1. `seed()` collided with the inherited Laravel test method and caused a fatal
   visibility error. The helper was renamed to `seedInboxRow()`.
2. The per-consumer map is ordered alphabetically by SQL, so the expected array was
   corrected to `alpha.order_projection` before `alpha.stock_projection`.
3. `expectsOutputToContain()` did not reliably match the final parenthesized fragment of
   the purge output. A manual `Artisan::call()` confirmed the real output contained the
   expected text. The assertion was simplified to the stable, meaningful prefix.
4. ECS applied line-length fixes to the provider and both commands.
5. PHPStan reported no errors and the baseline was not modified.

## 7. Verification results

| Check | Result |
|---|---|
| Phase 4 unit tests | 20 tests, 50 assertions |
| Phase 4 MySQL and command integration tests | 28 tests, 103 assertions |
| Inbox application/store regression suites | 69 tests, 213 assertions |
| RabbitMQ integration-event consumer suite | 15 tests, 154 assertions |
| Full PHPUnit suite | **574 tests, 1,854 assertions** |
| Targeted ECS | no errors |
| PHPStan | no errors; baseline untouched |
| `git diff --check` | no whitespace errors |

Environment: Docker app container with MySQL and RabbitMQ running.

## 8. Review presentation

Phase 4 was presented for review with three conventional commit alternatives:

1. `feat(inbox): add observability and safe retention tooling`
2. `docs(inbox): add operations runbook for idempotent consumers`
3. `refactor(inbox): extract shared error sanitizer and structured logging`

The conversation export was also suggested as
`.agents/plans/2026_11_12-inbox-idempotent-consumers-and-event-ordering/2026_11_12-inbox-idempotent-consumers-and-event-ordering-conversation.md`.

## 9. Verified closure

> Hagamos commit con el mensaje 1. Antes del commit debe quedar reflejado el estado de
> la fase 4 como Verified y revisar el cierre de task004 conforme al proceso del
> proyecto

Following the Task003 closure precedent, the following changes were made before the
commit:

- Task004 status changed from `Implemented (pending technical review)` to `Verified`.
- Review status recorded the technical approval on 2026-11-19.
- `docs/tasks/README.md` marks Task004 as `Verified` and records its verification note.
- The implementation log records the Verified state, technical review approval and
  delivery.
- The plan next step records that all phases are complete, reviewed and delivered.
- No push and no pull request were performed.

## 10. Implementation commit

```text
93bb52c feat(inbox): add observability and safe retention tooling
```

- 25 files changed, 1,905 insertions and 74 deletions.
- Includes the Phase 4 code, tests, runbook, plan update and Verified closure.
- `task004_phase3.diff` was not included; it was no longer present in the working tree
  when the commit was prepared.
- Branch state after the commit: `feature/stockflow-foundation` ahead of
  `origin/feature/stockflow-foundation` by one commit.

## 11. Final delivery record

This export and the `93bb52c` delivery reference are committed in a separate
documentation commit that closes Phase 4 definitively.

No push and no pull request were created. Any further delivery action requires explicit
approval.

## 12. Residual risks and technical debt

- No Inbox retention duration is approved by default; tables grow until an operator
  chooses a window.
- Purged rows remove duplicate-detection history and are irrecoverable.
- Strict ordering across restarts requires context-owned initialization or reconciliation.
- The Inbox does not serialize different events for the same aggregate; the default
  deployment is one active consumer per queue.
- The sanitizer is pattern-based and cannot detect arbitrary business secrets.
- OD-002, producer-side aggregate-version guarantees, remains open.
- Dashboard metrics for Inbox growth, retry volume, stale volume and dead-letter volume
  remain future work.

## 13. References

- Plan: `.agents/plans/2026_11_12-inbox-idempotent-consumers-and-event-ordering/2026_11_12-inbox-idempotent-consumers-and-event-ordering-plan.md`
- Task: `docs/tasks/004-inbox-idempotent-consumers-and-event-ordering.md`
- Runbook: `docs/architecture/inbox-operations.md`
- Implementation log: `docs/ai/implementation-log.md`
