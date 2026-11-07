# Task 03 — Transactional Outbox and Integration Infrastructure

**Status:** Proposed  
**Scope:** Shared infrastructure and bounded-context integration  
**Phase:** Reliable Event Publication

## 1. Objective

Implement a reliable mechanism for publishing integration events from bounded contexts through RabbitMQ using the Transactional Outbox pattern.

The implementation must guarantee that a business change and the corresponding outgoing integration event are committed atomically within the same database transaction.

RabbitMQ publication must happen independently from the business transaction, through an asynchronous publisher that reads pending Outbox records.

The design must tolerate temporary broker failures, process crashes, retries, and duplicate delivery without losing the original business change or silently discarding an event.

## 2. Architectural Principles

The implementation must follow these principles:

- Each bounded context owns its own Outbox records and persistence.
- Business state changes and Outbox records are written in the same database transaction.
- RabbitMQ publication happens after the business transaction commits.
- The publisher is independent of the business use case.
- A failed publication must not roll back an already committed business operation.
- Publication is at least once; consumers must be prepared for duplicate messages.
- Integration events use a stable event identity.
- The shared infrastructure may provide reusable publishing mechanisms, but it must not own or interpret bounded-context business models.
- The Outbox is a delivery mechanism, not an Event Store.

## 3. Scope

### In scope

- Define the Outbox persistence model.
- Implement the atomic write mechanism for business changes and Outbox records.
- Implement a publisher that retrieves pending records.
- Publish integration events through RabbitMQ.
- Handle concurrent publisher processes safely.
- Handle failures, retries, and process recovery.
- Define publication status and timestamp tracking.
- Define operational logging and monitoring requirements.
- Add unit and integration tests.
- Ensure each bounded context owns its own Outbox data.

### Out of scope

- Inbox implementation and consumer idempotency storage.
- Cross-context projections.
- Generic CQRS infrastructure.
- Event Sourcing or event replay.
- A central event registry that knows every bounded-context model.
- Business-specific event handlers.
- Redesign of the existing domain-event mechanism beyond the compatibility task.
- A generic workflow or saga engine.

## 4. Outbox Ownership

Each bounded context must own the Outbox records associated with its integration events.

For example:

```text
Orders
├── Domain
├── Application
└── Infrastructure
    └── Persistence
        └── Outbox

Inventory
├── Domain
├── Application
└── Infrastructure
    └── Persistence
        └── Outbox
```

The initial implementation may reuse common technical components for:

- Event serialization.
- RabbitMQ connection and publication.
- Publication scheduling.
- Retry handling.
- Logging and metrics.

However, each context remains responsible for writing its own Outbox records and defining which integration events it produces.

Shared infrastructure must not require direct knowledge of Orders, Inventory, or Catalog aggregates.

## 5. Integration Event Contract

The Outbox stores integration events, not arbitrary serialized domain objects.

The integration-event contract is defined separately in ADR 004.

The proposed envelope contains:

| Field | Purpose |
|---|---|
| `eventId` | Stable UUID identifying the integration event |
| `eventName` | Event type name |
| `eventVersion` | Version of the event contract |
| `aggregateType` | Type of aggregate that produced the event |
| `aggregateId` | UUID identifying the aggregate |
| `aggregateVersion` | Sequential version of the aggregate when available |
| `occurredAt` | Time at which the event occurred |
| `payload` | Event-specific integration data |
| `correlationId` | Optional identifier linking related operations |
| `causationId` | Optional identifier of the event or command that caused this event |

The event identity must be generated once and remain unchanged across retries.

The publisher must not generate a new event identity each time it attempts publication.

The event payload must contain only information required by consumers. Sensitive information, credentials, and internal implementation details must not be published.

## 6. Atomic Business Changes and Outbox Records

The central requirement is that the business state change and the corresponding Outbox record are committed in the same database transaction.

Conceptually:

```text
Begin transaction
    |
    +-- Apply business state change
    |
    +-- Persist integration event in Outbox
    |
Commit transaction
```

If either operation fails, the entire transaction must roll back.

