# Task 003 — Transactional Outbox and Integration Infrastructure

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
- RabbitMQ network operations must never execute inside the business database transaction.
- A record may be marked as published only after both successful broker confirmation and successful routing have been established.

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
- Define the publisher execution and supervision mechanism.

### Out of scope

- Inbox implementation and consumer idempotency storage.
- Cross-context projections.
- Generic CQRS infrastructure.
- Event Sourcing or event replay.
- A central event registry that knows every bounded-context model.
- Business-specific event handlers.
- Redesign of the existing domain-event mechanism beyond the compatibility task.
- A generic workflow or saga engine.
- Inventory-specific Domain Event to Integration Event mappings, which belong to the Inventory integration work in Task 006.

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

The physical persistence structure and reuse boundaries must be defined without transferring Outbox ownership to a central component.

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

`aggregateVersion` is optional. When provided, it must represent a sequential version of the originating aggregate and must be persisted consistently with the corresponding business change.

Consumers must not assume that every producer provides an aggregate version or that its presence alone establishes a global ordering guarantee.

The existence of an integration event name does not imply a one-to-one mapping with a Domain Event. Each bounded context must explicitly define which Domain Events or application outcomes produce its Integration Events.

The event payload must contain only information required by consumers. Sensitive information, credentials, and internal implementation details must not be published.

The stored event representation must allow the publisher to reproduce the same logical message on every retry, preserving its identity, contract name, version, metadata, and payload.

The implementation plan must define whether the Outbox persists the complete serialized envelope or stores its components separately and reconstructs the envelope deterministically.

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

The atomicity guarantee applies to the business state and Outbox records owned by the same bounded context and database transaction. It must not be interpreted as a shared transaction across bounded contexts.

RabbitMQ publication must take place only after the database transaction has committed.

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
| `payload` | Serialized event envelope or the data required to reconstruct it deterministically |
| `occurred_at` | Event occurrence timestamp |
| `created_at` | Outbox record creation timestamp |
| `published_at` | Successful publication timestamp, nullable |
| `attempts` | Number of publication attempts |
| `next_attempt_at` | Earliest time for the next attempt, nullable |
| `locked_at` | Time at which a publisher claimed the record, nullable |
| `locked_by` | Publisher identifier or unique claim token, nullable |
| `last_error` | Last publication error, nullable |
| `status` | Publication state |
| `failed_at` | Time at which the record entered a terminal failure state, nullable |

The final schema must be reviewed against the chosen database conventions and operational requirements before implementation.

The publication state must distinguish at least:

- Pending publication.
- Currently claimed or processing.
- Successfully published.
- Terminal failure, when the retry policy defines one.

The exact state representation and transition rules must be defined in the implementation plan.

The event payload must be stored in a form that allows the publisher to reproduce the same logical message on every retry.

The Outbox must not depend on the original aggregate still existing.

The persistence model must retain enough information to investigate and safely recover or republish terminal failures according to the agreed operational policy.

## 8. Publisher Responsibilities

The publisher is responsible for:

1. Retrieving eligible unpublished Outbox records.
2. Claiming records safely so concurrent publisher processes do not process the same record simultaneously under normal operation.
3. Publishing the stored event envelope to RabbitMQ.
4. Marking a record as published only after successful broker confirmation and successful routing have been established.
5. Recording publication attempts and errors.
6. Scheduling retries after temporary failures.
7. Recovering records left claimed by a process that crashed.
8. Ensuring that only the current claim owner can update a claimed record.

The publisher must not execute business rules or modify aggregate state.

It must not require the original aggregate to be loaded.

The publisher execution model must be defined explicitly. The implementation plan must decide whether publication runs through a long-running process, a scheduled command, or another suitable mechanism supported by the project.

The chosen execution model must provide operational supervision, failure visibility, and recovery.

### 8.1. Concurrent publishers

The design must support multiple publisher processes without uncontrolled concurrent processing of the same record.

Use an atomic database claim mechanism appropriate for MySQL.

The implementation must define:

- How pending records are selected.
- How records are claimed.
- How claim ownership is represented.
- How claim expiration is determined.
- How expired claims are recovered.
- How a publisher confirms that it still owns a claim before updating the record.

