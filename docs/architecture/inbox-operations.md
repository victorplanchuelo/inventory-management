# Inbox Operations

## 1. Purpose

This runbook describes how to operate the shared consumer-side Inbox infrastructure and the per-context Inbox tables.

It covers Inbox ownership, schema requirements, consumer identity, atomic processing, duplicate handling, aggregate-version ordering, RabbitMQ failure routing, observability, retention and purge. It does not define business projections or bounded-context event mappings.

## 2. Responsibilities and limitations

The Inbox guarantees duplicate suppression for the same `(consumer, eventId)` and records the outcome of each processing attempt. The Inbox row and the consumer-side business or projection change must commit in the same database transaction.

The Inbox is not a projection engine or an Event Store:

- It stores event metadata and processing outcomes, not payload contents.
- It does not reconstruct projections from historical events.
- It does not provide global ordering across aggregates or contexts.
- It does not serialize different events for the same aggregate by itself.
- Purged Inbox rows are irrecoverable and remove duplicate-detection history.

Task004 creates no production Inbox table or migration. Each bounded context creates its own `<context>_inbox` table when it introduces its first real integration-event consumer. Integration tests validate the mechanism with ephemeral tables that follow the same schema.

## 3. Components

| Component | Responsibility |
|---|---|
| Per-context Inbox table | Owned by one bounded context. Stores claims, outcomes and ordering metadata. |
| `IntegrationEventEnvelope` | Transport-independent, validated integration-event contract. |
| `InboxProcessor` | Applies the claim-first transaction, duplicate checks and ordering policy. |
| `InboxStore` / `MySqlInboxStore` | Persists claims, processed/stale outcomes and last processed aggregate versions. |
| `InboxStoreFactory` | Resolves the configured Inbox table from the consumer's context prefix. |
| `InboxMaintenanceStore` / `MySqlInboxMaintenance` | Reports status and purges committed records in bounded batches. |
| `RabbitMqIntegrationEventConsumer` | Acknowledges after commit and routes failures to retry or dead-letter queues. |
| `InboxLogger` | Structured processing, duplicate, stale, malformed, retry and dead-letter logs. |
| `ErrorSanitizer` / `OutboxErrorSanitizer` | Redacts credential-like values and normalizes control characters. |
| `manager:integration-events:inbox:status` | Read-only operational report. |
| `manager:integration-events:inbox:purge` | Explicit, manual purge of committed records. |

## 4. Per-context ownership

Each consuming bounded context owns one physical Inbox table. There is no shared Inbox table and no central Inbox owner.

`config/inbox.php` maps context names to table names:

```php
'contexts' => [
	'inventory' => 'inventory_inbox',
],
```

Task004 ships with no production contexts configured. The test suite registers a `test` context only through test configuration and never adds it to `config/inbox.php`.

Consumer identities must start with a configured context name followed by a dot, for example `inventory.stock_projection`. A consumer that does not match a configured context fails as an invalid consumer. This prevents a context from writing into another context's table by accident.

## 5. Schema and migration template

Future context migrations must copy this schema. Index and constraint names are prefixed with the physical table name.

```text
id                 char(36) primary key
consumer           varchar(128) not null
event_id           char(36) not null
event_name         varchar(255) not null
event_version      varchar(32) not null
aggregate_type     varchar(255) not null
aggregate_id       varchar(255) not null
aggregate_version  unsigned bigint nullable
occurred_at        datetime not null
processed_at       datetime nullable
outcome            varchar(16) nullable
correlation_id     varchar(255) nullable
causation_id       varchar(255) nullable
created_at         datetime not null

unique index <table>_consumer_event_id_unique (consumer, event_id)
index <table>_consumer_aggregate_version_index (consumer, aggregate_type, aggregate_id, aggregate_version)
index <table>_consumer_processed_at_index (consumer, processed_at)
index <table>_consumer_outcome_index (consumer, outcome)
check constraint <table>_processing_state_is_consistent:
    (processed_at is null and outcome is null)
    or (processed_at is not null and outcome is not null)
```