The following situations must be impossible:

- Business state is committed but its required Outbox record is missing.
- An Outbox record is committed while the corresponding business state change is rolled back.

The application layer must coordinate the use case and the transaction boundary through explicit abstractions.

Domain code must not depend on Laravel database APIs, Eloquent, RabbitMQ, or the Outbox persistence implementation.

The transaction mechanism must be compatible with the project's modular monolith and MySQL infrastructure.

## 7. Outbox Persistence Model

The initial Outbox record should contain enough information to publish and recover an event without loading the original aggregate.

The proposed fields are:

| Field | Purpose |
|---|---|
| `id` | Internal Outbox record identifier |
| `event_id` | Stable integration-event UUID |
| `event_name` | Integration event name |
| `event_version` | Integration event contract version |
| `aggregate_type` | Aggregate type |
| `aggregate_id` | Aggregate UUID |
| `aggregate_version` | Aggregate version, when available |
| `payload` | Serialized event envelope or event payload |
| `occurred_at` | Event occurrence timestamp |
| `created_at` | Outbox record creation timestamp |
| `published_at` | Successful publication timestamp, nullable |
| `attempts` | Number of publication attempts |
| `next_attempt_at` | Earliest time for the next attempt, nullable |
| `locked_at` | Time at which a publisher claimed the record, nullable |
| `locked_by` | Publisher identifier or claim token, nullable |
| `last_error` | Last publication error, nullable |

The final schema must be reviewed against the chosen database conventions and operational requirements before implementation.

The event payload must be stored in a form that allows the publisher to reproduce the same message on every retry.

The Outbox must not depend on the original aggregate still existing.

## 8. Publisher Responsibilities

The publisher is responsible for:

1. Retrieving eligible unpublished Outbox records.
2. Claiming records safely so concurrent publisher processes do not process the same record simultaneously under normal operation.
3. Publishing the stored event envelope to RabbitMQ.
4. Marking a record as published only after the broker has acknowledged successful publication.
5. Recording publication attempts and errors.
6. Scheduling retries after temporary failures.
7. Recovering records left claimed by a process that crashed.

The publisher must not execute business rules or modify aggregate state.

It must not require the original aggregate to be loaded.

### 8.1 Concurrent publishers

The design must support multiple publisher processes without uncontrolled concurrent processing of the same record.

Use an atomic database claim mechanism appropriate for MySQL.

The implementation must define:

- How pending records are selected.
- How records are claimed.
- How claim ownership is represented.
- How expired claims are recovered.
- How a publisher confirms that it still owns a claim before updating the record.

The implementation must avoid holding a database transaction open while waiting for RabbitMQ publication.

### 8.2 Successful publication

A record may be marked as published only after RabbitMQ confirms successful acceptance according to the selected client and broker configuration.

The publisher must persist:

- Publication timestamp.
- Final attempt count.
- Cleared claim information.

The record must not be marked as published before broker confirmation.

### 8.3 Crash after publication

A process may publish a message successfully and crash before recording the successful publication in MySQL.

In this situation, the record will remain eligible for publication after claim recovery.

The same event may therefore be published more than once.

This is an expected limitation of the Transactional Outbox pattern and must be handled through stable event identity and consumer-side idempotency.

The publisher must not attempt to solve this by generating a new event identifier or assuming exactly-once delivery.

## 9. Retry and Failure Handling

The publisher must distinguish between successful publication and failed attempts.

Temporary failures must result in a retryable Outbox record.

The retry policy must define:

- Maximum or operationally controlled retry behaviour.
- Retry delay or backoff strategy.
- Maximum number of attempts, if a limit is applied.
- Handling of records that exceed the retry policy.
- Recovery and manual intervention procedures.

A failed publication must never silently delete an Outbox record.

If a record reaches a terminal failure state, it must remain observable and recoverable according to an explicit operational policy.

The implementation must define whether terminal failures remain in the Outbox table or are moved to a separate failure store.

This decision must preserve enough information to investigate and republish the original event safely.

## 10. RabbitMQ Responsibilities