Claim ownership must use a unique claim token or equivalent fencing mechanism. A stable publisher process identifier alone is not sufficient to prevent a previous claim owner from updating a record after its claim has expired and been reassigned.

Every state-changing update after a claim must be conditional on the current claim ownership.

If a claim has expired or been reassigned, the previous owner must not be able to mark the record as published, schedule a retry, or otherwise overwrite the current owner's state.

The implementation must avoid holding a database transaction open while waiting for RabbitMQ publication.

The exact claim and lease strategy must be selected during planning and verified through integration tests.

### 8.2. Successful publication

A record may be marked as published only after both of the following conditions have been established:

- RabbitMQ has returned a positive publisher confirmation.
- The message has not been returned as unroutable, using mandatory routing or an equivalent mechanism supported by the selected client and broker configuration.

A positive publisher confirmation alone does not establish that the message was successfully routed to an intended queue.

The implementation must treat broker confirmation and routing acceptance as distinct conditions.

A negative confirmation, confirmation timeout, connection failure, or unroutable-message return must not result in the record being marked as published.

The publisher must persist:

- Publication timestamp.
- Final attempt count.
- Cleared claim information.
- Successful publication state.

The record must not be marked as published before broker confirmation and routing acceptance have both been established.

The exact interaction between publisher confirms, mandatory routing, returned messages, and the installed `ext-amqp` version must be verified through integration tests against RabbitMQ.

### 8.3. Crash after publication

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
- Conditions under which a record enters a terminal failure state.
- Recovery and manual intervention procedures.
- Whether terminal failures remain in the Outbox table or are moved to a separate failure store.
- Retention and cleanup rules for published and terminally failed records.

A failed publication must never silently delete an Outbox record.

If a record reaches a terminal failure state, it must remain observable and recoverable according to an explicit operational policy.

The decision to retain terminal failures in the Outbox table or move them to a separate failure store must preserve enough information to investigate and safely republish the original event.

A terminal failure must not change or discard the original event identity or payload.

The implementation plan must define how an operator can identify, investigate, and recover a terminally failed record without manually modifying business aggregate data.

## 10. RabbitMQ Responsibilities

RabbitMQ is the transport used to distribute integration events between bounded contexts.

The publisher must use the existing RabbitMQ infrastructure where appropriate, while avoiding coupling the Outbox implementation to the current direct domain-event publishing behaviour.

The implementation must verify and configure:

- Durable exchange and queue configuration where required.
- Persistent message delivery.
- Publisher confirmation behaviour.
- Mandatory routing or an equivalent mechanism to detect unroutable messages.
- Routing and event-name conventions.
- Connection failure handling.
- Retry and dead-letter responsibilities.

The publisher must not consider a successful method call sufficient evidence of successful publication.

A record may be marked as published only after positive broker confirmation and successful routing have both been established.

RabbitMQ delivery guarantees and Outbox persistence guarantees must be considered together.

A successful database commit does not imply that RabbitMQ has received the event. The publisher is responsible for completing that delivery asynchronously.

RabbitMQ network operations must not be performed within the business database transaction.

The implementation must reuse the existing PHP `ext-amqp` infrastructure where appropriate. It must not introduce Symfony Messenger's AMQP transport or another RabbitMQ client solely to obtain publisher confirmation capabilities.

The actual behaviour of publisher confirms, returned messages, mandatory routing, and persistent delivery must be verified against the installed `ext-amqp` version and the project's RabbitMQ environment.

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
- Publisher execution and connection failures.

Logs must include event identifiers and useful correlation information where available.

Sensitive payload values must not be written to logs.

Operational recovery must not require modifying business aggregate data manually.

The implementation plan must define how the relevant operational information is exposed and monitored, including the supervision and failure reporting of the publisher process.

## 12. Testing Strategy

Tests must follow the established project test structure.

### Unit tests

Test:

- Outbox record creation.
- Event envelope serialization and reconstruction.
- Publication eligibility.
- Retry scheduling.
- Attempt counting.
- Claim state transitions.
- Claim ownership validation.
- Successful publication state transitions.
- Terminal failure behaviour, if implemented.
- Prevention of state updates by an expired or superseded claim owner.

### Integration tests

Test:

- Business state and Outbox record are committed atomically.
- Both are rolled back when the transaction fails.
- Pending records can be retrieved and claimed.
- Concurrent publishers do not normally claim the same record.
- A publisher cannot update a record after losing claim ownership.
- Expired claims can be recovered.
- Successful RabbitMQ publication marks the record as published only after positive broker confirmation and successful routing.
- A publisher confirmation without successful routing does not mark the record as published.
- RabbitMQ failure leaves the record retryable.
- A crashed or expired claim can be recovered.
- A crash after broker publication but before database acknowledgement can result in duplicate delivery with the same event identity.
- The publisher can publish an event without loading its original aggregate.
- Persistent delivery and the selected RabbitMQ routing configuration behave as expected.

Tests must use the actual persistence and RabbitMQ infrastructure required by the behaviour being verified. Mock-only tests are not sufficient to establish transaction atomicity, concurrent claim behaviour, broker confirmation, or routing behaviour.

## 13. Acceptance Criteria

- [ ] Each bounded context owns its Outbox records.
- [ ] Business state changes and Outbox records are persisted in the same transaction.
- [ ] A failed transaction leaves neither the business change nor its Outbox record committed.
- [ ] Integration events are stored independently of the original aggregate lifecycle.
- [ ] Event identifiers remain stable across publication attempts.
- [ ] The publisher can retrieve and claim pending records safely.
- [ ] Concurrent publishers are supported.
- [ ] Claim ownership is protected by a unique token or equivalent mechanism.
- [ ] A publisher cannot update a record after its claim has expired or been reassigned.
- [ ] Publisher claims can be recovered after process failure.
- [ ] Records are marked as published only after positive broker confirmation and successful routing.
- [ ] Persistent RabbitMQ delivery is configured and verified.
- [ ] Temporary failures result in retryable records.
- [ ] Terminal failures remain observable and recoverable.
- [ ] A crash after broker publication does not cause the event to receive a new identity on retry.
- [ ] RabbitMQ publication uses the agreed integration-event envelope.
- [ ] The publisher does not require the original aggregate to exist or be loaded.
- [ ] Sensitive information is excluded from integration-event payloads and logs.
- [ ] The publisher execution and supervision mechanism is defined and implemented.
- [ ] Unit and integration tests cover the required behaviour.
- [ ] No Inbox, projection, or Event Sourcing implementation is introduced by this task.

## 14. Dependencies

This task depends on:

- The shared domain-event compatibility and test-structure foundation where existing shared event infrastructure is reused.
- ADR 003 — Transactional Outbox and RabbitMQ.
- ADR 004 — Integration Events and Cross-Context Communication.

The integration-event contract must be agreed before producers begin publishing events that other bounded contexts consume.

The Outbox implementation must remain independent of the future Inbox and projection implementations.

The generic Outbox and publisher mechanisms introduced by this task must be reusable by bounded contexts without taking ownership of their business models or persistence.

Inventory-specific integration of its persistence and Domain Event to Integration Event mappings belongs to Task 006. Task 003 must not introduce Inventory-specific event mappings or assume that those mappings have already been approved.

### Related Open Decisions

The following architectural decisions remain open and must not be implicitly resolved by this task:

- **OD-002 — Aggregate Versioning and Event Ordering Guarantees:** `aggregateVersion` remains optional. This task must support integration events without an aggregate version. The decision must be resolved before any producer or consumer relies on aggregate versions for correctness or ordering guarantees.
- **OD-003 — Inventory Domain Events to Integration Event Mapping:** This task defines generic Outbox and publishing infrastructure but does not decide which Inventory Domain Events produce Integration Events or their specific payloads. That mapping must be approved before implementing Inventory integration in Task 006.

OD-001 — Atomicity of Order Confirmation Across Bounded Contexts and OD-004 — Projection Initialization and Reconciliation do not block the generic Outbox infrastructure defined by this task.

## 15. Follow-up Work

After the Outbox publisher is implemented, subsequent tasks will address:

- Consumer-side Inbox and idempotency.
- Context-owned projections.
- Projection query APIs.
- Event ordering and aggregate-version handling.
- Projection initialization and reconciliation.

These concerns must not be pulled into this task unless a concrete dependency is demonstrated and explicitly approved.
