# Task 04 — Inbox, Idempotent Consumers and Event Ordering

**Status:** Proposed  
**Scope:** Shared integration infrastructure and bounded-context consumers  
**Phase:** Reliable Event Consumption

## 1. Objective

Implement a reliable consumer-side mechanism for processing integration events received through RabbitMQ.

The implementation must ensure that each bounded context can process an integration event idempotently, even when RabbitMQ delivers the same message more than once.

The Inbox mechanism must persist message-processing information atomically with the consumer's business changes or projection updates.

The implementation must also provide a consistent approach to event ordering and aggregate-version validation where producers supply aggregate versions.

## 2. Architectural Principles

The implementation must follow these principles:

- Each consuming bounded context owns its Inbox records.
- Consumers must not assume that RabbitMQ delivers messages exactly once.
- Event identity must remain stable across delivery attempts.
- An event must not produce duplicate business effects.
- Inbox persistence and the corresponding consumer-side changes must be committed in the same database transaction.
- Consumers must be independent of the producer's internal domain model.
- Event ordering must be handled explicitly where aggregate versions are available.
- A failed processing attempt must not be recorded as successfully processed.
- Shared infrastructure may provide reusable technical components without centralizing business behaviour.

## 3. Scope

### In scope

- Define the Inbox persistence model.
- Implement event identity-based duplicate detection.
- Implement atomic message-processing transactions.
- Integrate Inbox handling with RabbitMQ consumers.
- Define successful and failed processing behaviour.
- Handle duplicate message delivery.
- Define aggregate-version validation and stale-event handling.
- Define behaviour for missing or out-of-order aggregate versions.
- Add unit and integration tests.
- Define operational observability for failed and delayed messages.

### Out of scope

- Transactional Outbox implementation.
- Business-specific projection definitions.
- Generic CQRS infrastructure.
- Event Sourcing.
- A central event-processing registry.
- Cross-context database access.
- Automatic reconstruction of projections from historical Outbox records.
- Distributed transactions across bounded contexts.
- A generic saga or workflow engine.

## 4. Inbox Ownership

Each consuming bounded context owns its Inbox records.

For example:

```text
Orders
└── Infrastructure
    └── Persistence
        └── Inbox

Inventory
└── Infrastructure
    └── Persistence
        └── Inbox
```

A context may consume events from several producers. All those messages are handled through its own Inbox.

The shared integration infrastructure may provide common components for:

- Message identity extraction.
- Inbox record creation and lookup.
- Duplicate detection.
- Consumer transaction coordination.
- Event metadata validation.
- Logging and error reporting.

It must not own or interpret the consumer's business model.

## 5. Message Identity

Every integration event must carry a stable UUID `eventId`.

The consumer must use this identifier as the primary key for duplicate detection within its Inbox.

The following identifiers must not be confused:

- `eventId`: Identifies one integration event.
- `aggregateId`: Identifies the aggregate that produced the event.
- `aggregateVersion`: Identifies the aggregate version associated with the event.

The same event delivered multiple times must retain the same `eventId`.

A new event produced by a later business operation must have a different event identity, even when it concerns the same aggregate.

The Inbox must not rely on RabbitMQ delivery tags, queue offsets, or other transport-specific identifiers as the business-level event identity.

## 6. Inbox Persistence Model

The initial Inbox record should contain enough information to identify and investigate processed messages.

The proposed fields are:

| Field | Purpose |
|---|---|
| `id` | Internal Inbox record identifier |
| `event_id` | Stable integration-event UUID |
| `event_name` | Integration event name |
| `event_version` | Integration event contract version |
| `aggregate_type` | Aggregate type |
| `aggregate_id` | Aggregate UUID |
| `aggregate_version` | Aggregate version, when supplied |
| `processed_at` | Successful processing timestamp |
| `consumer` | Consumer identity or handler identifier |
| `created_at` | Inbox record creation timestamp |

The schema must enforce uniqueness for the combination that identifies a message within the consumer's processing boundary.

If each bounded context has its own Inbox table, uniqueness on `event_id` within that table is normally sufficient for the context-level boundary.

If multiple independent handlers within one context require separate processing guarantees, their identity must be included in the uniqueness strategy.

This decision must be made before implementation.

The Inbox should store only the metadata required for idempotency, ordering, and operational diagnosis. Full payload retention is not required by default.

