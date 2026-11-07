# ADR 004 — Integration Events and Cross-Context Communication

- **Status:** Proposed
- **Date:** 2026-11-07

## Context

StockFlow is structured as a modular monolith with independent bounded contexts.

Orders, Inventory and the planned Catalog context must communicate without sharing domain models, repositories, aggregates or persistence structures.

Direct method calls between contexts or access to another context's tables would create coupling and weaken the boundaries established by the modular monolith architecture.

The Transactional Outbox defined in ADR 003 provides reliable publication of integration messages through RabbitMQ. However, the system also needs explicit rules for message contracts, event identity, versioning, consumer responsibilities and duplicate processing.

Domain events and integration events serve different purposes:

- Domain events represent facts relevant to the internal model of a bounded context.
- Integration events represent facts that a bounded context has chosen to publish to other contexts.

An internal domain event must not automatically become a public integration contract.

## Decision

Bounded contexts communicate asynchronously through versioned integration events.

Each bounded context owns:

- The integration events it publishes.
- The mapping from internal domain events or application outcomes to integration events.
- The Outbox records for its published messages.
- The consumers and Inbox records for messages it receives.
- Its own local projections and persistence.

A context must not depend on another context's domain classes, repositories, ORM models or database tables.

The existing RabbitMQ and Symfony Messenger infrastructure is used as the transport mechanism, following ADR 003.

### Integration Event Envelope

Every integration event must use a stable envelope containing:

- `eventId`: globally unique and stable message identifier.
- `eventName`: integration event name.
- `eventVersion`: version of the event contract.
- `aggregateType`: type of the originating aggregate.
- `aggregateId`: originating aggregate identifier represented as a string.
- `aggregateVersion`: sequential version of the originating aggregate, when supported.
- `occurredAt`: timestamp representing when the business fact occurred.
- `payload`: event-specific primitive data.

Optional metadata may include:

- `correlationId`: identifier connecting related operations or messages.
- `causationId`: identifier of the message or operation that caused this event.

The envelope must be independent of Laravel, Eloquent, Symfony Messenger and RabbitMQ implementation classes.

The `aggregateId` must not assume that all bounded contexts use integer identifiers.

The `aggregateVersion` must be monotonically increasing for each aggregate whose producer guarantees versioning. Producers must persist and publish the corresponding version consistently with the business change.

Consumers must not assume that every producer already supports aggregate versioning.

### Event Naming

Integration event names describe facts that have already happened and use past-tense business language.

Candidate event names include:

**Catalog**
- `ProductCreated`
- `ProductUpdated`
- `ProductDiscontinued`

**Inventory**
- `StockAdded`
- `StockReserved`
- `StockReleased`

**Orders**
- `OrderConfirmed`
- `OrderCancelled`

These names are initial candidates. The final event catalogue must be aligned with the approved use cases and domain language before implementation.

Events must communicate business facts, not transport commands or requests for another context to execute an action.

### Event Contract Ownership

The publishing bounded context owns the meaning and contract of each integration event.

Consumers depend only on the published contract, not on the producer's internal implementation.

Integration event payloads must:

- Contain primitive, serializable values.
- Be sufficient for the documented consumer responsibilities.
- Avoid exposing internal domain objects or persistence models.
- Avoid sensitive information that consumers do not need.
- Use stable field names and documented types.

A consumer must not modify the meaning of an event or interpret it using assumptions about the producer's internal implementation.

### Contract Versioning

Every event contract has an explicit `eventVersion`.

Changes must preserve compatibility with existing consumers whenever possible.

Examples of generally compatible changes include adding an optional field that existing consumers can safely ignore.

Changes that alter the meaning, type or required presence of an existing field are potentially breaking and require an explicit versioning decision.

Consumers must handle only the event versions they explicitly support.

Unsupported versions must not be silently interpreted as another version.

The implementation must define how incompatible versions are routed, retried or made observable without creating an infinite retry loop.

### Producer Responsibilities

A producer must:

1. Complete its business operation.
2. Create the corresponding integration event.
3. Persist the integration event in its Outbox within the same database transaction as the business change.
4. Preserve the event identifier and contract version across publication attempts.
5. Publish through the mechanism defined in ADR 003.

The producer must not require consumers to be available for its business transaction to commit.

The producer remains the authority for the business facts it publishes.

### Consumer Responsibilities

A consumer must:

- Subscribe only to integration events relevant to its bounded context.
- Validate the event envelope and supported contract version.
- Translate the external contract into its own application or projection model.
- Avoid importing producer domain classes.
- Process messages idempotently.
- Keep its local state consistent when processing succeeds or fails.
- Expose failures for operational investigation.

A consumer must not access the producer's database to complete message processing.

### Inbox and Idempotency

Each bounded context that consumes integration events must maintain an Inbox or equivalent processed-message mechanism.

The Inbox must identify a message using:

- The consumer identity.
- The stable `eventId`.

A uniqueness constraint must prevent the same consumer from applying the same message more than once.

Different consumers must be able to process the same event independently.

