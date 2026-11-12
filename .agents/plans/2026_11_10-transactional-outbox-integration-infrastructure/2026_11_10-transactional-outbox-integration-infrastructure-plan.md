---
name: "transactional-outbox-integration-infrastructure"
description: "Implement shared Transactional Outbox and RabbitMQ integration-event publication infrastructure with atomic persistence, claim fencing, deterministic serialization, retries and operational tooling."
created_at: "2026-11-10"
status: "Implemented"
implemented_by:
  tool: "OpenCode"
  model:
    name: "DeepSeek V4.1 Flash Free"
    version: "4.1"
    reasoning_effort: "medium"

last_implementation_at: "2026-11-11T11:42:33Z"
has_completed_all_phases: true
---

# Transactional Outbox and Integration Infrastructure

## 1. Goal

Implement the generic `Manager\Shared` infrastructure required to persist integration events in a shared Outbox table within the same database transaction as the originating business change, and publish them asynchronously to RabbitMQ through a claim-fenced publisher.

An Outbox record may transition to `published` only after the publisher has received a positive broker confirmation and verified that the message was not returned as unroutable.

The implementation must support:

- Atomic business-change and Outbox persistence.
- A shared Outbox table with bounded-context ownership identified by `producer`.
- Deterministic integration-event envelope serialization.
- Exclusive claims with lease recovery and fencing.
- RabbitMQ publisher confirms, mandatory routing and persistent delivery.
- Retry scheduling and terminal failures.
- Operator recovery, status reporting, retention and purge.
- Explicit publisher execution and supervision.

Do not implement Inventory-specific event mappings (Task 006), the Inbox, projections, Event Sourcing, Sagas or modifications to the legacy `User` context or existing `RabbitMqEventBus` behaviour.

## 2. Context and authoritative references

- `AGENTS.md`: project workflow, architecture constraints, testing expectations and static-analysis rules.
- `docs/README.md`: documentation index and reading order.
- `docs/tasks/003-transactional-outbox-and-integration-infrastructure.md`: source of truth for Task003 scope and acceptance criteria.
- `docs/adr/003-outbox-rabbitmq.md`: transactional Outbox decision, message identity, publisher responsibilities, broker confirmation and routing.
- `docs/adr/004-integration-events-and-cross-context-communication.md`: integration-event envelope, optional `aggregateVersion`, producer responsibilities and event naming.
- `docs/architecture/architectural-reference.md`: Domain, Application and Infrastructure responsibilities, Outbox rules and testing strategy.
- `docs/architecture/open-decisions.md`: OD-002 and OD-003 remain OPEN and must not be resolved implicitly by this task.
- `src/Shared/Infrastructure/Bus/Event/RabbitMq/RabbitMqConnection.php`: existing `ext-amqp` wrapper. It does not provide the Outbox publisher's confirms, mandatory routing and persistent-delivery guarantees. Add new classes; do not modify legacy behaviour.
- `config/queue.php`: existing RabbitMQ connection configuration and `RABBITMQ_*` environment variables.
- `database/migrations/0001_01_01_000003_create_domain_events_table.php`: precedent for a shared technical table whose records remain context-owned.
- `tests/Integration/Shared/Infrastructure/Bus/Event/RabbitMq`: real RabbitMQ integration-test conventions.
- `tests/Shared/Infrastructure/Eloquent/MySqlDatabaseCleaner.php`: database cleanup helpers.
- `phpunit.xml`: Unit, Integration and Api suites.
- `ecs.php` and `phpstan.neon`: style and static-analysis configuration. Do not extend the PHPStan baseline.

