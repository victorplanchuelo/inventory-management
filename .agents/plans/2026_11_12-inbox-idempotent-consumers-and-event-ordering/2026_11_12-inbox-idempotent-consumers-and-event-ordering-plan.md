---
name: "inbox-idempotent-consumers-and-event-ordering"
description: "Implement per-context Inbox persistence, idempotent integration-event consumption, contract validation, aggregate-version ordering policies, retry and dead-letter handling, observability and retention."
created_at: "2026-11-12T15:12:14Z"

created_by:
  tool: "OpenCode"
  model:
    name: "DeepSeek V4.1 Flash Free"
    version: "4.1"
    reasoning_effort: "medium"
---

# Inbox, Idempotent Consumers and Event Ordering

## 1. Goal

Implement shared consumer-side infrastructure for processing RabbitMQ integration events idempotently, with each bounded context owning its own Inbox table. The Inbox record and the consumer-side business or projection change must commit in the same transaction, duplicate delivery must not repeat effects, and aggregate-version ordering must follow an explicit policy. Do not implement business projections, Event Sourcing or a generic CQRS framework.

## 2. Context and authoritative references

- `AGENTS.md`: project workflow, architecture constraints, testing expectations and static-analysis rules.
- `docs/README.md`: documentation index and reading order.
- `docs/tasks/004-inbox-idempotent-consumers-and-event-ordering.md`: source of truth for Task004 scope and acceptance criteria.
- `docs/adr/003-outbox-rabbitmq.md`: transactional Outbox, publication confirmation and RabbitMQ topology.
- `docs/adr/004-integration-events-and-cross-context-communication.md`: integration-event envelope, event identity, Inbox ownership, contract versioning and ordering rules.
- `docs/adr/005-cross-context-projections.md`: projection ownership, atomic Inbox and projection updates, stale-data handling and reconciliation boundaries.
- `docs/architecture/architecture.md`: bounded-context communication, consistency and failure scenarios.
- `docs/architecture/architectural-reference.md`: Domain, Application and Infrastructure responsibilities, Inbox rules and testing strategy.
- `docs/architecture/outbox-operations.md`: existing operational conventions for exchanges, retries, retention and command naming.
- `docs/architecture/open-decisions.md`: OD-002 remains OPEN and must not be resolved implicitly by this task.
- `src/Shared/Infrastructure/Bus/Event/RabbitMq/RabbitMqDomainEventsConsumer.php`: legacy consumer for the old domain-event envelope. It must remain unchanged.
- `src/Shared/Infrastructure/Bus/Event/RabbitMq/RabbitMqConnection.php`: existing `ext-amqp` connection wrapper, queue and exchange cache.
- `src/Shared/Infrastructure/Bus/Event/RabbitMq/RabbitMqConfigurer.php`: legacy queue topology configurer. It is coupled to `DomainEventSubscriber`; do not reuse it directly.
- `src/Shared/Infrastructure/Bus/Event/RabbitMq/RabbitMqQueueNameFormatter.php` and `RabbitMqExchangeNameFormatter.php`: naming conventions to mirror.
- `src/Shared/Infrastructure/Bus/Event/RabbitMq/ConsumeRabbitMqDomainEventsCommand.php`: command shape and queue-locator pattern.
- `src/Shared/Infrastructure/Outbox/OutboxEnvelopeSerializer.php`: canonical ADR004 envelope produced by the Outbox.
- `src/Shared/Infrastructure/Outbox/OutboxRecord.php`: envelope field definitions, including optional `aggregateVersion`.
- `src/Shared/Infrastructure/Outbox/OutboxConnectionFactory.php`: default-connection guarantee to mirror for the Inbox.
- `src/Shared/Infrastructure/Outbox/OutboxErrorSanitizer.php`: existing secret-redaction and control-character normalization utility.
- `src/Shared/Infrastructure/Outbox/OutboxPublisherLogger.php`: structured logging conventions.
- `src/Shared/Application/Outbox/OutboxRetention.php`: retention value-object pattern to mirror.
- `src/Shared/Application/Transaction/TransactionalSession.php`: transaction abstraction.
- `src/Shared/Infrastructure/Database/MySqlTransactionalSession.php`: MySQL transaction implementation.
- `src/Shared/Infrastructure/Database/DatabaseConnections.php`: connection cleanup used by consumer commands.
- `database/migrations/2026_11_10_000001_create_outbox_table.php`: schema and constraint conventions.
- `tests/Integration/Shared/Infrastructure/Bus/Event/RabbitMq/RabbitMqEventBusTest.php`: real RabbitMQ integration-test conventions.
- `tests/Integration/Shared/Infrastructure/Outbox/MySqlTransactionalSessionTest.php`: real MySQL transaction test conventions.
- `tests/Shared/Infrastructure/PhpUnit/InfrastructureTestCase.php`: integration test base.
- `tests/Shared/Infrastructure/Eloquent/MySqlDatabaseCleaner.php`: database cleanup helper.
- `config/queue.php`, `config/outbox.php`, `bootstrap/app.php`, `bootstrap/providers.php`, `app/Providers/OutboxServiceProvider.php`: configuration and provider conventions.
- `phpunit.xml`, `ecs.php`, `phpstan.neon`: test suites, style and static-analysis configuration. Do not extend the PHPStan baseline.

### Current state verified during research

- The legacy consumer only understands the old `DomainEventJsonSerializer` format (`data.type`, `data.attributes`, `data.id`, `data.occurred_on`). It does not understand the flat ADR004 integration-event envelope.
- `DomainEventJsonDeserializer` depends on `DomainEventMapping` and producer domain-event classes. It must not be reused for integration events.
- The Outbox publishes the ADR004 envelope with `eventId`, `eventName`, `eventVersion`, `aggregateType`, `aggregateId`, optional `aggregateVersion`, `occurredAt`, `payload`, optional `correlationId` and `causationId`.
- `OutboxEnvelopeSerializer` converts `occurredAt` to UTC and formats it with the literal pattern `Y-m-d\TH:i:s\Z`: seconds precision, no microseconds and no numeric offset. ADR004 only requires a timestamp; the producer serializer is the source of truth for the consumer wire contract.
- There is no Inbox table, Inbox store, integration-event subscriber, integration-event consumer or integration-event command. Task004 will not create a production Inbox table before a real consumer exists.
- `MySqlTransactionalSession` already opens transactions on the application default connection. `OutboxConnectionFactory` guarantees that the Outbox recorder and transaction session share the identical connection object.
- The existing Outbox migration uses a `uuid` column for `aggregate_id`, but ADR004 defines `aggregateId` as a string. Task004 must not modify the producer Outbox; consumer-side validation and Inbox storage accept opaque strings.
- There is no real consuming bounded context yet: `Inventory` has no Infrastructure layer and `Orders` does not exist. Task004 must deliver reusable shared infrastructure validated with a test subscriber, without creating business projections.
- The legacy `User` context and `RabbitMqEventBus` must remain unchanged.

## 3. Agreed design decisions

### 3.1 Per-context Inbox tables

Each consuming bounded context owns a physical Inbox table. Task004 does not create any production Inbox table or migration because no bounded context has a real integration-event consumer yet. Creating `inventory_inbox`, `orders_inbox` or `catalog_inbox` now would anticipate a context design that does not exist.

`config/inbox.php` maps context names to table names. It ships with no production contexts in Task004:

```php
'contexts' => [
	// No production contexts in Task004.
	// Add '<context>' => '<context>_inbox' together with the context's
	// Inbox migration when its first real consumer is implemented.
],
```

The first context that introduces a real consumer owns its table and migration. It must copy the schema, constraints and indices documented in section 4 and in `docs/architecture/inbox-operations.md`. Do not introduce a shared table, a generic migration builder or a central Inbox owner.

Integration tests validate the mechanism with ephemeral Inbox tables created in the real MySQL test database and dropped during teardown. The schema must be the same one future context migrations will use. Mocks are not acceptable for persistence, uniqueness or transaction guarantees.

`consumerName()` must be prefixed with a configured context name followed by a dot, for example `inventory.stock_projection`. The validator rejects any consumer that does not match a configured context, so a context cannot accidentally write into another context's Inbox.

The shared infrastructure owns only the reusable technical code. It does not own business event mappings, projections or context state.

### 3.2 Integration-event subscriber contract

`IntegrationEventSubscriber` is an application-level handler with instance methods, not static methods. This keeps test subscribers constructible with explicit event maps, policies and handler callables.

```php
interface IntegrationEventSubscriber
{
	/** @return array<string, list<string>> Event name to supported contract versions. */
	public function subscribedTo(): array;

	public function consumerName(): string;

	public function __invoke(IntegrationEventEnvelope $event): void;
}
```

Phase 3 extends the interface with `orderingPolicy(): AggregateVersionPolicy`.

The subscriber receives only the published integration-event contract. It must not import producer domain classes, repositories or persistence models.

The subscriber must execute every local database write through the same application default connection that `TransactionalSession` and `InboxStore` use. It must not open a separate transaction, switch to another database connection or perform irreversible external side effects inside the processing transaction. Those obligations are part of the contract and are not enforceable by the Inbox store.

### 3.3 Integration-event envelope

`IntegrationEventEnvelope` is a transport-independent value object built from the flat ADR004 envelope. It validates structural fields but does not interpret business payload fields.

- `eventId`: valid UUID string, following the stable event identity required by ADR004.
- `aggregateId`: non-empty opaque string with a bounded length (255). It must not be restricted to UUID, integer or any other producer-specific identifier format. Consumers must treat it as an opaque value.
- `eventName`, `eventVersion`, `aggregateType`: non-empty strings with bounded lengths.
- `aggregateVersion`: positive integer when present, absent otherwise.
- `occurredAt`: exactly the producer format `Y-m-d\TH:i:s\Z`, after UTC conversion, with seconds precision and a literal `Z`. Reject microseconds, numeric offsets (`+00:00`), missing `Z` and non-UTC representations. This matches `OutboxEnvelopeSerializer::OCCURRED_AT_FORMAT`; the producer must not be modified by Task004.
- `payload`: decoded JSON array. Integration-event payloads must be JSON objects or arrays, not scalars.
- `correlationId`, `causationId`: optional non-empty strings with bounded lengths.
- Unknown top-level envelope fields are ignored for forward compatibility.

### 3.4 Claim-first atomic processing

The Inbox uses a claim-first flow inside the consumer transaction:

```text
Check whether (consumer, eventId) is already handled
    |
    +-- If yes: return DUPLICATE without opening a transaction
    |
Begin transaction
    |
    +-- Insert Inbox claim (processed_at NULL, outcome NULL)
    |       If unique violation: throw an internal duplicate-claim signal
    |       so the transaction rolls back; then re-read the committed winner
    |       and return DUPLICATE only if it is complete, otherwise retryable failure
    |
    +-- Apply aggregate-version policy (Phase 3)
    |       STALE: mark claim as stale and return STALE
    |       GAP: throw retryable MissingAggregatePredecessor
    |       PROCESS: continue
    |
    +-- Invoke the integration-event subscriber
    |
    +-- Mark claim as processed with processed_at and outcome
    |
Commit transaction
    |
Acknowledge RabbitMQ message
```

Properties:

- A failed subscriber or failed `markProcessed` rolls back the claim and all business changes.
- The processor must not commit a claim unless it has been marked `processed` or `stale`.
- The database CHECK constraint only enforces that `processed_at` and `outcome` are both null or both non-null. It does not distinguish committed rows from uncommitted rows, so it cannot prove that a committed claim is complete.
- An incomplete row is never treated as a successful duplicate. `hasHandled` returns true only for a complete row, and a duplicate-key loser returns `DUPLICATE` only after verifying a complete committed winner; otherwise it throws a retryable failure.
- The duplicate check before claiming is an optimization, not a substitute for the unique constraint.
- `claim()` returns `false` only for a unique violation on `(consumer, event_id)`. Any other database error is rethrown and must not be interpreted as a duplicate.
- A concurrent duplicate blocks on the unique index. If the first transaction commits, the second receives a duplicate-key result and rolls back its own transaction. If the first transaction rolls back, the second claim succeeds and processes the event.
- The duplicate-key path throws a dedicated internal exception inside the `TransactionalSession` callback, forcing a rollback before verification. The processor catches it outside the callback and performs the committed-winner read on a fresh read.
- After rolling back a duplicate-key loser, the processor re-reads the Inbox row and returns `DUPLICATE` only when the winning claim is committed and complete (`processed_at` and `outcome` are non-null). If the row is absent or incomplete, it throws a retryable failure instead of returning `DUPLICATE`.
- The claim-first flow does not serialize different event IDs. Ordering across distinct events is governed by section 3.7.1 and remains a context responsibility.
- The RabbitMQ message is acknowledged only after `InboxProcessor::process()` returns `PROCESSED`, `DUPLICATE` or `STALE`, which happens after the transaction commits.
- If routing to retry or dead-letter fails, the consumer does not acknowledge and rethrows so RabbitMQ can redeliver.

### 3.5 Failure classification

- `PermanentIntegrationEventFailure`: invalid envelope, unsupported event name, unsupported event version, missing required aggregate version, invalid consumer identity. Routed directly to the dead-letter queue.
- `RetryableIntegrationEventFailure`: missing aggregate predecessor and explicitly classified transient failures. Routed to the retry queue with the fixed 1,000 ms TTL until `max_retries`, then to the dead-letter queue.
- Any other `Throwable` from a subscriber defaults to retryable. A subscriber that knows a business validation failure is permanent must throw an exception extending `PermanentIntegrationEventFailure`.
- A malformed message is never silently acknowledged and discarded. It is routed to the dead-letter queue with the original body and diagnostic headers.
- If retry or dead-letter routing fails, the original delivery is not acknowledged.

### 3.6 RabbitMQ topology

Use the same topic-exchange topology and naming conventions as the Outbox and the legacy consumer. Let:

- `X` = main exchange = `config('inbox.exchange')`, which must equal `config('outbox.exchange')`.
- `XR` = retry exchange = `retry-X`.
- `XD` = dead-letter exchange = `dead_letter-X`.
- `Q` = main queue = `manager.integration.<consumerName>`.
- `QR` = retry queue = `retry.manager.integration.<consumerName>`.
- `QD` = dead-letter queue = `dead_letter.manager.integration.<consumerName>`.

All three exchanges are `topic` and durable. All three queues are durable.

**Declarations and bindings**