## 7. Atomic Consumer Processing

The Inbox record and the corresponding consumer-side state change must be committed in the same database transaction.

Conceptually:

```text
Receive message
    |
Begin transaction
    |
    +-- Check whether eventId has already been processed
    |
    +-- If already processed:
    |       Do not apply the business effect again
    |
    +-- Otherwise:
    |       Validate event metadata
    |       Apply consumer-side changes
    |       Record eventId in Inbox
    |
Commit transaction
    |
Acknowledge RabbitMQ message
```

The transaction must guarantee:

- If processing fails, the Inbox record is not committed as successfully processed.
- If the transaction rolls back, the consumer-side changes are also rolled back.
- If the transaction commits, a redelivery of the same event does not repeat the business effect.

The RabbitMQ message must be acknowledged only after successful processing or after a duplicate has been safely identified as already processed.

The acknowledgement must not occur before the database transaction commits.

## 8. Duplicate Delivery

Duplicate delivery is an expected behaviour of the messaging infrastructure.

When a consumer receives an event whose `eventId` already exists in its Inbox:

- The consumer must not repeat the business operation.
- The message must be treated as successfully handled.
- The consumer may acknowledge the RabbitMQ delivery.
- The duplicate should be observable through metrics or structured logs where useful.

Duplicate detection must be safe under concurrent message delivery.

A check-then-insert implementation without database uniqueness protection is insufficient.

The database constraint and transaction strategy must protect against concurrent consumers processing the same event simultaneously.

## 9. Failure Handling

If consumer processing fails:

- The database transaction must roll back.
- The event must not be recorded as successfully processed.
- The RabbitMQ message must follow the configured retry or rejection policy.
- The original `eventId` must remain unchanged.
- Reprocessing must be safe.

The implementation must distinguish between:

- Temporary infrastructure failures.
- Invalid event envelopes.
- Unsupported event versions.
- Business validation failures.
- Duplicate messages.
- Events that cannot be processed because a required predecessor has not arrived.

The retry and dead-letter strategy must be explicit and consistent with the RabbitMQ configuration.

An invalid event must not be silently acknowledged and discarded.

The policy for permanently invalid messages must preserve enough information for investigation.

## 10. Event Ordering and Aggregate Versions

RabbitMQ does not provide a general guarantee that every event from an aggregate will be processed in the order required by the consumer.

The integration-event contract therefore includes an optional `aggregateVersion`.

When a producer guarantees sequential aggregate versions, consumers can use that value to detect stale, duplicated, or missing events.

The consumer must distinguish between the following cases:

### 10.1 Expected next version

The incoming event has the next expected version.

The consumer may process it and update its projection or local state.

### 10.2 Stale version

The incoming event has a version that has already been superseded by a later processed version.

The consumer must not overwrite newer state with stale information.

The handling policy must distinguish an already processed event from a different event carrying an older aggregate version.

### 10.3 Missing predecessor

The incoming event has a version greater than the next expected version.

This indicates a possible gap.

The consumer must not silently apply the event if doing so would make its resulting state incorrect.

The implementation must define whether the event is:

- Retried for a limited period.
- Deferred for later processing.
- Stored for ordered reprocessing.
- Rejected for operational intervention.

The chosen strategy must avoid infinite retry loops.

### 10.4 No aggregate version

Some producers may not provide aggregate versions initially.

In that case, the consumer must not invent a version or claim ordering guarantees that do not exist.

The consumer must use an explicitly documented policy appropriate to the event's semantics.

Aggregate-version checks are mandatory only for event streams whose producer guarantees sequential versions.

## 11. Event Contract Validation

Before applying consumer-side effects, the consumer must validate the integration-event envelope.

Validation must cover:

- Presence and format of `eventId`.
- Presence of `eventName`.
- Supported `eventVersion`.
- Valid aggregate identity where required.
- Valid aggregate version when supplied.
- Required event-specific payload fields.

Unsupported versions must follow an explicit compatibility policy.

Consumers must not silently reinterpret an unknown event version as a known version.

The consumer must depend on the published integration-event contract, not on the producer's internal domain-event classes.

## 12. Transaction and Infrastructure Boundaries

The Inbox mechanism must respect the project's architectural boundaries.