Laravel migration template:

```php
Schema::create('inventory_inbox', function (Blueprint $table): void {
	$table->uuid('id')->primary();
	$table->string('consumer', 128);
	$table->uuid('event_id');
	$table->string('event_name');
	$table->string('event_version', 32);
	$table->string('aggregate_type');
	$table->string('aggregate_id');
	$table->unsignedBigInteger('aggregate_version')->nullable();
	$table->dateTime('occurred_at');
	$table->dateTime('processed_at')->nullable();
	$table->string('outcome', 16)->nullable();
	$table->string('correlation_id')->nullable();
	$table->string('causation_id')->nullable();
	$table->dateTime('created_at');

	$table->unique(['consumer', 'event_id'], 'inventory_inbox_consumer_event_id_unique');
	$table->index(
		['consumer', 'aggregate_type', 'aggregate_id', 'aggregate_version'],
		'inventory_inbox_consumer_aggregate_version_index'
	);
	$table->index(['consumer', 'processed_at'], 'inventory_inbox_consumer_processed_at_index');
	$table->index(['consumer', 'outcome'], 'inventory_inbox_consumer_outcome_index');
});

DB::statement(
	'ALTER TABLE inventory_inbox ADD CONSTRAINT inventory_inbox_processing_state_is_consistent '
	. 'CHECK ((processed_at IS NULL AND outcome IS NULL) '
	. 'OR (processed_at IS NOT NULL AND outcome IS NOT NULL))'
);
```

Requirements:

- `id` and `event_id` use `CHAR(36)`, matching Laravel's MySQL `uuid()` representation. Do not use `BINARY(16)` or a native UUID type.
- `aggregate_id` is an opaque string per ADR004. Do not restrict it to UUID or integer formats.
- Charset and collation inherit from the application MySQL connection. Do not add per-table overrides.
- The unique `(consumer, event_id)` index is authoritative for duplicate suppression. The pre-claim `hasHandled` check is only an optimization.
- The CHECK constraint keeps `processed_at` and `outcome` consistent. It does not prove that a committed row is complete: both fields may be null in a committed incomplete row. Only the processor flow and transaction boundaries guarantee that a claim is marked before commit.
- Do not create a generic migration builder or a shared Inbox migration.

## 6. Envelope identity rules

- `eventId` is a UUID and is the stable event identity.
- `aggregateId` is a bounded opaque string, not necessarily a UUID.
- `occurredAt` must use exactly the producer format `Y-m-d\TH:i:s\Z`, in UTC, with seconds precision and a literal `Z`. Microseconds, numeric offsets and missing `Z` are rejected.
- `aggregateVersion` is optional and must be a positive integer when present.
- `correlationId` and `causationId` are optional bounded strings persisted as nullable diagnostic metadata.
- Unknown top-level fields are ignored for forward compatibility.

## 7. Configuration

| Environment variable | Config key | Default | Purpose |
|---|---|---|---|
| `INBOX_EXCHANGE` | `inbox.exchange` | `OUTBOX_EXCHANGE` or `integration_events` | Consumer exchange. Must equal `outbox.exchange`. |
| `INBOX_MAX_RETRIES` | `inbox.max_retries` | `5` | Retry publications before dead-lettering. |
| `INBOX_CONFIRM_TIMEOUT_SECONDS` | `inbox.confirm_timeout_seconds` | `5.0` | Confirmation timeout for retry and dead-letter publications. |
| `INBOX_RETENTION_DAYS` | `inbox.retention_days` | unset | Optional manual purge window. No duration is approved by default. |
| `INBOX_LOGGING_ENABLED` | `inbox.logging.enabled` | `true` | Enables Inbox structured logs. |

The fallback to `OUTBOX_EXCHANGE` only provides a default. `InboxExchangeValidator` compares the configured values during `InboxServiceProvider` boot and at the start of the configure command. A mismatch fails fast with `IncompatibleInboxExchange`.