| Object | Declaration | Bindings |
| --- | --- | --- |
| `Q` | Durable queue. | `Q -> X` with routing key `Q`; additionally `Q -> X` with routing key equal to every event name in `subscribedTo()`. |
| `QR` | Durable queue with `x-message-ttl = 1000`, `x-dead-letter-exchange = X` and `x-dead-letter-routing-key = Q`. | `QR -> XR` with routing key `Q`. |
| `QD` | Durable queue. | `QD -> XD` with routing key `Q`. |

**Publications and routing**

| Flow | Publication | Routing result |
| --- | --- | --- |
| Outbox producer | `X`, routing key = `eventName`. | Matched by the event-name binding on `Q`. |
| Retry | `XR`, routing key = `Q`. | Matched by the `QR` binding. |
| Retry expiry | Broker dead-letters from `QR` to `X`, routing key = `Q`. | Matched by the queue-name binding on `Q`. |
| Dead-letter | `XD`, routing key = `Q`. | Matched by the `QD` binding. |

The queue-name binding `Q -> X` with routing key `Q` is mandatory: without it, messages returning from the retry queue would be unroutable. The `QR -> XR` and `QD -> XD` bindings are also mandatory: without them, retry and dead-letter publications would be silently dropped by the topic exchange. The configure command must declare all exchanges, queues, queue arguments and bindings idempotently.

Retry and dead-letter publications preserve the original body, `message_id`, `content_type`, `content_encoding`, priority, persistent delivery mode and headers, adding or incrementing `redelivery_count`. Diagnostic dead-letter headers are added only for dead-letter publications.

The fallback `INBOX_EXCHANGE -> OUTBOX_EXCHANGE -> integration_events` only provides a default; it does not enforce equality. `InboxExchangeValidator` must compare `config('inbox.exchange')` and `config('outbox.exchange')` and fail fast with `IncompatibleInboxExchange` when they differ. The validator runs during `InboxServiceProvider` boot and at the start of `manager:integration-events:rabbitmq:configure`, so producer and consumer cannot use different exchanges unnoticed.

`max_retries` defaults to 5. The header `redelivery_count` starts absent (treated as 0) and is incremented on each retry publication. With the default, a message is attempted once and retried five times before dead-lettering.

Backoff is intentionally fixed: every retry waits exactly 1,000 ms in the retry queue. There is no exponential or progressive backoff in Task004. The retry queue declares `x-message-ttl = 1000` and is recreated with that value; tests and the operations document must assert the fixed delay and the initial-plus-five-retries sequence.

### 3.7 Ordering policies

The policy is selected explicitly by each subscriber.

- `NoOrderingAggregateVersionPolicy`: process every event without using `aggregateVersion`.
- `MonotonicAggregateVersionPolicy`: recommended default for projections. Missing versions process without invented ordering; an incoming version greater than the last applied version processes; an incoming version less than or equal to the last applied version is stale.
- `StrictSequentialAggregateVersionPolicy`: only for consumers whose subscription covers the complete aggregate sequence. Missing versions are permanent failures; `last + 1` processes; greater versions are gaps; older or equal versions are stale.

A version gap is not automatically a missing message. A consumer subscribed to a subset of events may legitimately observe gaps. `StrictSequentialAggregateVersionPolicy` must only be used when the complete-sequence requirement is documented.

When a consumer starts without a baseline, the first observed version is accepted. Strict ordering across restarts therefore depends on the consumer's initialization or reconciliation strategy, which is a context responsibility.

### 3.7.1 Concurrency and ordering limits

The Inbox guarantees duplicate suppression for the same `(consumer, eventId)`. It does not, by itself, guarantee serial processing of different events for the same aggregate.

The default operational model for Task004 is one active consumer process per queue. The consume command must not be run as multiple workers against the same queue unless the consuming context provides its own per-aggregate serialization.

If a context requires strict ordering while using concurrent workers, it must add a context-owned mechanism, for example a database row lock or advisory lock keyed by aggregate, or a partitioned queue with one consumer per partition. Task004 documents this responsibility and does not introduce a generic partition-lock system.

Aggregate-version policies detect stale or gapped events in the observed sequence. They do not create ordering guarantees by themselves, and RabbitMQ delivery order must not be treated as a business-ordering guarantee.

### 3.8 Observability and retention

- Structured logs include `eventId`, `eventName`, `eventVersion`, `aggregateType`, `aggregateId`, `aggregateVersion` when present, `consumer`, `correlationId` and `causationId` when present, outcome and latency.
- Payload values are never logged.
- Error messages are sanitized with the existing secret-redaction and control-character rules.
- No retention duration is approved. `retention_days` has no default value and no automatic purge is scheduled.
- Purge is manual, explicit, observable and fails closed when no retention is configured. Only committed rows with `processed_at < cutoff` are eligible.
- Status and purge commands are explicit, observable and safe.

### 3.9 Explicit exclusions

- No business-specific projection or Inventory/Orders event mapping.
- No generic CQRS or projection engine.
- No Event Sourcing.
- No central event registry.
- No cross-context database access.
- No distributed transactions.
- No Saga or workflow engine.
- No new dependencies, Redis, microservices or major framework upgrades.
- No changes to the legacy `User` context or `RabbitMqEventBus`.
- No producer-side Outbox changes.
- OD-002 remains OPEN. This task supports optional aggregate versions but does not define project-wide producer versioning guarantees.

## 4. Phase 1: End-to-end idempotent integration-event consumption with Inbox

### Description

Deliver a runnable integration-event consumer that persists an Inbox claim and the consumer-side business change atomically, acknowledges after commit, safely handles duplicates and routes failures to retry or dead-letter queues. This phase does not create a production Inbox table or migration; it delivers the shared reusable components validated against ephemeral Inbox tables in real MySQL integration tests.

### Public contracts

**Database schema template (not a production migration)**

Task004 must not add a production Inbox migration under `database/migrations`. The following schema is the contract used by the ephemeral test tables and the template that future context-owned migrations must copy. Index and constraint names are prefixed with the physical table name.

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

- UUID physical representation in MySQL: `id` and `event_id` are `CHAR(36)`, matching Laravel's MySQL `$table->uuid()` compilation. Do not use `BINARY(16)` or a native `UUID` type.
- Charset and collation are not hard-coded in the Inbox schema: they inherit from the application MySQL connection (`config('database.connections.mysql.charset')` and `.collation`, whose defaults are `utf8mb4` and `utf8mb4_unicode_ci` and may be overridden by `DB_CHARSET`/`DB_COLLATION`). Do not add per-table overrides in the Inbox migration.
- Ephemeral test tables are created on the same MySQL connection/database as the test suite and therefore inherit the identical charset and collation. Tests must assert `CHAR(36)` for `id` and `event_id` and assert that the ephemeral table collation equals the connection's configured collation, not a hard-coded literal.
- `aggregate_id` is an opaque string per ADR004. Do not apply a UUID column type or UUID-format validation.
- `causation_id` is persisted as nullable diagnostic metadata. It is not required for idempotency and needs no index.
- `docs/architecture/inbox-operations.md` must include the full migration template, the constraint rationale and a review checklist for future contexts.
- Do not create a generic migration builder in this task.
- Integration tests may create ephemeral tables that follow this schema; those tables are test infrastructure, not production migrations.

**Application**