- Domain code must not depend on RabbitMQ or database implementations.
- Application code coordinates the processing use case and required ports.
- Infrastructure implements Inbox persistence and transport-specific behaviour.
- Each context owns its consumer-side state and transaction.
- No transaction may span two bounded-context databases.

The Inbox and the corresponding projection or business state must be stored in the same transactional database boundary.

If a consumer needs to trigger another external side effect, that side effect must use an appropriate reliable mechanism rather than being performed as an unprotected operation inside the Inbox transaction.

## 13. Retention and Cleanup

Inbox records are required for duplicate detection.

Deleting them too early can allow an old message to be processed again.

The implementation must define a retention policy based on:

- RabbitMQ redelivery and retry windows.
- Operational replay procedures.
- Expected message retention.
- The consequences of processing an old duplicate.
- Storage requirements.

The initial implementation should favour correctness over aggressive cleanup.

Any cleanup process must be explicit, observable, and safe.

## 14. Observability

The consumer infrastructure must provide enough information to diagnose processing problems.

At minimum, it must be possible to identify:

- Successfully processed events.
- Duplicate deliveries.
- Failed processing attempts.
- Unsupported event versions.
- Stale aggregate versions.
- Missing aggregate predecessors.
- Messages awaiting retry.
- Messages routed to dead-letter queues.
- Consumer processing latency.

Logs should include:

- `eventId`.
- `eventName`.
- `aggregateId`.
- `aggregateVersion`, when supplied.
- Consumer identity.
- Correlation information, when available.

Sensitive event payload values must not be logged.

## 15. Testing Strategy

Tests must follow the established project test structure.

### Unit tests

Test:

- Event envelope validation.
- Duplicate-detection decisions.
- Aggregate-version validation.
- Stale-event handling.
- Missing-predecessor handling.
- Unsupported event versions.
- Consumer processing decisions.

### Integration tests

Test:

- Successful processing records the event in the Inbox.
- A failed transaction leaves no successful Inbox record.
- Consumer-side changes roll back when processing fails.
- A duplicate event does not repeat the business effect.
- Concurrent deliveries cannot process the same event twice.
- RabbitMQ acknowledgement occurs only after successful transaction completion.
- A failed message follows the configured retry policy.
- Dead-letter handling preserves diagnostic information.
- Stale aggregate versions do not overwrite newer state.
- Missing predecessor behaviour follows the documented policy.
- Inbox records and consumer-side state are committed atomically.

Tests must use the actual persistence infrastructure for transaction and uniqueness guarantees. Mock-only tests are insufficient to establish these properties.

## 16. Acceptance Criteria

- [ ] Each consuming bounded context owns its Inbox.
- [ ] Integration events are identified by a stable UUID `eventId`.
- [ ] Duplicate detection uses event identity rather than transport-specific identifiers.
- [ ] Inbox persistence and consumer-side changes are committed atomically.
- [ ] Failed processing does not create a successful Inbox record.
- [ ] Duplicate deliveries do not repeat business effects.
- [ ] Concurrent duplicate deliveries are handled safely.
- [ ] RabbitMQ acknowledgement occurs only after successful processing or safe duplicate detection.
- [ ] Retry and dead-letter behaviour is explicit.
- [ ] Event envelope and version validation are implemented.
- [ ] Aggregate-version handling prevents stale events from overwriting newer state where version guarantees exist.
- [ ] Missing predecessor behaviour is explicitly defined.
- [ ] Events without aggregate versions do not receive invented ordering guarantees.
- [ ] Inbox retention and cleanup rules are documented.
- [ ] Unit and integration tests cover the required behaviour.
- [ ] No cross-context database access or distributed transaction is introduced.

## 17. Dependencies

This task depends on:

- Task 02 — Shared Domain Event Compatibility and Test Structure.
- Task 03 — Transactional Outbox and Integration Infrastructure.
- ADR 004 — Integration Events and Cross-Context Communication.

Projection-specific processing behaviour must also follow ADR 005 — Cross-Context Projections.

## 18. Follow-up Work

After this task, bounded contexts can implement their own event consumers and projections using the shared Inbox mechanism.

Subsequent work should define:

- Inventory projections of relevant Catalog and Orders events.
- Orders projections of relevant Inventory events.
- Projection query ports and API read models.
- Projection initialization and reconciliation.
- Operational procedures for delayed or failed event processing.

These remain context-specific responsibilities and must not be implemented as one generic projection engine.