## 8. Consumer identity and queue naming

Let `consume` be the configured consumer name.

- Main queue: `manager.integration.<consumer>`
- Retry queue: `retry.manager.integration.<consumer>`
- Dead-letter queue: `dead_letter.manager.integration.<consumer>`

The consumer name must use `<context>.<handler>`, for example `inventory.stock_projection`.

## 9. Atomic claim-first processing

The processor follows this sequence:

```text
Check (consumer, eventId)
    |
    +-- Complete committed row: return DUPLICATE without opening a transaction
    |
Begin transaction
    |
    +-- Insert claim with processed_at = NULL and outcome = NULL
    |       Unique violation: throw DuplicateInboxClaim to force a rollback
    |
    +-- Apply aggregate-version policy
    |       STALE: mark stale and return STALE
    |       GAP: throw retryable MissingAggregatePredecessor
    |       PROCESS: invoke the subscriber
    |
    +-- Mark processed or stale
    |
Commit
    |
Acknowledge RabbitMQ
```

Rules:

- A subscriber failure or `markProcessed` failure rolls back the claim and every business change made through the same connection.
- The processor never commits an incomplete claim on a successful path.
- The pre-claim check is not a substitute for the unique index.
- `claim()` returns `false` only for the `(consumer, event_id)` unique violation. Other database errors are rethrown.
- The duplicate-key loser throws an internal exception inside the transaction callback so its transaction rolls back before it re-reads the winning row.
- The loser returns `DUPLICATE` only when the committed winner has non-null `processed_at` and `outcome`. Otherwise it throws `InboxClaimNotVerified` as a retryable failure.
- The original RabbitMQ delivery is acknowledged only after the transaction commits, or after a verified duplicate/stale outcome, and after any retry or dead-letter publication is confirmed.

Subscriber obligations:

- Perform every local write through the same application default connection used by the Inbox transaction.
- Do not open a separate connection or transaction.
- Do not perform irreversible external side effects inside the Inbox transaction. Use a reliable mechanism outside it.

## 10. Ordering policies

Each subscriber chooses its policy explicitly:

- `NoOrderingAggregateVersionPolicy`: always processes. Does not query the last applied version.
- `MonotonicAggregateVersionPolicy`: recommended default for projections.
  - Missing version: process without invented ordering.
  - No baseline: process the first observed version.
  - Greater than last processed: process.
  - Less than or equal to last processed: stale.
- `StrictSequentialAggregateVersionPolicy`: only for subscriptions that cover the complete aggregate sequence.
  - Missing version: permanent `MissingAggregateVersion`.
  - No baseline: process the first observed version.
  - `last + 1`: process.
  - Greater than `last + 1`: retryable `MissingAggregatePredecessor`.
  - Less than or equal to last processed: stale.

`lastProcessedAggregateVersion` considers only rows with `outcome = 'processed'`. Stale rows never advance the applied version.

Duplicate detection by `(consumer, eventId)` always runs before stale detection. A different event with an older aggregate version is stale, not duplicate.

Strict sequencing requires a complete subscription and a context-owned initialization or reconciliation strategy. Without a baseline, the first observed version is accepted. Strict ordering across restarts is not guaranteed by the Inbox alone.

### Concurrency limits

The Inbox suppresses duplicate deliveries. It does not serialize different events for the same aggregate.

The default deployment is one active consumer process per queue. Do not run multiple workers against the same queue unless the consuming context provides per-aggregate serialization, such as a database row lock or advisory lock keyed by aggregate, or a partitioned queue with one consumer per partition.

Aggregate-version policies detect stale or gapped events in the observed sequence. They do not create ordering guarantees, and RabbitMQ delivery order must not be treated as a business-ordering guarantee.

## 11. RabbitMQ topology and failure routing

Let `X` be the main exchange, `XR = retry-X` and `XD = dead_letter-X`.