- `Manager\Shared\Application\IntegrationEvent\IntegrationEventEnvelope`
  - `public static function fromArray(array $data): self`
  - Public readonly properties: `eventId`, `eventName`, `eventVersion`, `aggregateType`, `aggregateId`, `aggregateVersion`, `occurredAt`, `payload`, `correlationId`, `causationId`.
- `Manager\Shared\Application\IntegrationEvent\IntegrationEventSubscriber`
  - `public function subscribedTo(): array`
  - `public function consumerName(): string`
  - `public function __invoke(IntegrationEventEnvelope $event): void`
  - Local writes must use the same application default connection as the Inbox transaction. The subscriber must not open another connection or transaction, and must not perform irreversible external side effects inside the Inbox transaction.
- `Manager\Shared\Application\IntegrationEvent\Exception\InvalidIntegrationEventEnvelope`
- `Manager\Shared\Application\Inbox\InboxOutcome`
  - Cases: `PROCESSED = 'processed'`, `STALE = 'stale'`.
- `Manager\Shared\Application\Inbox\InboxRecord`
  - Read model with `public static function fromDatabaseRow(array $row): self`.
  - Fields include `id`, `consumer`, `eventId`, `eventName`, `eventVersion`, `aggregateType`, `aggregateId`, `aggregateVersion`, `occurredAt`, `processedAt`, `outcome`, `correlationId`, `causationId` and `createdAt`.
- `Manager\Shared\Application\Inbox\InboxStore`
  - `hasHandled(string $consumer, string $eventId): bool`
    - Returns `true` only for a committed, complete row (`processed_at` and `outcome` non-null). It is an optimization; the unique constraint remains authoritative.
  - `claim(IntegrationEventEnvelope $event, string $consumer, DateTimeImmutable $createdAt): bool`
    - Persists `correlationId` and `causationId` when present in the envelope.
    - Returns `false` only for the `(consumer, event_id)` unique violation. Other database errors must be rethrown.
  - `markProcessed(string $consumer, string $eventId, InboxOutcome $outcome, DateTimeImmutable $processedAt): void`
  - `find(string $consumer, string $eventId): ?InboxRecord`
- `Manager\Shared\Application\Inbox\InboxStoreFactory`
  - `forConsumer(string $consumer): InboxStore`
  - Throws `InvalidInboxConsumer` when the consumer context has no configured Inbox table.
- `Manager\Shared\Application\Inbox\InboxProcessingResult`
  - Cases: `PROCESSED = 'processed'`, `DUPLICATE = 'duplicate'`.
- `Manager\Shared\Application\Inbox\InboxProcessor`
  - `__construct(TransactionalSession $session, InboxStoreFactory $storeFactory, InboxConsumerValidator $consumerValidator)`
  - `process(IntegrationEventEnvelope $event, IntegrationEventSubscriber $subscriber): InboxProcessingResult`
  - The early `hasHandled` path returns `DUPLICATE` without opening a transaction. The duplicate-key path returns `DUPLICATE` only after the loser rolls back and verifies that the winning claim is committed and complete; otherwise it throws a retryable failure.
- `Manager\Shared\Application\Inbox\InboxConsumerValidator`
  - `__construct(array $contexts)`
  - `assertIsValid(string $consumerName): void`
- `Manager\Shared\Application\Inbox\InboxExchangeValidator`
  - `__construct(string $inboxExchange, string $outboxExchange)`
  - `assertCompatible(): void`
  - Throws `IncompatibleInboxExchange` when both values differ.
- `Manager\Shared\Application\Inbox\Exception\InvalidInboxConsumer`
- `Manager\Shared\Application\Inbox\Exception\IncompatibleInboxExchange`

**Infrastructure**

- `Manager\Shared\Infrastructure\Inbox\InboxConnectionFactory`
  - `connection(): Connection`, always the application default connection.
- `Manager\Shared\Infrastructure\Inbox\MySqlInboxStore`
  - `__construct(Connection $connection, string $table)`
  - Implements `InboxStore`.
- `Manager\Shared\Infrastructure\Inbox\MySqlInboxStoreFactory`
  - `__construct(Connection $connection, array $tables)`
  - Implements `InboxStoreFactory`.
- `Manager\Shared\Infrastructure\Bus\Event\RabbitMq\IntegrationEventEnvelopeDeserializer`
  - `deserialize(string $body, IntegrationEventSubscriber $subscriber): IntegrationEventEnvelope`
- `Manager\Shared\Infrastructure\Bus\Event\RabbitMq\IntegrationEventQueueNameFormatter`
  - `format(IntegrationEventSubscriber $subscriber): string`
  - `formatRetry(IntegrationEventSubscriber $subscriber): string`
  - `formatDeadLetter(IntegrationEventSubscriber $subscriber): string`
- `Manager\Shared\Infrastructure\Bus\Event\RabbitMq\IntegrationEventSubscriberLocator`
  - `__construct(iterable $subscribers)`
  - `withQueueNamed(string $queueName): IntegrationEventSubscriber`
  - `all(): array`
- `Manager\Shared\Infrastructure\Bus\Event\RabbitMq\IntegrationEventRabbitMqConfigurer`
  - `__construct(RabbitMqConnection $connection, string $exchangeName)`
  - `configure(IntegrationEventSubscriber ...$subscribers): void`
  - Declares `X`, `XR` and `XD` as durable topic exchanges.
  - Declares `Q`, `QR` and `QD` as durable queues, with `QR` using `x-message-ttl = 1000`, `x-dead-letter-exchange = X` and `x-dead-letter-routing-key = Q`.
  - Creates the queue-name and per-event-name bindings on `X`, the `QR -> XR` binding and the `QD -> XD` binding exactly as defined in section 3.6.
- `Manager\Shared\Infrastructure\Bus\Event\RabbitMq\RabbitMqIntegrationEventConsumer`
  - `__construct(RabbitMqConnection $connection, IntegrationEventEnvelopeDeserializer $deserializer, InboxProcessor $processor, InboxLogger $logger, string $exchangeName, int $maxRetries)`
  - `consume(IntegrationEventSubscriber $subscriber, string $queueName): void`
- `Manager\Shared\Infrastructure\Inbox\InboxLogger`
  - Basic structured logging for processed, duplicate, malformed, retry and dead-letter outcomes.
  - Never logs payload values.
- `Manager\Shared\Infrastructure\Console\IntegrationEvent\ConsumeIntegrationRabbitMqEventsCommand`
  - Signature: `manager:integration-events:rabbitmq:consume {queue} {quantity}`
  - Intended to run as a single active worker per queue. It does not coordinate multiple workers or provide per-aggregate ordering.
- `Manager\Shared\Infrastructure\Console\IntegrationEvent\ConfigureIntegrationRabbitMqCommand`
  - Signature: `manager:integration-events:rabbitmq:configure`
  - Calls `InboxExchangeValidator` before configuring the topology and exits with a clear failure when the exchanges differ.
- `App\Providers\InboxServiceProvider`
  - Binds the connection factory, store factory, processor, deserializer, locator, configurer, consumer, logger and commands.
  - Registers the `integration_event_subscriber` tag.
  - Calls `InboxExchangeValidator` during boot and fails fast when `inbox.exchange` and `outbox.exchange` differ.
- `config/inbox.php`:

```php
return [
	'contexts' => [
		// No production contexts in Task004. Add '<context>' => '<context>_inbox'
		// together with the context's Inbox migration when its first real consumer
		// is implemented.
	],
	// Fallback only. InboxExchangeValidator enforces equality with outbox.exchange.
	'exchange' => env('INBOX_EXCHANGE', env('OUTBOX_EXCHANGE', 'integration_events')),
	'max_retries' => (int) env('INBOX_MAX_RETRIES', 5),
	'retention_days' => env('INBOX_RETENTION_DAYS'),
	'logging' => [
		'enabled' => (bool) env('INBOX_LOGGING_ENABLED', true),
	],
];
```

- Register `InboxServiceProvider` in `bootstrap/providers.php`.
- Register the two commands in `bootstrap/app.php`.
- Add the new environment variables to `.env.example`. Leave `INBOX_RETENTION_DAYS` commented out or unset, with a note that no retention duration is approved yet. Document that `INBOX_EXCHANGE`, if set, must equal `OUTBOX_EXCHANGE` or the application fails fast.
- Do not modify `OutboxServiceProvider`, `RabbitMqProvider`, `RabbitMqDomainEventsConsumer`, `RabbitMqEventBus` or `DomainEventJsonDeserializer`.

### Test isolation and concurrency verification

- Every integration test creates unique `<prefix>_<uuid>` Inbox and business probe tables in `setUp` and drops them in `tearDown` or `finally`, including when the assertion fails.
- Each test uses its own table and consumer identity; tests must not share Inbox rows or depend on state left by another test.
- The duplicate-race test must use two real MySQL connections or worker processes synchronized through pipes. A single-threaded preinserted duplicate proves duplicate handling but not the race.
- The race worker receives the ephemeral table name and consumer identity explicitly; it must not depend on production `config/inbox.php` contexts.
- Tests that override exchange names must set both `inbox.exchange` and `outbox.exchange` to the same value; a mismatch is expected to fail fast.
- Tests must assert that only one claim commits, the losing attempt rolls back its own transaction, re-reads the winning row and returns `DUPLICATE` only after the winning claim is committed and complete.
- The commit and rollback subscriber must write through the same connection object used by `InboxProcessor`; assert connection identity and the transaction level inside and after the subscriber.
- A contract test must serialize a real `OutboxRecord` with `OutboxEnvelopeSerializer` and deserialize the result with `IntegrationEventEnvelopeDeserializer`, proving that the exact producer `occurredAt` format is accepted.
- Tests must assert commit and rollback atomicity against real MySQL: on success both rows persist; on failure neither row persists and the transaction level returns to zero.
- Tests must assert that the processor never commits an incomplete claim: after every successful path the row has both `processed_at` and `outcome`, and after `markProcessed` failure the row is rolled back.
- Tests must verify that no probe tables remain after the test run.

### To-do actions

- [ ] Do not create a production Inbox table or migration. Add a test-only helper that creates an ephemeral Inbox table with the reference schema, constraints and indices, and drops it during teardown.
- [ ] Implement `IntegrationEventEnvelope` with structural validation and `fromArray()`.
- [ ] Implement `IntegrationEventSubscriber`.
- [ ] Implement `InboxOutcome`, `InboxRecord`, `InboxStore`, `InboxStoreFactory`, `InboxProcessingResult`, `InboxConsumerValidator` and the Phase 1 exceptions.
- [ ] Implement `InboxProcessor` with the claim-first flow, duplicate handling and transaction coordination. Use a dedicated internal exception to force a rollback on a duplicate-key loser before re-reading the committed winner.
- [ ] Implement `InboxConnectionFactory`, `MySqlInboxStore` and `MySqlInboxStoreFactory`. Ensure the store and `TransactionalSession` receive the identical default connection object.
- [ ] Implement `IntegrationEventEnvelopeDeserializer` for the Phase 1 envelope and subscriber lookup.
- [ ] Implement `IntegrationEventQueueNameFormatter`, `IntegrationEventSubscriberLocator` and `IntegrationEventRabbitMqConfigurer`. Declare all exchanges, queues and bindings from section 3.6, including the queue-name and event-name bindings on the main exchange, the retry-queue binding on the retry exchange and the dead-letter-queue binding on the dead-letter exchange.
- [ ] Implement `RabbitMqIntegrationEventConsumer` with ack-after-commit, duplicate acknowledgement, fixed 1,000 ms retry routing and dead-letter routing.
- [ ] Implement basic `InboxLogger` without payload logging.
- [ ] Implement the consume and configure commands.
- [ ] Implement `InboxExchangeValidator` and `IncompatibleInboxExchange`; invoke the validator from `InboxServiceProvider` boot and from the configure command.
- [ ] Add `config/inbox.php`, `InboxServiceProvider`, provider registration, command registration and `.env.example` variables.
- [ ] Add unit tests for the envelope, processor, deserializer, queue formatter and logger, including accepting a non-UUID `aggregateId`, requiring a UUID `eventId`, and accepting exactly `Y-m-d\TH:i:s\Z` for `occurredAt` while rejecting microseconds, offsets, missing `Z` and non-UTC representations.
- [ ] Add exchange configuration tests: matching values pass; differing values throw `IncompatibleInboxExchange` with both exchange names; the configure command exits with failure when the exchanges differ.
- [ ] Add a producer-consumer contract test that serializes a real `OutboxRecord` with `OutboxEnvelopeSerializer` and deserializes it with `IntegrationEventEnvelopeDeserializer`; assert the exact `occurredAt` value and all envelope fields.
- [ ] Use a unique ephemeral Inbox table name per test, for example `inbox_probe_<uuid>`, and a separate business probe table when needed. Create both in `setUp`, drop them in `finally`/`tearDown`, and never reuse them across tests.
- [ ] Assert that ephemeral test tables use `CHAR(36)` for `id` and `event_id`, matching the migration template and Laravel's MySQL `uuid()` representation, and that their charset/collation match the test MySQL connection configuration rather than a hard-coded literal.
- [ ] Add integration tests using real MySQL ephemeral Inbox tables for `MySqlInboxStore`, atomic commit and rollback, duplicate delivery, concurrent duplicate processing, RabbitMQ success, retry and dead-letter behavior. Do not replace persistence with mocks.
- [ ] Add real RabbitMQ topology tests: an event published to `X` with routing key = event name reaches `Q`; a message published to `XR` with routing key = `Q` reaches `QR` and returns to `Q` through `X` after the TTL; a message published to `XD` with routing key = `Q` reaches `QD`. Assert topic/durable exchanges, durable queues, retry queue arguments and every binding from section 3.6.
- [ ] Add a test asserting that `InboxConnectionFactory`, `TransactionalSession` and `MySqlInboxStore` receive the identical application default connection object.
- [ ] Add a persistence test for optional `correlationId` and `causationId`: both are stored when present and remain null when absent.
- [ ] Add a commit test where the subscriber writes a business probe row through the same connection object as the Inbox transaction: assert connection identity, that the Inbox row and business row are both persisted after commit and that the transaction level returns to zero.
- [ ] Add a rollback test where the subscriber writes a business probe row through the same connection and then throws: assert that neither the Inbox row nor the business row persists and that the transaction level returns to zero.
- [ ] Add tests proving the processor never commits an incomplete claim: after every successful path the row has both `processed_at` and `outcome`; after a `markProcessed` failure neither the claim nor the business effect persists; no committed row has both fields null.
- [ ] Add a sequential duplicate test: assert that the second processing attempt returns `DUPLICATE` and does not repeat the business effect.
- [ ] Add a real duplicate-race test using two MySQL connections or worker processes synchronized through pipes: assert that exactly one claim wins, the losing connection rolls back its own transaction (transaction level zero) before re-reading the winning row, and returns `DUPLICATE` only when that row is committed and complete. Assert that only one business effect is persisted.
- [ ] Add a unit test for the duplicate-key branch where the winning row is absent or incomplete: assert that the processor throws a retryable failure instead of returning `DUPLICATE`.
- [ ] Add an integration test that inserts an incomplete committed Inbox row directly, then processes the same event: assert that it is not treated as a safe duplicate and that the processor raises a retryable failure.
- [ ] Add a test asserting that database errors other than the `(consumer, event_id)` unique violation are rethrown and never classified as duplicates.
- [ ] Add consumer tests asserting the fixed 1,000 ms retry delay, the initial delivery plus five retries, and dead-lettering after the fifth retry. Assert that no exponential or progressive backoff is used.
- [ ] Verify in test cleanup that ephemeral Inbox and business probe tables do not remain after teardown.
- [ ] In tests, register a `test` context pointing at the ephemeral table and use it only through test configuration; do not add it to `config/inbox.php`.
- [ ] Add a test subscriber fixture with configurable event map, consumer name and handler. Do not register it in production service providers.
- [ ] Run the relevant PHPUnit suites, targeted ECS and PHPStan. Fix issues without modifying the PHPStan baseline.
- [ ] STOP. Present the changes to the user for review and suggest commit messages. Do not proceed to Phase 2 until the user explicitly asks.

