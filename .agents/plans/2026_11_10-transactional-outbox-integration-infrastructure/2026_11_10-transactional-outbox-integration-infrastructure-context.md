# Transactional Outbox — final context

- Checkpoint date: 2026-11-12
- Branch: `feature/stockflow-foundation`
- Last commit: `c55984d feat(outbox): add operations tooling and sanitize publication errors`
- Delivery: implementation committed in `c55984d` and pushed to `origin/feature/stockflow-foundation`.
- Working tree: implementation committed and pushed; this documentation update is pending commit.
- Plan: `.agents/plans/2026_11_10-transactional-outbox-integration-infrastructure/2026_11_10-transactional-outbox-integration-infrastructure-plan.md`
- Current state: **all phases implemented, reviewed, committed and pushed; Task003 Verified**.

## Scope decision

Task003 implements only Shared Transactional Outbox infrastructure for publishing integration events to RabbitMQ.

Out of scope and unchanged:

- Inventory-specific persistence and Domain Event to Integration Event mapping (Task006).
- Inbox, projections, Sagas or Event Sourcing.
- Legacy `User` context and `RabbitMqEventBus`.
- OD-002 and OD-003, which remain open.
- New dependencies.
- Any further commit, push or pull request without explicit approval.

## Implemented phases

### Phase 1 — transactional persistence

- One shared `outbox` table with logical bounded-context ownership through `producer`.
- Business state and Outbox records share the application's default database connection and transaction.
- No independent Outbox connection is configurable.
- Producer allowlist validation, payload JSON validation and deterministic timestamp persistence.

### Phase 2 — claim fencing and confirmed publication

- One-at-a-time claims with `SELECT ... FOR UPDATE SKIP LOCKED`, lease recovery and unique claim tokens.
- Every state-changing transition is fenced by the current claim token.
- Deterministic ADR004 envelope serialization with byte-stable payload text.
- RabbitMQ publication with persistent delivery, mandatory routing and publisher confirms.
- A record becomes `published` only after positive confirmation and successful routing.

### Phase 3 — retries, terminal failure and requeue

- `OutboxRetryPolicy` with ten total attempts, 5-second base backoff, factor 2 and 10-minute cap.
- Retry limits and backoff are configurable while preserving the defaults.
- A failed attempt below the limit schedules a fenced retry; failure of attempt ten becomes terminal `failed`.
- Expired or pending records already at the attempt limit transition to `failed` during claim recovery.
- Serialization failures are terminal without retry.
- `manager:integration-events:outbox:requeue {eventId}` recovers only `failed` records, preserving event identity and payload bytes.

### Phase 4 — observability, retention and operations

- `manager:integration-events:outbox:status` reports counts, retrying records, oldest pending age, stale claims, active publishers, last publication and recent errors.
- `manager:integration-events:outbox:purge {--published-older-than-days=}` deletes only published records beyond the retention window.
- Retention resolution order: CLI option, `outbox.published_retention_days`, built-in default of 7 days.
- Pending, claimed and failed records are never purged automatically.
- Structured per-record and batch-summary logs; logging and batch summaries can be disabled separately.
- `docs/architecture/outbox-operations.md` documents execution, supervision, monitoring, retries, requeue, retention and incident handling.

## Error sanitization

`OutboxErrorSanitizer` is a reusable policy applied before errors are persisted, logged or displayed.

It redacts:

- URI userinfo and database DSN credentials.
- Passwords, secrets, tokens, API keys, authorization codes, usernames and similar key/value pairs.
- JSON, escaped JSON and PHP array syntax.
- Authorization and proxy-authorization header values.
- JWTs, common provider token formats and PEM private key blocks.

After redaction, the policy normalizes CR/LF, tabs, non-printable C0/C1 controls and Unicode line or paragraph separators to single spaces, preventing log forging. The policy is applied in `OutboxPublisher`, `OutboxPublisherLogger`, `MySqlOutboxRepository` and `OutboxStatusCommand`. Repository reads also sanitize legacy rows, and exhaustion recovery rewrites legacy errors in sanitized form.

Sanitization is defense in depth. Payload-content sensitivity remains the responsibility of each producing bounded context. Task003 introduces no producer mapping and therefore makes no claim that all future payloads are free of sensitive information.

## Main files

Production and configuration:

- `src/Shared/Application/Outbox/OutboxFailure.php`
- `src/Shared/Application/Outbox/OutboxRetention.php`
- `src/Shared/Application/Outbox/OutboxStatusSnapshot.php`
- `src/Shared/Infrastructure/Console/Outbox/OutboxStatusCommand.php`
- `src/Shared/Infrastructure/Console/Outbox/PurgeOutboxCommand.php`
- `src/Shared/Infrastructure/Outbox/MySqlOutboxRepository.php`
- `src/Shared/Infrastructure/Outbox/OutboxErrorSanitizer.php`
- `src/Shared/Infrastructure/Outbox/OutboxPublisher.php`
- `src/Shared/Infrastructure/Outbox/OutboxPublisherLogger.php`
- `app/Providers/OutboxServiceProvider.php`
- `config/outbox.php`

Tests and documentation:

- `tests/Unit/Shared/Infrastructure/Outbox/OutboxErrorSanitizerTest.php`
- `tests/Unit/Shared/Infrastructure/Outbox/OutboxPublisherLoggerTest.php`
- `tests/Unit/Shared/Infrastructure/Outbox/OutboxPublisherTest.php`
- `tests/Integration/Shared/Infrastructure/Outbox/MySqlOutboxRepositoryTest.php`
- `tests/Integration/Shared/Infrastructure/Outbox/OutboxPublisherTest.php`
- `tests/Integration/Shared/Infrastructure/Console/Outbox/OutboxCommandsTest.php`
- `docs/architecture/outbox-operations.md`
- `docs/tasks/003-transactional-outbox-and-integration-infrastructure.md`
- `docs/ai/implementation-log.md`

## Verification status

Technical verification and technical review are complete. The technical review was approved on 2026-11-12. Exact final counts are recorded in `docs/ai/implementation-log.md`. At minimum:

- Outbox unit tests pass.
- Outbox integration tests pass against real MySQL and RabbitMQ.
- PHPStan reports no errors and the baseline is untouched.
- Targeted ECS over changed files is clean.
- Full-project ECS retains only pre-existing legacy findings in untouched files.

Known limitations:

- There is no deterministic real-broker test for the confirmation-timeout path. The timeout state is covered by publication-state tests; real-broker tests cover positive confirmation, unroutable returns and negative acknowledgements.
- The sanitizer is pattern-based and covers common credential shapes, not arbitrary business secrets.
- Full-project ECS still reports pre-existing legacy findings in untouched or generated files; those were not modified.

## Next step

No further implementation phase is planned. Task003 is Verified, committed and pushed. Any further change requires explicit approval.

`Verified` in Task003 means that the automated checks and acceptance criteria were verified and the technical review was approved on 2026-11-12. OD-002 and OD-003 remain open, and no further phase is planned for this plan.