| Object | Declaration | Bindings |
|---|---|---|
| `Q = manager.integration.<consumer>` | Durable queue. | `Q -> X` with routing key `Q`; `Q -> X` with every subscribed event name. |
| `QR = retry.manager.integration.<consumer>` | Durable queue with `x-message-ttl = 1000`, `x-dead-letter-exchange = X`, `x-dead-letter-routing-key = Q`. | `QR -> XR` with routing key `Q`. |
| `QD = dead_letter.manager.integration.<consumer>` | Durable queue. | `QD -> XD` with routing key `Q`. |

All exchanges are durable `topic` exchanges.

Routing flows:

- Producer: publish to `X` with routing key = event name. The event-name binding routes it to `Q`.
- Retry: publish to `XR` with routing key `Q`. The retry-queue binding routes it to `QR`.
- Retry expiry: the broker dead-letters from `QR` to `X` with routing key `Q`. The queue-name binding routes it back to `Q`.
- Dead letter: publish to `XD` with routing key `Q`. The dead-letter-queue binding routes it to `QD`.

Failure classification:

- `PermanentIntegrationEventFailure`: invalid envelope, unsupported event name or version, missing required aggregate version, invalid consumer identity. Routed directly to the dead-letter queue.
- `RetryableIntegrationEventFailure` and any other `Throwable`: routed to the retry queue until the retry limit.
- Malformed messages are never silently acknowledged and discarded.

Retry and dead-letter publications preserve the original body, message ID, content type, encoding, priority, persistent delivery mode and headers, adding or incrementing `redelivery_count`. Dead-letter publications also add `x-error-class`, sanitized `x-error-message`, `x-consumer`, `x-event-id` when known, `x-event-name` when known, `x-original-queue` and `x-failed-at`.

Backoff is fixed at 1,000 ms. There is no exponential or progressive backoff. With the default `INBOX_MAX_RETRIES = 5`, a message is attempted once and retried five times before dead-lettering. The dead-letter copy carries `redelivery_count = 5`.

Retry and dead-letter publishing uses a dedicated confirmed publisher with `AMQP_MANDATORY`. A returned message or a negative acknowledgement fails the routing. The original delivery is not acknowledged when failure routing fails.

## 12. Observability

Structured log messages:

| Message | Level | When |
|---|---|---|
| `inbox.event.processed` | `info` | The claim and business effect committed. |
| `inbox.event.duplicate` | `info` | The event was already handled or a duplicate claim lost the race. |
| `inbox.event.stale` | `warning` | A stale aggregate version was recorded without invoking the subscriber. |
| `inbox.event.malformed` | `warning` | The envelope could not be deserialized. |
| `inbox.event.retry` | `warning` | The message was routed to the retry queue. |
| `inbox.event.dead_letter` | `error` | The message was routed to the dead-letter queue. |

Context includes `event_id`, `event_name`, `event_version`, `aggregate_type`, `aggregate_id`, `aggregate_version` when present, `consumer`, `correlation_id` and `causation_id` when present, outcome, retry count when applicable, sanitized error class and message for failures, and latency for processed, duplicate and stale outcomes.

Payload contents are never logged. Error messages are sanitized with `ErrorSanitizer`, which redacts credential-like values and normalizes control characters to prevent log forging. Sanitization is defense in depth and cannot detect arbitrary business secrets.

### Status command

```bash
php artisan manager:integration-events:inbox:status --context=inventory --older-than-days=30
```

- Without `--context`, it reports every configured context.
- Without a retention option or `INBOX_RETENTION_DAYS`, the purgeable count is reported as not evaluated.
- It reports total, processed and stale counts, per-consumer counts, oldest and newest completion timestamps, and purgeable records when a cutoff is available.
- It reads database state only and does not probe RabbitMQ.

## 13. Retention and purge

No Inbox retention duration is approved by default. `INBOX_RETENTION_DAYS` is unset in `.env.example`, and no automatic purge is scheduled.