## 5. Phase 2: Contract validation and failure classification

### Description

Complete the integration-event contract validation and formalize the failure taxonomy so malformed or unsupported messages go directly to the dead-letter queue while transient failures follow the bounded retry policy.

### Public contracts

**Application exceptions**

- `Manager\Shared\Application\IntegrationEvent\Exception\PermanentIntegrationEventFailure` (abstract, extends `RuntimeException`).
- `Manager\Shared\Application\IntegrationEvent\Exception\RetryableIntegrationEventFailure` (abstract, extends `RuntimeException`).
- `Manager\Shared\Application\IntegrationEvent\Exception\UnsupportedIntegrationEventName` (extends `PermanentIntegrationEventFailure`).
- `Manager\Shared\Application\IntegrationEvent\Exception\UnsupportedIntegrationEventVersion` (extends `PermanentIntegrationEventFailure`).
- Reparent `InvalidIntegrationEventEnvelope` and `InvalidInboxConsumer` to `PermanentIntegrationEventFailure`.

**Validation**

- `IntegrationEventEnvelope` validates every structural field with the exact formats and bounds defined in section 3.3.
- `IntegrationEventEnvelopeDeserializer`:
  - Rejects malformed JSON.
  - Rejects a decoded value that is not an object/array.
  - Rejects missing required fields.
  - Rejects an event name not present in `subscriber->subscribedTo()`.
  - Rejects an event version not present in the subscriber's supported version list.
  - Ignores unknown top-level fields.
  - Never includes payload content in exception messages.

**Consumer routing**

- `RabbitMqIntegrationEventConsumer` catches `PermanentIntegrationEventFailure` and routes directly to the dead-letter queue.
- It catches `RetryableIntegrationEventFailure` and any other `Throwable` and routes to the retry queue with the fixed 1,000 ms TTL until `max_retries`, then to the dead-letter queue. There is no progressive backoff.
- Dead-letter publications preserve the original body and include diagnostic headers:
  - `x-error-class`
  - `x-error-message` (sanitized)
  - `x-consumer`
  - `x-event-id` when known
  - `x-event-name` when known
  - `x-original-queue`
  - `x-failed-at`
  - `redelivery_count`
- If retry or dead-letter publication fails, do not acknowledge the original delivery.

### To-do actions

- [ ] Add the permanent and retryable failure base exceptions and the unsupported event name/version exceptions.
- [ ] Reparent `InvalidIntegrationEventEnvelope` and `InvalidInboxConsumer`.
- [ ] Complete envelope validation for identifiers, names, versions, timestamps, payload and optional metadata. Validate `eventId` as UUID, keep `aggregateId` as an opaque bounded string per ADR004, and enforce the exact producer `occurredAt` format `Y-m-d\TH:i:s\Z` with no microseconds or offsets.
- [ ] Add supported event name and version validation to the deserializer using `subscribedTo()`.
- [ ] Update the consumer to route permanent failures directly to dead-letter and retryable or unknown failures through the bounded retry policy.
- [ ] Add dead-letter diagnostic headers without logging or exposing payload content.
- [ ] Add tests for unsupported event names, unsupported versions, malformed JSON, invalid `occurredAt` formats, permanent subscriber failures, retryable failures and dead-letter header preservation.
- [ ] Add tests asserting that permanent failures leave no successful Inbox row.
- [ ] Run the relevant PHPUnit suites, targeted ECS and PHPStan. Fix issues without modifying the PHPStan baseline.
- [ ] STOP. Present the changes to the user for review and suggest commit messages. Do not proceed to Phase 3 until the user explicitly asks.

## 6. Phase 3: Aggregate-version ordering

### Description

Add explicit aggregate-version policies so consumers can distinguish duplicates, stale events and missing predecessors. Stale events must not overwrite newer state, and gaps must follow a bounded retry policy instead of an infinite loop.

### Public contracts

**Application**

- `Manager\Shared\Application\Inbox\AggregateVersionPolicy`
  - `decide(?int $incomingVersion, ?int $lastAppliedVersion): AggregateVersionDecision`
- `Manager\Shared\Application\Inbox\AggregateVersionDecision`
  - Cases: `PROCESS`, `STALE`, `GAP`.
- `Manager\Shared\Application\Inbox\MonotonicAggregateVersionPolicy`
- `Manager\Shared\Application\Inbox\StrictSequentialAggregateVersionPolicy`
- `Manager\Shared\Application\Inbox\NoOrderingAggregateVersionPolicy`
- `Manager\Shared\Application\Inbox\Exception\MissingAggregateVersion` (extends `PermanentIntegrationEventFailure`).
- `Manager\Shared\Application\Inbox\Exception\MissingAggregatePredecessor` (extends `RetryableIntegrationEventFailure`).
- `IntegrationEventSubscriber` adds `orderingPolicy(): AggregateVersionPolicy`.
- `InboxStore` adds `lastProcessedAggregateVersion(string $consumer, string $aggregateType, string $aggregateId): ?int`.
- `InboxProcessingResult` adds `STALE`.
- `InboxProcessor` applies the subscriber policy after claiming and before invoking the subscriber:
  - `PROCESS`: invoke the subscriber and mark the claim as processed.
  - `STALE`: mark the claim as stale, do not invoke the subscriber, return `STALE`.
  - `GAP`: throw `MissingAggregatePredecessor`; the transaction rolls back the claim.
- Duplicate detection by `(consumer, eventId)` remains first. A different event with an older aggregate version is stale, not duplicate.
- `lastProcessedAggregateVersion` considers only rows with `outcome = 'processed'`, so stale rows never advance the applied version.

**Policy semantics**