The Inbox record and the consumer's local business or projection update must be committed in the same database transaction.

If processing fails, both the local update and the Inbox record must roll back so that the message can be retried safely.

If the message has already been processed by that consumer, the consumer must acknowledge or otherwise complete duplicate handling without repeating the business effect.

The Inbox is a processing safeguard, not a replacement for business invariants.

### Ordering and Aggregate Versions

RabbitMQ delivery order must not be treated as a universal guarantee of business ordering across all consumers.

Where a producer supplies `aggregateVersion`, consumers may use it to detect stale or out-of-order messages.

The consumer must define an explicit policy for:

- Duplicate aggregate versions.
- Older versions received after newer versions.
- Version gaps.
- Events for which aggregate versioning is not available.

A consumer may ignore an older event when its local state already reflects a newer version, provided this is valid for that event and projection.

A version gap must not automatically be considered a missing message. A consumer subscribed to only some event types may legitimately observe gaps in the producer's aggregate version sequence.

Where processing depends on a complete sequence, the subscription and ordering requirements must be explicitly documented.

The implementation must not assume that RabbitMQ alone guarantees the required business ordering.

## Cross-Context Boundaries

The following rules apply to all bounded contexts:

- A context owns its aggregates and domain rules.
- A context owns its database tables and persistence models.
- A context publishes facts through integration events.
- A context consumes external contracts through its own adapters.
- A context must not call another context's repositories.
- A context must not read or write another context's tables directly.
- Shared infrastructure may provide transport and serialization capabilities, but must not become a central owner of all domain models or event semantics.

Orders remains responsible for the Order lifecycle.

Inventory remains responsible for physical stock and reservation rules.

Catalog, if included in the approved scope, remains responsible for product information.

Integration events communicate facts between these contexts without transferring ownership of their models.

## Candidate Integration Flows

The following flows illustrate the intended communication model. They are architectural examples, not a final event catalogue.

### Catalog to Other Contexts

When Catalog is implemented, it may publish product lifecycle events.

Orders and Inventory may consume those events to maintain their own local product information or projections.

A consumer must not treat a local product projection as ownership of the Catalog product.

### Inventory to Other Contexts

Inventory may publish stock-related facts such as stock additions, reservations and releases.

Other contexts may consume those facts to update local projections or coordinate subsequent application behavior.

A stock projection in another context is not authoritative for reserving physical stock.

### Orders to Other Contexts

Orders may publish order lifecycle facts such as order confirmation and cancellation.

Inventory may consume relevant order facts only when the approved workflow requires them.

The final responsibility for creating, releasing or validating stock reservations remains in Inventory.

## Relationship with ADR 003 and ADR 005

ADR 003 defines the transactional Outbox and reliable publication mechanism.

This ADR defines integration event contracts and cross-context communication rules, including consumer idempotency.

ADR 005 defines cross-context projection ownership, update semantics, query responsibilities and reconciliation.

This ADR does not introduce Event Sourcing or require integration events to be retained as a permanent event history.

## Consequences

**Positive:**

- Bounded contexts remain independent.
- Producers and consumers depend on explicit contracts rather than internal classes.
- Consumers can evolve independently from producers.
- Stable event identity enables duplicate detection.
- Contract versioning makes changes explicit.
- Inbox processing protects local state from repeated message delivery.
- The architecture supports additional consumers without direct producer-to-consumer dependencies.

**Negative:**

- Integration contracts require documentation and compatibility discipline.
- Consumers need Inbox persistence and transactional processing.
- Eventual consistency introduces temporary differences between contexts.
- Out-of-order messages and unsupported versions require explicit handling.
- Debugging asynchronous workflows requires correlation identifiers and operational visibility.

## Rejected Alternatives

- **Direct cross-context repository calls:** They couple bounded contexts and violate persistence ownership.
- **Shared domain models between contexts:** They create compile-time and conceptual coupling.
- **Publishing domain objects directly:** It exposes internal implementation details as external contracts.
- **A central event hub owning every event model:** It creates a new central dependency and weakens bounded-context ownership.
- **Exactly-once message delivery:** It is not assumed to be achievable end to end. Stable identifiers, at-least-once delivery and idempotent consumers are adopted instead.
- **Event Sourcing:** It is outside the current project scope and is not required for integration event publication.

## Implementation Constraints

- Keep integration event contracts independent of framework and transport classes.
- Use stable UUID message identifiers.
- Represent aggregate identifiers as strings in integration contracts.
- Use explicit event contract versions.
- Persist producer Outbox records in the same transaction as business changes.
- Use a consumer-specific Inbox or equivalent mechanism.
- Commit Inbox records and local processing effects atomically.
- Keep consumers independent of producer repositories and domain models.
- Define ordering and version-gap behavior for each relevant consumer.
- Test duplicate delivery, failed processing, retries, unsupported versions and out-of-order delivery.
- Do not introduce Event Sourcing, microservices or a generic event platform as part of this decision.

Any change to these architectural constraints must be evaluated and documented through an ADR update.