Purge is manual and fails closed when no retention is configured:

```bash
php artisan manager:integration-events:inbox:purge --context=inventory --older-than-days=30 --limit=1000
```

Resolution order:

1. `--older-than-days`.
2. `inbox.retention_days`.
3. No default. The command fails when neither defines a positive integer.

The command:

- Deletes only rows with non-null `processed_at`, non-null `outcome` and `processed_at < cutoff`.
- Never deletes incomplete claims, recent records or in-flight claims.
- Orders eligible rows by `processed_at` and `id` and deletes at most `--limit` records per context.
- Without `--context`, it processes every configured context and prints one result line per context.
- Fails when the context is unknown, the retention or limit is not a positive integer, or no contexts are configured.

Example output:

```text
Purged 120 inbox record(s) from context <inventory> older than 2026-10-19 10:00:00 UTC (retention: 30 day(s)).
```

Before purging:

- Confirm consumers no longer need to replay the affected events.
- Keep the retention window longer than RabbitMQ redelivery and operational replay windows.
- Confirm backups and incident-response requirements.
- Understand that purged rows no longer protect against duplicate delivery of old messages.

The Inbox has no automatic cleanup. Monitor table growth until an operational retention decision is approved.

## 14. Operational runbook

1. Run the status command and record counts, per-consumer distribution, oldest/newest completion timestamps and purgeable records.
2. Check application logs for `inbox.event.*` entries and inspect sanitized errors.
3. Verify RabbitMQ connectivity, exchange existence and every queue binding.
4. Confirm one active consumer per queue unless the context provides per-aggregate serialization.
5. For retries, inspect `redelivery_count`. A message is dead-lettered after the configured retry limit.
6. For dead letters, inspect the original body and diagnostic headers, then fix the cause before replaying.
7. For stale events, confirm the producer sequence and the expected applied version. Do not replay a stale event to force an overwrite.
8. For gaps, confirm whether the subscription covers the complete aggregate sequence. Strict sequencing is only valid for complete subscriptions.
9. For purge, verify the retention window, context and limit before execution. Purge is irreversible.
10. Never paste payload contents, raw connection strings or unsanitized exception messages into incident tickets or chat.

## 15. Checklist for the first real context consumer

- [ ] Create `<context>_inbox` with the schema, constraints and index names from section 5.
- [ ] Add `<context> => '<context>_inbox'` to `config/inbox.php`.
- [ ] Implement a subscriber with a consumer name prefixed by the context and dot.
- [ ] Declare every subscribed event name and supported contract version in `subscribedTo()`.
- [ ] Choose the ordering policy deliberately. Use strict sequencing only for complete subscriptions.
- [ ] Write through the same application default connection as the Inbox transaction.
- [ ] Avoid irreversible external side effects inside the transaction.
- [ ] Initialise or reconcile the projection baseline if strict ordering across restarts is required.
- [ ] Ensure the deployment runs one active consumer per queue unless per-aggregate serialization exists.
- [ ] Add tests for duplicates, stale events, gaps and rollback using the real database.
- [ ] Decide and document the context's Inbox retention window before enabling purge.
- [ ] Verify exchange equality at boot and configure the RabbitMQ topology.
- [ ] Register the subscriber in the `integration_event_subscriber` tag.
- [ ] Review the queue bindings, retry TTL, dead-letter routing and diagnostics.

## 16. Known limitations

- Strict ordering across restarts depends on context-owned initialization or reconciliation.
- Duplicate suppression does not serialize different events for the same aggregate.
- The default deployment model is one active consumer per queue.
- The CHECK constraint does not prove that a committed row is complete.
- Inbox retention has no approved default and no automatic purge.
- Purged rows remove duplicate-detection history.
- The sanitizer is pattern-based and cannot infer arbitrary business secrets.
- Task004 does not decide project-wide producer versioning guarantees. OD-002 remains open.