- `NoOrderingAggregateVersionPolicy`: always `PROCESS`.
- `MonotonicAggregateVersionPolicy`:
  - incoming version absent: `PROCESS`.
  - no last applied version: `PROCESS`.
  - incoming greater than last applied: `PROCESS`.
  - incoming less than or equal to last applied: `STALE`.
- `StrictSequentialAggregateVersionPolicy`:
  - incoming version absent: throw `MissingAggregateVersion`.
  - no last applied version: `PROCESS` (first observed version).
  - incoming equals last applied plus one: `PROCESS`.
  - incoming greater than last applied plus one: `GAP`.
  - incoming less than or equal to last applied: `STALE`.

### To-do actions

- [ ] Implement the aggregate-version policy contract, decision enum and the three policies.
- [ ] Add `orderingPolicy()` to `IntegrationEventSubscriber` and update the test subscriber fixtures.
- [ ] Add `lastProcessedAggregateVersion()` to `InboxStore` and `MySqlInboxStore`.
- [ ] Add `STALE` to `InboxProcessingResult`.
- [ ] Update `InboxProcessor` to apply the policy, mark stale rows and throw the retryable missing-predecessor exception for gaps.
- [ ] Add unit tests for every policy case, including missing versions, first observed version, next version, stale version and gap.
- [ ] Add unit tests proving duplicate detection happens before stale detection.
- [ ] Add integration tests proving stale events do not overwrite newer projection state and are recorded with `outcome = 'stale'`.
- [ ] Add integration tests proving missing predecessors retry and eventually dead-letter, while events without aggregate versions process without invented ordering.
- [ ] Add an integration test proving `lastProcessedAggregateVersion` ignores stale rows.
- [ ] Document that strict sequencing requires a complete subscription and a context-owned initialization or reconciliation strategy.
- [ ] Document that duplicate suppression does not serialize different events for the same aggregate, that the default deployment is one active consumer per queue, and that strict ordering with concurrent workers requires a context-owned per-aggregate mechanism.
- [ ] Run the relevant PHPUnit suites, targeted ECS and PHPStan. Fix issues without modifying the PHPStan baseline.
- [ ] STOP. Present the changes to the user for review and suggest commit messages. Do not proceed to Phase 4 until the user explicitly asks.

## 7. Phase 4: Observability, retention and operations documentation

### Description

Expose enough operational information to diagnose processed, duplicate, stale, retrying and dead-lettered messages, add safe Inbox retention and purge commands, and document the consumer runbook. This phase must not introduce a generic monitoring or projection platform.

### Public contracts

**Application**

- `Manager\Shared\Application\Inbox\InboxRetention`
  - `public static function resolve(mixed $commandLineDays, mixed $configuredDays): ?self`
  - Returns `null` when neither the command line nor the configuration defines a retention.
  - Rejects non-numeric or non-positive values.
  - `public function cutoff(DateTimeImmutable $now): DateTimeImmutable`
- `Manager\Shared\Application\Inbox\Exception\InvalidInboxRetention`
- `Manager\Shared\Application\Inbox\InboxMaintenanceStore`
  - `contexts(): array`
  - `purgeProcessedBefore(string $context, DateTimeImmutable $cutoff, int $limit): int`
  - `snapshot(string $context, ?DateTimeImmutable $purgeCutoff): InboxStatusSnapshot`
- `Manager\Shared\Application\Inbox\InboxStatusSnapshot`
  - Public readonly fields: `context`, `totalRecords`, `processedRecords`, `staleRecords`, `recordsByConsumer`, `oldestProcessedAt`, `newestProcessedAt`, `purgeableRecords`.
  - `purgeableRecords` is `null` when no retention is explicitly configured or provided.

**Infrastructure**

- `Manager\Shared\Infrastructure\Logging\ErrorSanitizer`
  - `sanitize(string $message): string`
- `OutboxErrorSanitizer` implements `ErrorSanitizer` without behavior changes.
- `InboxLogger` is expanded with `ErrorSanitizer` and adds:
  - sanitized error messages and control-character normalization.
  - `recordStale(IntegrationEventEnvelope $event, string $consumer): void`
  - latency for processed and duplicate outcomes.
  - structured metadata defined in section 3.8.
  - no payload logging.
- `Manager\Shared\Infrastructure\Inbox\MySqlInboxMaintenance`
  - Implements `InboxMaintenanceStore`.
  - Purges only rows with `processed_at is not null and processed_at < cutoff`, in a bounded batch.
- `Manager\Shared\Infrastructure\Console\IntegrationEvent\InboxStatusCommand`
  - Signature: `manager:integration-events:inbox:status {--context=} {--older-than-days=}`
  - Reports total, processed, stale, per-consumer counts, oldest and newest processed timestamps and, when a retention is explicitly configured or provided, purgeable count.
  - When no retention is configured, reports that purgeable count is not evaluated.
- `Manager\Shared\Infrastructure\Console\IntegrationEvent\PurgeInboxCommand`
  - Signature: `manager:integration-events:inbox:purge {--context=} {--older-than-days=} {--limit=1000}`
  - Output: `Purged %d inbox record(s) from context <%s> older than %s UTC (retention: %d day(s)).`
  - Rejects non-positive retention or limit values with a failure exit code.
  - Fails closed with a clear message when neither `--older-than-days` nor `INBOX_RETENTION_DAYS` is configured. It never assumes a default duration.
- `config/inbox.php` keeps `retention_days` with no default and `logging.enabled`.
- Register the new commands in `bootstrap/app.php`.

**Documentation**

- `docs/architecture/inbox-operations.md` covering:
  - per-context Inbox ownership and the prohibition on a shared Inbox table.
  - the migration template with schema, `CHAR(36)` UUID representation, connection-inherited charset/collation, constraints, index naming and a review checklist for future contexts.
  - the CHECK constraint's actual guarantee: it keeps `processed_at` and `outcome` consistent but does not prove commit completeness; only the processor flow and tests ensure that.
  - consumer identity and queue naming.
  - exchange configuration: fallback is only a default, and mismatched `INBOX_EXCHANGE`/`OUTBOX_EXCHANGE` values fail fast at provider boot and at the configure command.
  - envelope identity rules: `eventId` is a UUID, `aggregateId` is an opaque string per ADR004, and `occurredAt` must match the producer format `Y-m-d\TH:i:s\Z`.
  - optional diagnostic metadata: `correlationId` and `causationId` are persisted as nullable Inbox columns.
  - atomic claim-first processing and duplicate detection.
  - the subscriber's obligation to write through the same database connection as the Inbox transaction and to avoid irreversible external side effects inside that transaction.
  - duplicate-key loser rollback and the requirement to verify a committed, complete winning claim before returning `DUPLICATE`.
  - ordering policies and the duplicate-versus-stale distinction.
  - concurrency limits: one active consumer per queue by default, and context-owned serialization for strict ordering with concurrent workers.
  - RabbitMQ topology: exchange and queue declarations, queue arguments and the complete binding and routing matrix from section 3.6.
  - failure classification, fixed 1,000 ms retry backoff, the initial-plus-five-retries sequence and dead-letter behavior.
  - observability fields and commands.
  - retention and purge safety, explicitly stating that no duration is approved by default, purge is manual and no automatic cleanup exists.
  - operational runbook and known limitations.