The project uses the `Manager\` PSR-4 mapping to `src/`.

All Outbox code in this task is generic Shared infrastructure. Bounded contexts remain responsible for creating their own integration-event records and defining their own event mappings.

## 3. Agreed design constraints

### 3.1 Physical persistence

Use one shared table named `outbox`.

The `producer` column identifies the bounded context that owns each record. Shared infrastructure owns the technical storage and publication mechanism; it does not acquire ownership of the originating business event.

Do not create per-context Outbox tables in this task.

### 3.2 Integration-event envelope

The publisher emits the ADR004 envelope with the following top-level property order:

1. `eventId`
2. `eventName`
3. `eventVersion`
4. `aggregateType`
5. `aggregateId`
6. `aggregateVersion`
7. `occurredAt`
8. `payload`
9. `correlationId`
10. `causationId`

Optional fields whose stored value is absent must be omitted, not emitted as JSON `null`.

`aggregateVersion` remains optional. This task must not assume ordering guarantees based on it.

### 3.3 Deterministic serialization

The serialized envelope must be byte-identical whenever the same persisted Outbox record is published again.

Rules:

- Fixed top-level property order as specified above.
- Optional null fields are omitted.
- `occurredAt` is formatted as ISO 8601 UTC: `Y-m-d\TH:i:s\Z`.
- The timestamp is derived exclusively from the persisted `occurred_at` value.
- No clock, identifier or other variable value is generated during publication.
- Integer values remain integers; do not normalize them to floats.
- Use fixed JSON encoding flags: `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`.
- Do not use pretty printing.
- The payload is stored as validated JSON text and inserted verbatim as a JSON value into the envelope.
- Never decode and re-encode the stored payload during publication.
- Never insert the payload as an escaped JSON string.
- Preserve nested object property order, numeric lexical representation, Unicode and whitespace in the stored payload text.

The serializer must construct the envelope around the persisted payload text deterministically.

### 3.4 Payload representation and validation

The Application-level `OutboxMessage` must accept a JSON payload representation capable of expressing all valid JSON values required by integration events, including:

- Objects and nested objects.
- Arrays and nested arrays.
- Strings.
- Integers and decimal numbers.
- Booleans.
- JSON null values.

Do not restrict payloads to primitive-only PHP arrays.

The write boundary must validate the payload as syntactically valid JSON without replacing its original textual representation.

Recommended contract:

- `OutboxMessage` carries the payload as a JSON string (`payloadJson`).
- Validation checks JSON syntax and valid encoding.
- Validation must not replace the original string with a decoded-and-re-encoded representation.
- The recorder persists the validated string unchanged.
- The serializer inserts that stored string verbatim into the envelope.

### 3.5 Physical payload column

Use a textual MySQL column (`TEXT` or `LONGTEXT` according to the project's expected payload size), not MySQL's native `JSON` column type.

MySQL's native JSON storage does not guarantee preservation of the original textual representation, property order or numeric lexical form. It is therefore unsuitable for the byte-preservation requirement.

If supported by the project's MySQL version and migration conventions, add a database constraint using `JSON_VALID(payload)`.

Application-level validation remains mandatory even if a database constraint is present.

The `CHECK` constraint requires MySQL 8.0.16 or newer, where `CHECK` constraints are enforced. The project Docker image currently runs MySQL 8.0.32; verify the server version before relying on the constraint in another environment.

### 3.6 Same-connection transaction guarantee

`MySqlTransactionalSession` and `MySqlOutboxRecorder` must receive the same connection object through constructor injection.

The Outbox must not expose an independent database connection configuration. A single container factory or service-provider binding must resolve the application's default connection and inject the identical object into both adapters. Business repositories and the Outbox writer must therefore share the same underlying connection.

Requirements:

- No hidden `DB::connection()` lookup inside either adapter.
- No `outbox.connection` setting and no fallback to an independently resolved connection.
- `MySqlTransactionalSession` opens and completes the transaction using the injected connection.
- `MySqlOutboxRecorder` writes using that same injected connection.
- The recorder never opens, commits or rolls back its own transaction.
- The Outbox write participates in the transaction opened by `TransactionalSession`.

The integration test must assert connection object identity even when a stale `outbox.connection` configuration value is injected, and verify commit and rollback behaviour.

This abstraction does not integrate Inventory in Task003. Context-specific integration remains part of Task006.

### 3.7 Producer validation

The `producer` field must be validated against a configured allowlist in `config/outbox.php`.

Do not make the Application DTO depend directly on Laravel configuration or the framework container.

Responsibilities:

- `OutboxMessage` validates its structural fields, identifiers, names and JSON payload representation.
- A configured Infrastructure-level validator or equivalent adapter dependency validates that the producer is registered.
- `MySqlOutboxRecorder::append()` rejects an unknown producer before inserting.
- Producer names must be non-empty and match the configured allowlist exactly.

### 3.8 Claim ownership and fencing

Each claim attempt must generate a new unique `claim_token`, including when the same publisher process reclaims the same record.

`publisher_id` is diagnostic metadata only. It must never establish ownership.

Every state-changing operation performed by a publisher must include the current token in its conditional update:

- Mark published.
- Schedule retry.
- Mark terminal failure.
- Clear or update claim state.

A stale publisher whose lease expired must not be able to modify the record after another publisher has reclaimed it.

### 3.9 MySQL 8 claim strategy

Do not require a single SQL statement to both claim and return hydrated rows if that requirement forces an unsupported or fragile MySQL implementation.

Use a MySQL 8-compatible short transaction with row-level locking:

1. Begin a short database transaction.
2. Select eligible records using `SELECT ... FOR UPDATE SKIP LOCKED`.
3. Eligible records include:
   - `pending` records whose `next_attempt_at` is null or due.
   - `claimed` records whose lease has expired.
4. Exclude records that are not yet eligible.
5. Assign a fresh unique `claim_token` to each selected record.
6. Set `status = claimed`, `locked_at` and diagnostic `publisher_id`.
7. Increment the attempt counter as part of starting the publication attempt.
8. Hydrate the claimed records while the claim transaction is still active.
9. Commit the claim transaction.
10. Only after commit, serialize and publish the records to RabbitMQ.

The claim transaction must never remain open during RabbitMQ network I/O.

Use the transaction and row-lock semantics to ensure that concurrent publishers cannot receive the same live claim.

Expired claims must be recoverable without allowing the previous owner to retain write authority.

Integration tests must exercise this strategy using two real MySQL connections.

### 3.10 Attempt-count semantics

The `attempts` column represents total publication attempts started, including successful attempts.

Rules:

- Initial value: `0`.
- Increment exactly once when a record is claimed for a new publication attempt.
- First publication attempt changes the value from `0` to `1`.
- A successful publication leaves the attempt count unchanged after that increment.
- A failed publication on attempt 1 through 9 schedules a retry.
- A failed publication on attempt 10 transitions the record to terminal `failed`.
- A process crash after claiming a record still consumes that attempt.
- A reclaimed record must not be allowed to exceed ten total attempts.

If an expired claim is recovered and its stored attempt count is already 10, it must transition to terminal failure without starting an eleventh publication attempt. A pending record whose stored attempt count has reached 10 must also transition to terminal failure during claim recovery; it must never remain permanently pending and unclaimable.

Recovery of exhausted records is bounded by the same limit used for claiming and ordered by `created_at`, `id`; subsequent claims continue with the remaining records. This prevents a single claim transaction from locking and updating an unbounded backlog.

The recovery path must use row locking and conditional state transitions so that a stale publisher cannot override the terminal outcome.

### 3.11 Broker confirmation and routing

A record may transition to `published` only after both conditions are satisfied:

1. Positive broker publisher confirmation.
2. No returned message indicating that mandatory routing failed.

Use the actual PHP `ext-amqp` API verified in the project environment:

- `AMQPChannel::confirmSelect()`
- `AMQPChannel::waitForConfirm()`
- `AMQPChannel::setConfirmCallback()`
- `AMQPChannel::setReturnCallback()`
- `AMQPChannel::waitForBasicReturn()`

Do not use Python-style or snake-case method names such as `confirm_select()` in PHP implementation code.

The implementation must explicitly coordinate publisher confirms and returned-message handling. An ACK alone is not sufficient evidence that a mandatory message was routed successfully.

Treat the following as publication failures:

- Negative broker acknowledgement.
- Confirmation timeout.
- Returned unroutable message.
- Connection or channel failure before successful confirmation and routing verification.

These outcomes must never result in `published`.

Integration tests must use the real RabbitMQ service and the environment's connection configuration. Do not use mocked `AMQPChannel` objects to claim that confirms, mandatory routing or returns have been verified.

### 3.12 RabbitMQ publication properties

Use:

- Durable topic exchange, default name `integration_events`.
- Routing key based on `event_name`.
- `message_id` equal to the stable `eventId`.
- Mandatory routing (`AMQP_MANDATORY`).
- Persistent delivery mode 2.
- Publisher confirms.
- Returned-message handling.

Add new publisher classes. Do not alter the behaviour of the legacy `RabbitMqEventBus`.

### 3.13 Retry policy

Use ten total attempts.

- First publication attempt = attempt 1.
- Maximum total attempts = 10.
- Backoff base = 5 seconds.
- Exponential factor = 2.
- Maximum backoff = 10 minutes.
- Failure of attempt 10 transitions to terminal `failed`.

Terminal records retain:

- Original `event_id`.
- Original payload text.
- `failed_at`.
- `last_error`.
- `status = failed`.

Terminal records are not retried automatically.

### 3.14 Retention

Retention is an independent configurable Outbox policy.

Configuration:

- `published_retention_days`.
- Initial proposed value: 7 days.
- Configurable through `config/outbox.php`.

Only published records may be purged automatically.

Terminal `failed` records must never be removed automatically. Their deletion requires an explicit operator action.

The Outbox is not an Event Store. Purged published records are irrecoverable.

Consumers own their own idempotency and Inbox retention. Do not assume Task004 provides a global consumption confirmation.

Before reducing retention or purging records, operational recovery requirements and consumer processing commitments must be evaluated.

### 3.15 Execution and supervision

Use a short-lived drain command:

`manager:integration-events:outbox:publish --limit=N`

The command processes a bounded batch and exits.

Run it periodically using Supervisor or cron, following the existing Artisan and Supervisor conventions in the repository.

The runbook must document:

- Invocation and batch configuration.
- Supervisor or cron configuration.
- Restart and failure behaviour.
- Logging and monitoring.
- Recovery and requeue operations.

### 3.16 CLI naming

Use the following full command names consistently in code, tests, documentation and supervision:

- `manager:integration-events:outbox:publish`
- `manager:integration-events:outbox:requeue`
- `manager:integration-events:outbox:status`
- `manager:integration-events:outbox:purge`

### 3.17 Explicit exclusions

This task does not:

- Map Inventory Domain Events to Integration Events.
- Resolve OD-002 or OD-003.
- Implement Inventory persistence or Inventory Outbox integration (Task006).
- Implement Inbox consumers.
- Implement projections.
- Implement Sagas.
- Implement Event Sourcing.
- Add Redis.
- Add new Composer dependencies.
- Add Symfony Messenger AMQP transport.
- Modify the legacy `RabbitMqEventBus`.
- Modify the legacy `User` context or its event flow.
- Add PHPStan baseline entries.

## 4. Proposed file inventory

The following paths are the intended implementation locations. During implementation, inspect existing namespace and registration conventions and adapt only where an existing project convention requires a different location. Any material path or responsibility change must be reported during phase review.

### 4.1 Application contracts

- `src/Shared/Application/Outbox/OutboxMessage.php`
- `src/Shared/Application/Outbox/OutboxRecorder.php`
- `src/Shared/Application/Outbox/OutboxStatus.php`
- `src/Shared/Application/Transaction/TransactionalSession.php`

### 4.2 Infrastructure configuration and persistence

- `config/outbox.php`
- `database/migrations/<timestamp>_create_outbox_table.php`
- `src/Shared/Infrastructure/Database/MySqlTransactionalSession.php`
- `src/Shared/Infrastructure/Outbox/MySqlOutboxRecorder.php`
- `src/Shared/Infrastructure/Outbox/OutboxProducerValidator.php`
- `src/Shared/Infrastructure/Outbox/OutboxConnectionFactory.php` or the existing project's equivalent container binding.

### 4.3 Publisher and repository

- `src/Shared/Infrastructure/Outbox/OutboxRecord.php`
- `src/Shared/Infrastructure/Outbox/MySqlOutboxRepository.php`
- `src/Shared/Infrastructure/Outbox/OutboxEnvelopeSerializer.php`
- `src/Shared/Infrastructure/Outbox/OutboxPublisher.php`
- `src/Shared/Infrastructure/Outbox/OutboxRetryPolicy.php`
- `src/Shared/Infrastructure/Bus/Event/RabbitMq/RabbitMqConfirmedPublisher.php`

### 4.4 Console commands

- `src/Shared/Infrastructure/Console/Outbox/PublishOutboxCommand.php`
- `src/Shared/Infrastructure/Console/Outbox/RequeueOutboxCommand.php`
- `src/Shared/Infrastructure/Console/Outbox/OutboxStatusCommand.php`
- `src/Shared/Infrastructure/Console/Outbox/PurgeOutboxCommand.php`

Use the project's existing console command discovery and registration conventions. Do not introduce a parallel bootstrap mechanism.

### 4.5 Tests

Unit tests:

- `tests/Unit/Shared/Application/Outbox/OutboxMessageTest.php`
- `tests/Unit/Shared/Application/Outbox/OutboxStatusTest.php`
- `tests/Unit/Shared/Infrastructure/Outbox/OutboxEnvelopeSerializerTest.php`
- `tests/Unit/Shared/Infrastructure/Outbox/OutboxRetryPolicyTest.php`
- `tests/Unit/Shared/Infrastructure/Outbox/OutboxPublisherTest.php`

Integration tests:

- `tests/Integration/Shared/Infrastructure/Outbox/MySqlTransactionalSessionTest.php`
- `tests/Integration/Shared/Infrastructure/Outbox/MySqlOutboxRecorderTest.php`
- `tests/Integration/Shared/Infrastructure/Outbox/MySqlOutboxRepositoryTest.php`
- `tests/Integration/Shared/Infrastructure/Outbox/OutboxPublisherTest.php`
- `tests/Integration/Shared/Infrastructure/Bus/Event/RabbitMq/RabbitMqConfirmedPublisherTest.php`
- `tests/Integration/Shared/Infrastructure/Console/Outbox/OutboxCommandsTest.php`

Use the actual project test conventions and fixtures. Avoid duplicating existing database or RabbitMQ test infrastructure.

### 4.6 Documentation

- `docs/architecture/outbox-operations.md`
- `docs/README.md`
- `docs/tasks/003-transactional-outbox-and-integration-infrastructure.md`
- `docs/tasks/README.md`
- `docs/ai/implementation-log.md`

## 5. Phase 1 — Integration-event contract, Outbox schema and transactional write

### Goal

Deliver the Application-level contract for recording an integration event and the persistence guarantees that make the originating business change and its Outbox record atomic.

No Inventory integration is included.

### Application contracts

Create:

**`OutboxMessage`**

Fields:

- `eventId`
- `eventName`
- `eventVersion`
- `producer`
- `aggregateType`
- `aggregateId`
- `payloadJson`
- `occurredOn`
- Optional `aggregateVersion`
- Optional `correlationId`
- Optional `causationId`

Validation:

- Valid UUID event ID.
- Valid UUID aggregate ID.
- Non-empty event and aggregate names.
- Valid event version.
- Maximum lengths aligned with the persistence schema: `producer` 64 characters, `event_name` 255, `aggregate_type` 255, `correlation_id` 255, `causation_id` 255 and `event_version` 32.
- Length limits are counted in characters, not bytes, to match MySQL `VARCHAR` semantics.
- Valid JSON payload text.
- Optional values validated when present.
- No dependency on Laravel configuration.
- No decode/re-encode replacement of the supplied payload JSON text.

Producer allowlist validation is performed by the configured Infrastructure-level validator used by the recorder.

**`OutboxRecorder`**

```php
append(OutboxMessage $message): void
```

**`TransactionalSession`**

```php
execute(callable $operation): mixed
```

**`OutboxStatus`**

Enum values:

- `pending`
- `claimed`
- `published`
- `failed`

A `pending` record may transition to `failed` when it has exhausted the attempt limit during claim recovery.

Keep publication state in Shared Application/Infrastructure. Do not introduce Outbox publication states into bounded-context Domain models.

### Database schema

Create one `outbox` table.

Columns:

- `id`: internal UUID record identifier.
- `producer`: owning bounded context.
- `event_id`: UUID, unique.
- `event_name`.
- `event_version`.
- `aggregate_type`.
- `aggregate_id`: UUID.
- `aggregate_version`: nullable.
- `payload`: validated JSON stored as `TEXT` or `LONGTEXT`.
- `occurred_at`.
- `created_at`.
- `published_at`: nullable.
- `attempts`: integer, default 0.
- `next_attempt_at`: nullable.
- `locked_at`: nullable.
- `claim_token`: nullable.
- `publisher_id`: nullable.
- `last_error`: nullable.
- `status`: default `pending`.
- `failed_at`: nullable.

Store every Outbox date-time column (`occurred_at`, `created_at`, `published_at`, `next_attempt_at`, `locked_at` and `failed_at`) as MySQL `DATETIME`, not `TIMESTAMP`.

The convention is explicit UTC wall-clock time:

- The recorder formats values in UTC before writing them.
- MySQL `DATETIME` performs no session-timezone conversion.
- Readers interpret the stored values as UTC.
- Integration tests must verify UTC persistence while the MySQL session timezone is not UTC.

Indexes:

- Unique index on `event_id`.
- Index supporting pending eligibility and retry scheduling, beginning with `status` and `next_attempt_at`.
- Index supporting expired-lease lookup, beginning with `status` and `locked_at`.
- Add or adjust indexes based on the actual claim and status queries. Verify them with integration tests and query inspection.

Use the textual payload column to preserve the exact validated JSON representation. Do not use MySQL native JSON storage for this column.

### Infrastructure adapters

Create:

- `MySqlTransactionalSession`.
- `MySqlOutboxRecorder`.
- `OutboxProducerValidator`.
- Shared connection factory or equivalent existing container binding.

Both adapters must receive the identical connection object through constructor injection.

The recorder inserts through that object and never manages a transaction itself.

The session owns transaction begin, commit and rollback on that same object.

### Phase 1 tests

Unit:

- Valid and invalid UUIDs.
- Empty event or aggregate names.
- Maximum lengths for every schema-bounded string field.
- Length limits counted in characters rather than bytes.
- Invalid JSON payload.
- Nested JSON objects and arrays.
- Unicode and numeric JSON values.
- Optional fields.
- Unknown producer rejected by the recorder's configured validation path.
- Valid and invalid status transitions.

Integration:

Use a demo business table created by the test itself.

Each test must isolate its Outbox records by `event_id` and clean up only the records it created. Do not truncate or delete every row from shared tables.

Verify:

1. The session and recorder use the identical connection object.
2. An injected `outbox.connection` configuration cannot redirect the adapters away from the default connection.
3. Business row and Outbox row both persist on commit.
4. A business exception inside `execute()` rolls back both writes.
5. A failure during the transaction leaves neither write persisted.
6. The recorder does not open or commit its own transaction.
7. The persisted payload text is identical to the validated input text.
8. UTC wall-clock values are preserved when the MySQL session timezone is not UTC.
9. No publication or external broker artefact is created by a rolled-back transaction.

### Phase 1 completion checklist

- [x] Create `config/outbox.php` with producer allowlist and initial publisher settings.
- [x] Create the Outbox migration and indexes.
- [x] Create `OutboxMessage`, `OutboxStatus`, `OutboxRecorder` and `TransactionalSession`.
- [x] Create producer validation and shared connection wiring.
- [x] Create `MySqlTransactionalSession` and `MySqlOutboxRecorder`.
- [x] Eliminate independent Outbox connection configuration and assert default connection identity.
- [x] Store Outbox date-times as UTC `DATETIME` and verify with a non-UTC MySQL session timezone.
- [x] Isolate integration test records by `event_id` and clean only those records.
- [x] Enforce schema-aligned maximum lengths in the Application DTO.
- [x] Verify `created_at` UTC persistence under a non-UTC MySQL session timezone.
- [x] Add unit tests for contracts and validation.
- [x] Add real MySQL integration tests for connection identity, commit and rollback.
- [x] Verify the payload text is preserved.
- [x] Run PHPUnit, ECS and PHPStan using project commands inside Sail.
- [x] Do not extend the PHPStan baseline.
- [x] STOP and present the full diff and verification results for review.
- [x] Do not proceed to Phase 2 until the user explicitly asks.

## 6. Phase 2 — Claim fencing, deterministic envelope and confirmed RabbitMQ publication

### Goal

Deliver the end-to-end happy-path publication pipeline.

### Outbox record and repository

Create `OutboxRecord` as an Infrastructure read model containing all persisted event, status, attempt and claim fields needed by the publisher.

Create `MySqlOutboxRepository` with:

- Claiming using a short MySQL transaction and `SELECT ... FOR UPDATE SKIP LOCKED`.
- Fresh unique claim token per claimed record and per claim attempt.
- Lease-aware eligibility.
- Expired-lease recovery.
- Hydration before the claim transaction commits.
- No database locks held during RabbitMQ publication.
- Fenced `markPublished(recordId, claimToken, publishedAt)`.
- No retry scheduling, attempt limit or terminal state transition in this phase; those belong to Phase 3.
- Status guards on all state transitions.
- Diagnostic `publisher_id` only.

The claim strategy must be tested with two real MySQL connections. A record must never be simultaneously owned by two active publishers.

The publisher claims one record at a time so the lease only has to cover a single publication attempt, even when the command processes a larger total limit. Claiming a whole batch up front could let the lease expire before the last record is published.

### Envelope serializer

Create `OutboxEnvelopeSerializer`.

Responsibilities:

- Build the ADR004 envelope in fixed property order.
- Format the persisted timestamp as ISO 8601 UTC.
- Omit absent optional fields.
- Use fixed JSON encoding flags.
- Insert the stored payload JSON text verbatim as a JSON value.
- Never decode and re-encode the stored payload.
- Never generate identifiers or timestamps during serialization.
- Throw `OutboxSerializationException`, not `OutboxPublicationException`, when the envelope cannot be serialized.

Serialization failures are deterministic and must not be treated as transport failures. Phase 3 defines their terminal handling.

Tests must cover:

- Nested objects and arrays.
- Unicode.
- Integer and decimal representations.
- Boolean and null payload values.
- Optional envelope fields.
- Property order.
- ISO 8601 UTC.
- Exact byte equality across repeated serializations.
- Exact byte equality after the same record is reclaimed and republished.
- Invalid UTF-8 raises `OutboxSerializationException`.

### Confirmed RabbitMQ publisher

Create `RabbitMqConfirmedPublisher` using the existing `config/queue.php` connection configuration.

Use PHP `ext-amqp` methods:

- `confirmSelect()`
- `waitForConfirm()`
- `setConfirmCallback()`
- `setReturnCallback()`
- `waitForBasicReturn()`

The implementation must correctly coordinate confirmation and mandatory returned-message handling.

A positive ACK without successful routing verification must not be treated as successful publication.

Publish with:

- Durable topic exchange `integration_events`.
- Routing key `event_name`.
- `message_id = eventId`.
- Mandatory routing.
- Persistent delivery mode 2.
- Publisher confirms.

Do not modify `RabbitMqConnection` or `RabbitMqEventBus` legacy behaviour.

### Publisher orchestration

Create `OutboxPublisher`.

Flow:

1. Claim one record at a time and repeat up to the requested total limit.
2. Commit the claim transaction.
3. Serialize the persisted record. A serialization error raises `OutboxSerializationException` and never becomes a publication failure.
4. Publish through the confirmed RabbitMQ publisher.
5. On confirmed and routed success, perform fenced `markPublished`. If it returns false, throw `OutboxClaimLostException`, do not count the record as processed and stop the invocation. A successful publish does not imply a successful state transition.
6. On publication failure, propagate the failure and leave the record claimed. Do not mark it as published and do not schedule a retry. The expired lease makes it eligible for recovery.
7. On serialization failure, propagate `OutboxSerializationException` without marking the record as published. Phase 3 treats it as a terminal, non-retryable failure.
8. Stop the current invocation on publication, serialization or claim-loss failure.

Retry policy, attempt limits, terminal failure and failure-to-retry transitions are Phase 3 concerns and must not be introduced here.

Do not load domain aggregates or execute domain rules.

Do not perform RabbitMQ network I/O inside a business transaction or claim transaction.

### Publish command

Create:

`manager:integration-events:outbox:publish {--limit=}`

Register it using the existing Artisan command registration conventions.

The command processes a bounded batch and exits. When `--limit` is omitted, it uses `outbox.publish_batch` from configuration. The `--limit` option overrides the configured value.

### Phase 2 tests

Unit:

- Serializer byte stability.
- Optional-field omission.
- Claim eligibility.
- Publisher orchestration.
- One-at-a-time claims so the lease covers a single publication.
- Success transitions.
- Publication failure propagation without marking published.
- Lost claim after successful publication is signaled and not counted as processed.
- Serialization failure uses `OutboxSerializationException`, not `OutboxPublicationException`.
- Confirmation-state mapping for ACK, NACK, returned message and missing confirmation.
- Fencing behaviour.
- Configured default limit and `--limit` override.

Integration against real MySQL and real RabbitMQ:

- Use the environment's RabbitMQ configuration from `config/queue.php` and `RABBITMQ_*` variables.
- Do not hard-code Docker container names.
- A bound queue receives the exact persisted envelope.
- Verify message ID, routing key and persistent delivery.
- Positive broker confirmation plus successful routing is required before `published`.
- Unroutable message is never marked published.
- NACK is never marked published.
- The no-confirmation state is covered by unit tests; the real timeout is not reproduced deterministically against the broker.
- Two MySQL connections cannot claim the same live record.
- A stale publisher cannot mark a record published.
- Expired leases are recoverable.
- A failed publication leaves the record claimed, without retry scheduling or a published marker.
- Reclaimed publication preserves event ID and exact body bytes.

Do not use mocked `AMQPChannel` objects to claim that broker confirmation, mandatory routing or return behaviour has been verified.

### Phase 2 completion checklist

- [x] Create `OutboxRecord` and `MySqlOutboxRepository`.
- [x] Implement MySQL 8-compatible claim and lease recovery.
- [x] Implement claim-token fencing.
- [x] Create the deterministic envelope serializer.
- [x] Create the confirmed RabbitMQ publisher.
- [x] Verify ACK and returned-message coordination.
- [x] Create `OutboxPublisher` with one-at-a-time claims, explicit claim-loss signaling and no retry scheduling.
- [x] Create the publish command using `outbox.publish_batch` as its default limit.
- [x] Add unit tests.
- [x] Add real MySQL and RabbitMQ integration tests.
- [x] Verify duplicate publication identity and byte stability.
- [x] Run PHPUnit, PHPStan and ECS on changed files inside Sail.
- [x] Do not extend the PHPStan baseline.
- [x] STOP and present the full diff and verification results for review.
- [x] Do not proceed to Phase 3 until the user explicitly asks.

## 7. Phase 3 — Retry policy, terminal failure and operator recovery

### Goal

Deliver the complete failure lifecycle, terminal state and operator requeue mechanism.

### Retry policy

Create `OutboxRetryPolicy`.

Rules:

- Ten total attempts.
- First publication attempt is attempt 1.
- Attempts are incremented when a record is claimed for publication.
- A process crash after claim consumes that attempt.
- Failure of attempt 10 is terminal.
- Backoff base: 5 seconds.
- Backoff factor: 2.
- Backoff cap: 10 minutes.

The policy must expose clear operations for:

- Computing the next retry time from the current attempt count.
- Determining whether the failed attempt is terminal.

The implementation must not schedule an eleventh publication attempt.

The ten-attempt limit must also be enforced during claim: a pending record or expired claim with ten attempts must not be claimed for an eleventh attempt. Pending records and expired claims at the limit transition to terminal failure during claim recovery rather than being published again or remaining permanently unclaimable.

### Retry transition

Add or extend the repository transition:

`scheduleRetry(recordId, claimToken, nextAttemptAt, lastError)`

Requirements:

- Fenced by the current claim token.
- Only claimed records may transition.
- Sets `status = pending`.
- Sets `next_attempt_at`.
- Stores `last_error` for diagnostics.
- Clears claim ownership and lease fields.
- Preserves event ID, payload and attempt count.
- Must not schedule a retry when the stored attempt count has reached the limit; return `false` without mutating the record.
- A stale publisher cannot schedule a retry.

### Publisher failure mapping

- `OutboxPublicationException` is the only retryable failure category. A failed attempt below ten schedules a retry through the policy; a failed attempt ten transitions to terminal failure.
- `OutboxSerializationException` is deterministic and non-retryable. Transition the claimed record directly to terminal failure with the serialization error, without scheduling a retry.
- `OutboxClaimLostException` means another publisher owns the record. Do not schedule a retry, transition state or increment counters; stop the invocation and leave the record to its current owner.
- Do not catch arbitrary `Throwable` as a recoverable publication failure; unexpected exceptions propagate without mutating the record beyond its claim.

After a recoverable or terminal failure has been recorded, the invocation continues with the next record in the bounded batch. Claim loss and unexpected exceptions stop the invocation.

### Terminal transition

Add or extend the repository transition:

`markFailed(recordId, claimToken, failedAt, lastError)`

Requirements:

- Fenced by the current claim token.
- Sets `status = failed`.
- Sets `failed_at`.
- Stores `last_error`.
- Preserves an existing `last_error` during exhaustion recovery and uses a generic exhaustion message only when none is stored.
- Clears claim ownership and lease fields.
- Preserves event ID and payload text.

### Requeue command

Create:

`manager:integration-events:outbox:requeue {eventId}`

Rules:

- Only terminal `failed` records may be requeued.
- Set status to `pending`.
- Reset attempts to zero.
- Clear `failed_at` and `next_attempt_at`.
- Preserve `last_error` for diagnostics.
- Preserve event ID and payload exactly.
- Do not regenerate event identity or payload.
- Do not allow stale publisher tokens to manipulate state.

Requeue is an explicit operator action, not automatic retry behaviour.

### Phase 3 tests

Unit:

- Backoff sequence.
- Backoff cap.
- Ten-total-attempt boundary.
- Attempt 1 semantics.
- Terminal decision after attempt 10 fails.
- Recovery of an expired attempt-10 claim without an eleventh publish.
- Retry scheduling on a recoverable publication failure.
- Serialization failures transition directly to terminal failure without retry.
- Claim loss does not schedule a retry or mutate the record.
- Unexpected exceptions are not converted into retry scheduling.
- Stale publishers cannot schedule a retry.

Integration:

- A failed attempt below ten stores the retry time and error, clears the claim and remains retryable.
- Stale publishers cannot schedule a retry or overwrite retry state.
- Exhaustion transitions to `failed`.
- A pending record at the attempt limit transitions to `failed` during claim recovery, preserving event identity and payload.
- Exhaustion preserves an existing `last_error` and uses a generic message when none is stored.
- Exhausted-record recovery is bounded by the claim limit and uses stable `created_at`, `id` ordering; subsequent claims recover the remainder.
- Retry scheduling is refused at the attempt limit.
- Terminal record retains identity, payload, failure time and error.
- Failed records are not automatically published again.
- Requeue returns a failed record to pending.
- Subsequent publication emits the exact same envelope bytes and event ID.
- Fencing applies to every terminal transition.
- Stale publishers cannot overwrite state after requeue or lease recovery.

### Phase 3 completion checklist

- [x] Implement `OutboxRetryPolicy`.
- [x] Define and implement attempt accounting at claim time.
- [x] Enforce the ten-attempt limit at claim time.
- [x] Implement fenced retry transition.
- [x] Map publication failures to retry or terminal transitions.
- [x] Map serialization failures directly to terminal failure without retry.
- [x] Treat claim loss as non-retryable ownership loss without state mutation.
- [x] Implement terminal failure transition.
- [x] Implement expired attempt-10 recovery.
- [x] Implement the requeue command.
- [x] Add unit tests for backoff, failure mapping and attempt boundaries.
- [x] Add integration tests for retry persistence, stale retry fencing, exhaustion, recovery and byte-stable republication.
- [x] Run PHPUnit, ECS and PHPStan inside Sail.
- [x] Do not extend the PHPStan baseline.
- [x] STOP and present the full diff and verification results for review.
- [x] Do not proceed to Phase 4 until the user explicitly asks.

## 8. Phase 4 — Observability, retention, supervision and documentation

### Goal

Deliver operational visibility, configurable retention, structured logging and documentation.

### Status command

Create:

`manager:integration-events:outbox:status`

Report:

- Counts by `pending`, `claimed`, `published` and `failed`.
- A separate retrying count for pending records whose `next_attempt_at` is in the future.
- Age of the oldest pending record.
- Recent publication errors with event and aggregate identifiers.
- Relevant publisher health and connection failure information where available.

Never print payload contents or secrets.

### Purge command

Create:

`manager:integration-events:outbox:purge {--published-older-than-days=}`

Resolution order:

1. Explicit CLI option.
2. `config/outbox.php`.
3. Initial default: 7 days.

Only delete records with `status = published` older than the configured retention window.

Never automatically purge `pending`, `claimed` or `failed` records.

Terminal failures may only be removed through an explicit operator action with clear confirmation and documentation.

### Configuration

Complete `config/outbox.php` with:

- `producers`.
- Database connection documentation: the Outbox is fixed to the application's default connection, and no independent Outbox connection is configurable.
- Exchange name.
- Lease duration.
- Default publish batch.
- Retry configuration where appropriate.
- `published_retention_days`, initial proposed value 7.
- Publisher logging switches where needed.

### Structured logging

Log per record:

- Outcome.
- Event ID.
- Producer.
- Aggregate type.
- Aggregate ID.
- Attempt number.
- Error class and sanitized message.
- Connection failure details where applicable.

Log a batch summary.

Never log payload contents, credentials or secrets. Treat exception messages as untrusted input and apply a reusable sanitization policy before persisting `last_error`, writing logs or rendering status errors. The policy must redact credentials, passwords, tokens, authorization headers, private keys and similar secrets, and must normalize CR/LF, tabs and other non-printable control characters to prevent log forging. Payload-content sensitivity remains the responsibility of each producing bounded context.

### Operational runbook

Create `docs/architecture/outbox-operations.md`.

Include:

- Outbox responsibilities and limitations.
- Publisher drain execution model.
- Supervisor or cron configuration using the repository's existing conventions.
- Batch and lease configuration.
- Restart and failure behaviour.
- Monitoring through the status command and structured logs.
- Retry and terminal failure semantics.
- Manual requeue procedure.
- Published-record retention and purge.
- Explanation that the Outbox is not an Event Store.
- Explanation that purged published records are irrecoverable.
- Consumer independence and Inbox retention responsibilities.
- Explicit statement that Task004 does not provide a global consumption confirmation.
- Operational checks required before reducing retention or purging.

Update `docs/README.md` with the runbook entry.

Update `docs/tasks/003-transactional-outbox-and-integration-infrastructure.md` only after all acceptance criteria have actually been verified.

Update `docs/tasks/README.md` to reflect the verified Task003 status.

Add the Task003 entry to `docs/ai/implementation-log.md` using the existing template and actual verification output. Do not invent test counts or results.

### Phase 4 tests

Unit:

- Retention-window calculations.
- Age calculations.
- Status aggregation.

Integration:

- Purge removes only published records beyond the retention window.
- Pending records remain.
- Claimed records remain.
- Failed records remain.
- Status reports correct counts.
- Retrying bucket is correct.
- Oldest pending age is correct.
- Payload values are never included in status output or logs.
- Secrets embedded in exception messages are redacted before persistence, logging and status display.
- Control characters embedded in exception messages are normalized to single spaces, preventing log forging.
- Legacy stored errors are redacted when reported.

### Phase 4 completion checklist

- [x] Implement the status command.
- [x] Implement published-only purge.
- [x] Add configurable retention.
- [x] Add structured logging without payload values.
- [x] Add and apply a reusable error-sanitization policy before persistence, logging and display.
- [x] Write the Outbox operational runbook.
- [x] Update the documentation index.
- [x] Update Task003 status and acceptance criteria only after verification.
- [x] Update the task index.
- [x] Add the implementation log entry with real results.
- [x] Add unit and integration tests for status and purge.
- [x] Run PHPUnit, PHPStan and targeted ECS over the changed files inside the app container; full-project ECS retains pre-existing legacy findings.
- [x] Do not extend the PHPStan baseline.
- [x] STOP and present the full diff and verification results for review.

## 9. Cross-phase verification requirements

Each phase must finish with:

1. Relevant unit tests.
2. Relevant integration tests.
3. Full project PHPUnit verification where appropriate.
4. ECS.
5. PHPStan.
6. Review of the complete diff.
7. Review of changed files and architectural boundaries.
8. Presentation of actual command output and results.
9. STOP for user review.

Do not proceed to the next phase until the user explicitly asks.

Do not claim a test, static-analysis run or integration scenario passed unless its actual output has been inspected.

Do not extend the PHPStan baseline.

The user performs local implementation, verification, commit and push. The assistant reviews shared diffs and actual results; it does not modify the repository or perform commits.

## 10. Acceptance criteria

Task003 can be marked Verified only when all applicable criteria below have been implemented and verified.

### Persistence and transaction guarantees

- [x] One shared Outbox table exists.
- [x] Producer ownership is validated against configuration.
- [x] Event ID is unique.
- [x] Payload is validated and stored as text without representation replacement.
- [x] Business and Outbox writes share the same connection object and transaction.
- [x] Commit and rollback behaviour is verified against real MySQL.

### Publisher and reliability

- [x] Claims are exclusive under concurrent MySQL connections.
- [x] Every claim attempt receives a new token.
- [x] Expired leases are recoverable.
- [x] Stale publishers are fenced from all state-changing operations.
- [x] Envelope serialization is byte-stable.
- [x] Stored payload is inserted verbatim as a JSON value.
- [x] Lost claims are signaled and never reported as successfully transitioned.
- [x] RabbitMQ publication uses persistent delivery.
- [x] Positive broker confirmation is required.
- [x] Mandatory routing success is required.
- [x] Unroutable and negatively acknowledged messages are never marked published.
- [x] RabbitMQ behaviour is verified against the real broker.

### Failure handling

- [x] Ten total attempts are enforced.
- [x] Crashed claimed attempts count toward the limit.
- [x] Attempt 10 failure becomes terminal.
- [x] Expired attempt-10 claims cannot trigger an eleventh publication.
- [x] Pending records at the attempt limit cannot remain abandoned.
- [x] Exhaustion preserves an existing `last_error` and falls back to a generic message.
- [x] Exhausted-record recovery is bounded per claim transaction.
- [x] Retry scheduling is refused at the attempt limit.
- [x] Failed records remain observable.
- [x] Serialization failures become terminal without retry.
- [x] Requeue preserves event identity and payload bytes.

### Operations

- [x] All four CLI commands are registered and tested.
- [x] Publisher execution and supervision are documented.
- [x] Status metrics are correct.
- [x] Payload contents are excluded from logs and status output.
- [x] Credential-like values in error messages are redacted before persistence, logging and display.
- [x] Purge only deletes eligible published records.
- [x] Failed records are never automatically purged.
- [x] Retention policy and consequences are documented.

### Architecture and documentation

- [x] Domain Events are not published directly.
- [x] No Inventory-specific mapping has been introduced.
- [x] OD-002 and OD-003 remain open.
- [x] No Inbox, projections, Saga or Event Sourcing implementation has been introduced.
- [x] Legacy User event behaviour remains unchanged.
- [x] Legacy RabbitMqEventBus behaviour remains unchanged.
- [x] Task003 documentation reflects only verified implementation.
- [x] Implementation log contains actual verification results.

## 11. Next step

Phase 4 is implemented and technically verified. All phases in this plan are complete. Task003 is marked Verified in `docs/tasks/003-transactional-outbox-and-integration-infrastructure.md`; that status means the automated checks and acceptance criteria have been verified. Human review and approval remain pending.

Review the complete diff, test output, ECS and PHPStan results before approving any commit. No further implementation phase is planned for this plan.

Phase 4 exposes outbox health, bounds published retention and documents operations thanks to [Codely](https://codely.com) AI tooling. 📊 🧹 📕 🧭 🛰️ ✅