RabbitMQ is the transport used to distribute integration events between bounded contexts.

The publisher must use the existing RabbitMQ infrastructure where appropriate, while avoiding coupling the Outbox implementation to the current direct domain-event publishing behaviour.

The implementation must verify:

- Durable exchange and queue configuration where required.
- Persistent message delivery where required.
- Publisher confirmation behaviour.
- Routing and event-name conventions.
- Connection failure handling.
- Retry and dead-letter responsibilities.

RabbitMQ delivery guarantees and Outbox persistence guarantees must be considered together.

A successful database commit does not imply that RabbitMQ has received the event. The publisher is responsible for completing that delivery asynchronously.

## 11. Observability and Operations

The implementation must provide enough operational information to diagnose publication problems.

At minimum, it must be possible to identify:

- Number of pending Outbox records.
- Number of successfully published records.
- Records currently claimed by publishers.
- Records awaiting retry.
- Records exceeding the retry policy.
- Age of the oldest pending record.
- Last publication error.
- Event identity and aggregate identity associated with a failure.

Logs must include event identifiers and useful correlation information where available.

Sensitive payload values must not be written to logs.

Operational recovery must not require modifying business aggregate data manually.

## 12. Testing Strategy

Tests must follow the established project test structure.

### Unit tests

Test:

- Outbox record creation.
- Event envelope serialization.
- Publication eligibility.
- Retry scheduling.
- Attempt counting.
- Claim state transitions.
- Successful publication state transitions.
- Terminal failure behaviour, if implemented.

### Integration tests

Test:

- Business state and Outbox record are committed atomically.
- Both are rolled back when the transaction fails.
- Pending records can be retrieved and claimed.
- Concurrent publishers do not normally claim the same record.
- Successful RabbitMQ publication marks the record as published.
- RabbitMQ failure leaves the record retryable.
- A crashed or expired claim can be recovered.
- A crash after broker publication but before database acknowledgement can result in duplicate delivery with the same event identity.
- The publisher can publish an event without loading its original aggregate.

Tests must use the actual persistence and RabbitMQ infrastructure required by the behaviour being verified. Mock-only tests are not sufficient to establish transaction atomicity or broker confirmation behaviour.

## 13. Acceptance Criteria

- [ ] Each bounded context owns its Outbox records.
- [ ] Business state changes and Outbox records are persisted in the same transaction.
- [ ] A failed transaction leaves neither the business change nor its Outbox record committed.
- [ ] Integration events are stored independently of the original aggregate lifecycle.
- [ ] Event identifiers remain stable across publication attempts.
- [ ] The publisher can retrieve and claim pending records safely.
- [ ] Concurrent publishers are supported.
- [ ] Publisher claims can be recovered after process failure.
- [ ] Records are marked as published only after successful broker confirmation.
- [ ] Temporary failures result in retryable records.
- [ ] Terminal failures remain observable and recoverable.
- [ ] A crash after broker publication does not cause the event to receive a new identity on retry.
- [ ] RabbitMQ publication uses the agreed integration-event envelope.
- [ ] Sensitive information is excluded from integration-event payloads and logs.
- [ ] Unit and integration tests cover the required behaviour.
- [ ] No Inbox, projection, or Event Sourcing implementation is introduced by this task.

## 14. Dependencies

This task depends on:

- The shared domain-event compatibility and test-structure foundation where existing shared event infrastructure is reused.
- ADR 003 — Transactional Outbox and RabbitMQ.
- ADR 004 — Integration Events and Cross-Context Communication.

The integration-event contract must be agreed before producers begin publishing events that other bounded contexts consume.

The Outbox implementation must remain independent of the future Inbox and projection implementations.

## 15. Follow-up Work

After the Outbox publisher is implemented, subsequent tasks will address:

- Consumer-side Inbox and idempotency.
- Context-owned projections.
- Projection query APIs.
- Event ordering and aggregate-version handling.
- Projection initialization and reconciliation.

These concerns must not be pulled into this task unless a concrete dependency is demonstrated and explicitly approved.