- Update `docs/README.md` with the new document and structure entry.
- Update `docs/ai/implementation-log.md` with actual verification results and review status.
- Update `docs/tasks/004-inbox-idempotent-consumers-and-event-ordering.md` only with verified results. Do not mark the task `Verified` before the checks pass and the technical review is approved.
- Keep OD-002 OPEN and document that this task supports optional aggregate versions without deciding project-wide producer guarantees.

### To-do actions

- [ ] Add the `ErrorSanitizer` interface and make `OutboxErrorSanitizer` implement it without changing behavior.
- [ ] Expand `InboxLogger` with sanitized error messages, stale outcomes, latency and structured metadata.
- [ ] Implement `InboxRetention`, `InboxStatusSnapshot`, `InboxMaintenanceStore` and `MySqlInboxMaintenance` with no default retention duration.
- [ ] Implement the status and purge commands with context selection, validation and clear output.
- [ ] Ensure purge only deletes committed rows older than the cutoff, never deletes recent or in-flight records and never runs automatically.
- [ ] Add unit tests for retention, status snapshot and sanitized logging without payload, including missing and invalid retention values.
- [ ] Add integration tests using real MySQL for purge cutoff, batch limit, context isolation, status counts and command validation failures. Do not replace persistence with mocks.
- [ ] Write `docs/architecture/inbox-operations.md`, including the full migration template with `CHAR(36)` UUID types, the CHECK constraint limitation, the same-connection subscriber rule, the duplicate-key loser protocol, the fixed 1,000 ms backoff, exchange validation and the checklist for the first real context consumer.
- [ ] Update `docs/README.md`, `docs/ai/implementation-log.md` and the Task004 document with verified results.
- [ ] Run the relevant PHPUnit suites, targeted ECS and PHPStan. Fix issues without modifying the PHPStan baseline.
- [ ] Run the full test suite and confirm no legacy tests regress.
- [ ] STOP. Present the changes to the user for review and suggest commit messages. Do not commit, push or open a pull request without explicit approval.

## 8. Risks, assumptions and explicit exclusions

### Assumptions

- Task004 creates no production Inbox table or migration. The first bounded context that introduces a real consumer creates its own `<context>_inbox` table and migration using the documented template.
- There is no real Inventory or Orders integration-event subscriber in this task. The test subscriber is the only concrete consumer and must not be treated as a production event mapping.
- Integration tests use ephemeral Inbox tables in the real MySQL test database. Persistence, uniqueness and transaction guarantees must not be mocked.
- `aggregateId` is an opaque string per ADR004; `eventId` remains a UUID.
- Inbox tables inherit charset and collation from the application MySQL connection; no per-table override is introduced by Task004.
- `correlationId` and `causationId` are persisted as optional diagnostic metadata; neither is required for idempotency.
- The default deployment model is one active consumer process per queue. Concurrent workers on the same queue require context-owned per-aggregate serialization for strict ordering.
- `occurredAt` uses exactly the producer format `Y-m-d\TH:i:s\Z`; Task004 does not modify the producer or accept alternate ISO 8601 representations.
- The subscriber writes through the same default database connection as the Inbox transaction and performs no irreversible external side effects inside it.
- `INBOX_EXCHANGE` must match `OUTBOX_EXCHANGE`. The fallback to `OUTBOX_EXCHANGE` is only a default, and a mismatch fails fast at provider boot and at the configure command.
- Backoff is fixed at 1,000 ms for every retry. With `max_retries = 5`, the original delivery plus five retries are attempted before dead-lettering.
- No Inbox retention duration is approved. `retention_days` has no default, purge is manual and the purge command fails closed when no duration is configured.
- The claim-first design requires `processed_at` and `outcome` to be nullable only while an uncommitted claim exists. The processor and transaction flow guarantee that a claim is not committed without being marked processed or stale; the CHECK constraint only enforces consistency between the two columns, not commit completeness.

### Risks

- **Strict ordering across restarts:** the first observed aggregate version is accepted when no baseline exists. Strict sequencing depends on the context's initialization or reconciliation strategy, which is outside Task004.
- **Concurrent different versions:** the unique `(consumer, event_id)` constraint protects duplicate deliveries, but it does not order different events for the same aggregate. Task004's default deployment is one active consumer per queue; strict ordering with multiple workers requires a context-owned per-aggregate lock or partitioning strategy. This task documents the limitation and does not introduce a partition-lock system.
- **Dead-letter volume:** permanent failures go directly to dead-letter. Operators must monitor and process the dead-letter queues.
- **Subscriber connection misuse:** if a subscriber opens another connection or transaction, the shared transaction cannot guarantee atomicity. This is a contract obligation enforced by review and tests, not by the Inbox store.
- **Irreversible side effects:** a subscriber that performs an external side effect inside the Inbox transaction cannot rely on rollback to undo it. Such effects must use a reliable mechanism outside the transaction.
- **Duplicate-key misclassification:** treating unrelated database errors as duplicates or returning `DUPLICATE` before verifying a committed, complete winning claim would break idempotency. Tests must cover both branches.
- **Incomplete committed claim:** the CHECK constraint permits `processed_at` and `outcome` to both be null. Only the processor flow guarantees that a claim is marked before commit. Tests must assert that no incomplete Inbox row is committed.
- **Exchange drift:** if validation is bypassed, producer and consumer could use different exchanges. Provider boot and the configure command both validate; any future configuration path must call the same validator.
- **Producer aggregate ID mismatch:** the existing Outbox schema stores `aggregate_id` as UUID, while ADR004 defines it as a string. Task004 fixes the consumer side only; producer-side alignment remains outside scope and must be addressed if a producer needs non-UUID aggregate identifiers.
- **No retention decision:** until an operational duration is approved, Inbox rows accumulate and no purge runs. Growth must be monitored; this is intentional to avoid deleting duplicate-detection history prematurely.
- **Retention versus replay:** any future purge removes duplicate-detection history. The eventual retention window must remain longer than operational replay and retry windows.
- **First-real-consumer setup:** the first context to add a consumer must add both its Inbox migration and its `config/inbox.php` entry. Missing configuration fails fast, and the operations document provides the checklist.
- **Per-context table drift:** future context Inboxes must copy the same schema and constraints. The operations document must include the template and a review checklist.
- **Test schema drift:** the ephemeral test table helper and the documented migration template must stay identical, including `CHAR(36)` for UUID columns and the connection-inherited charset/collation. Using `BINARY(16)`, a native UUID type or a per-table charset/collation override could change equality and uniqueness semantics.
- **PHPStan and ECS:** new code must pass targeted ECS and PHPStan without new baseline entries. Full-project ECS may still report pre-existing legacy findings in untouched files.

### Explicit exclusions

- No producer-side Outbox changes.
- No Inventory or Orders business projections.
- No generic CQRS, projection framework or event registry.
- No Event Sourcing.
- No cross-context database access.
- No distributed transactions.
- No Saga or workflow engine.
- No Redis, microservices or new dependencies.
- No changes to the legacy `User` context or `RabbitMqEventBus`.
- No automatic projection reconstruction from historical Outbox records.

## 9. Next step

Start with Phase 1: deliver the end-to-end idempotent integration-event consumer using ephemeral Inbox tables in integration tests, with atomic claim-first processing, duplicate handling, retry and dead-letter routing. Do not create a production Inbox table; the first real context consumer will add its own migration.

Inbox idempotency ready to roll by 🐢 💨 (Turbotuga™, [Codely](https://codely.com)'s mascot).
