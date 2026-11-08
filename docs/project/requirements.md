# Requirements — StockFlow

## Functional Requirements

### FR-01 — Create Order

The API must allow creating an order with at least one line item.

Each line item identifies a product through its `ProductId` and specifies a positive integer quantity.

Product descriptive information must not be owned by the Orders context.

Orders must not depend on Catalog being available or implemented. Until Catalog is introduced, `ProductId` remains an external product reference.

### FR-02 — Atomic Order Confirmation

An order can only be confirmed after Inventory has successfully reserved all stock required by its line items.

Inventory must process all stock reservations requested for an order atomically within its own transaction. Either all requested reservations are committed or none are.

Stock availability must be validated against Inventory's authoritative state through the Inventory application boundary, not against an eventually consistent projection.

Order confirmation must never result in a confirmed order without the corresponding successful stock reservation.

Orders and Inventory retain ownership of their respective persistence structures and transactions. The implementation must not assume a shared transaction across bounded contexts.

The coordination protocol must explicitly address failures between the Inventory reservation and the Orders persistence operations, including retries, idempotency, compensation and recovery. This protocol is tracked by OD-001 and must be decided before implementing order confirmation.

RabbitMQ must not participate in the synchronous decision of whether stock is available. No network I/O to RabbitMQ may occur while an Inventory transaction holds database locks.

Whether order creation and confirmation are performed in one use case or separate use cases remains a lifecycle decision to be resolved in the Orders task specification.

### FR-03 — Retrieve Order

The API must allow retrieving an order's identifier, status and line items.

Where product descriptive information is required, Orders may use a local product projection rather than querying Catalog's internal persistence.

Projection-backed information must be understood as eventually consistent.

### FR-04 — Cancel Order

The API must allow requesting the cancellation of an order in a cancellable state.

If active reservations exist, they must be released consistently.

Repeated cancellation requests must not release the same reservation more than once.

The cancellation operation must respect the order lifecycle and reservation state rules.

### FR-05 — Register Inventory

The API must allow registering the initial inventory quantity for a product.

Exactly one `StockItem` must exist per `ProductId`. Registering another stock item for an already registered product must be rejected.

The operation must prevent invalid inventory states.

Inventory initially maintains exactly one StockItem per ProductId. Duplicate registration must be rejected.

### FR-06 — Add Inventory

The API must allow increasing the stock of an existing product by a positive quantity.

The operation must preserve the inventory aggregate's invariants.

### FR-07 — Retrieve Inventory

The API must allow retrieving the inventory quantities for a product, including:

- `onHand`: physical stock currently held.
- `reserved`: stock allocated to active reservations.
- `available`: stock that can still be reserved, calculated as `onHand - reserved`.

The values must be consistent with Inventory's authoritative state when retrieved directly from Inventory.

The API may expose additional quantities if supported by the model, but it must not treat a projection as authoritative for stock decisions.

### FR-08 — Publish Integration Events

Integration events that must be communicated to other components must be recorded in the producer context's Outbox within the same transaction as the corresponding business change.

Each bounded context that publishes integration events must own its Outbox records and publication responsibilities.

The event contract must be independent of internal domain classes and transport-specific implementation details.

The Outbox is a reliable publication mechanism, not an Event Store.

### FR-09 — Consume Integration Events

Messages published to RabbitMQ must be processed with retries and idempotency.

Permanent failures must be isolatable through a dead-letter queue or an equivalent mechanism.

Consumers must not depend on the producer's internal domain model or persistence.

### FR-10 — Maintain Context-Owned Projections

A bounded context must be able to maintain local read projections using integration events published by other contexts.

Examples include:

- An Orders product projection containing product information required by order-related queries.
- An Orders inventory projection containing availability information required for display or preliminary queries.

Each projection must be owned and maintained by its consuming context.

A context must not update another context's projections or read another context's internal tables to maintain its own read models.

### FR-11 — Retrieve Data Through Local Projections

The API must be able to retrieve information from projections owned by its bounded context when those projections provide the required read model.

Projection-backed queries must use context-owned query interfaces.

The API must not access another bounded context's repositories, aggregates or persistence directly.

Projection-backed responses must account for eventual consistency and must not imply that the projection always reflects the latest source state.

### FR-12 — Process Messages Idempotently Through an Inbox

Consumers must maintain an Inbox or equivalent processed-message mechanism to identify messages that have already been successfully processed.

The processed-message record and the corresponding local projection or business update must be committed in the same transaction.

A duplicate delivery must not repeat the effects of an already processed message.

Different consuming contexts must maintain independent processing records.

### FR-13 — Handle Out-of-Order Messages

Consumers that rely on aggregate versions must be able to detect stale or out-of-order messages.

Each consumer or projection must define how it handles older versions, missing versions and messages that cannot be applied safely.

Consumers must not assume that message transport ordering alone guarantees correct projection state.

### FR-14 — Maintain Product References

Orders and Inventory must identify products through `ProductId`.

`ProductId` is a UUID reference and does not imply ownership of a Product aggregate by Orders or Inventory.

If Catalog is not implemented initially, `ProductId` must remain an external reference.

Inventory and Orders must not introduce duplicate Product aggregates or take ownership of product descriptive information.

### FR-15 — Support Catalog Integration (Planned)

When Catalog is introduced, it must own product information and publish the integration events required by consuming contexts.

Candidate events include:

- `ProductCreated`
- `ProductUpdated`
- `ProductDiscontinued`

The exact event contracts and consuming projections must be defined in the Catalog task specification.

Catalog is desirable but is not a prerequisite for the initial Inventory and Orders implementation.

## Non-Functional Requirements

### NFR-01 — Consistency

Each bounded context must maintain transactional consistency for its own business changes and corresponding Outbox records.

Inventory stock changes, reservations and Inventory Outbox records must be committed or rolled back together within an Inventory-owned transaction.

Orders persistence changes and Orders Outbox records must be committed or rolled back together within an Orders-owned transaction.

Inbox records and local projection updates must also maintain transactional consistency within their owning context.

Cross-context consistency must be guaranteed through an explicitly defined coordination protocol. It must not rely on an implicit shared transaction.

### NFR-02 — Concurrency

Reservations must be protected against concurrent race conditions through row locking and a deterministic lock acquisition strategy.

The implementation must prevent over-reservation, including when multiple orders compete for the last available units.

The implementation must define how deadlocks are handled and retried.

Concurrency behavior must be tested against a real MySQL instance.

### NFR-03 — Separation of Concerns

The Domain and Application layers must not depend on Laravel, Eloquent, HTTP, RabbitMQ or Symfony Messenger.

Bounded contexts must not access or modify another context's internal domain model or persistence.

Domain aggregate repository contracts belong to the Domain layer. Use-case-specific ports may belong to the Application layer. Infrastructure implements these contracts.

### NFR-04 — Testability

Domain rules must be tested with fast unit tests.

Application use cases must be tested independently of infrastructure where practical.

Adapters must be tested through integration tests.

Message processing, projection updates and transaction boundaries must have appropriate automated coverage.

Concurrency and rollback behavior must be tested against MySQL.

### NFR-05 — Idempotency

Repeating a message must not repeat non-idempotent business effects.

Message identifiers must remain stable across publication retries.

Consumers must record successful processing atomically with their local effects.

### NFR-06 — Compatibility

New code must be compatible with PHP 8.3 and the versions of Laravel and libraries actually installed.

### NFR-07 — Security

Secrets and credentials must not be included in logs, events or responses.

External inputs must be validated.

Integration event payloads and projections must contain only the information required by their consumers.

### NFR-08 — Local Operability

The development environment must document the required services, environment variables, migrations, tests and workers.

The local setup must support running the Outbox publisher and message consumers.

### NFR-09 — Eventual Consistency

Cross-context projections are eventually consistent.

The system must not assume that an integration event or projection update is immediately visible after the originating transaction commits.

Use cases requiring authoritative state must obtain it from the owning context through an appropriate application boundary.

In particular, order confirmation must use Inventory's authoritative stock state rather than a local inventory projection.

### NFR-10 — Message Reliability

The integration messaging infrastructure must support:

- Reliable recording of messages through the transactional Outbox.
- Asynchronous publication after the business transaction commits.
- Retry of transient publication and consumption failures.
- Isolation of messages that cannot be processed successfully.
- Idempotent consumption.
- Observability of pending, failed and successfully processed messages.

The architecture provides at-least-once delivery, not exactly-once delivery.

### NFR-11 — Projection Integrity

Projection updates must be isolated from the source context's persistence.

Consumers must be able to detect duplicate messages and, where aggregate versions are available, stale or out-of-order messages.

Projection initialization and reconciliation must have a documented strategy.

## Initial Business Rules

### Orders

- An order must contain at least one line item.
- Requested quantities must be positive integers.
- An order cannot be partially confirmed.
- State transitions must be explicitly modeled.
- A cancellation must not release stock twice.

### Inventory

- Exactly one `StockItem` exists per `ProductId`.
- `ProductId` is a UUID reference.
- `StockItem` owns its reservation entities.
- `onHand` and `reserved` are stored quantities.
- `available` is calculated as `onHand - reserved`.
- Inventory quantities must be non-negative.
- `reserved` must never exceed `onHand`.
- Operations that add stock or reserve stock require a positive quantity.
- More stock than is available cannot be reserved.
- An active reservation can only be released once.
- A reservation is identified independently from its owning stock item.

### Cross-Context and Messaging

- Domain identifiers must not depend on specific database types.
- An event must not carry secrets or information that the consumer does not need.
- Each bounded context owns its domain model and business rules.
- Cross-context read models belong to the consuming context.
- Projections must not be used as the authority for business write decisions.
- Business changes and their required Outbox messages must be committed atomically.
- Consumer processing records and their local effects must be committed atomically.
- Duplicate message delivery must not repeat successful processing effects.

## Pending Decisions Requiring Validation

### Order Lifecycle and Business Rules

- Whether an order is created directly in the `pending` state or confirmed within the same use case.
- Whether modifying already-created orders is allowed.
- Reservation expiration policy.
- HTTP request idempotency key policy.
- Exact error format and HTTP status codes.
- Authentication and authorization.
- Cancellation policy after events have been published.
- Whether order lines retain product descriptive snapshots.

### Inventory Operations and API

- Whether administrative stock adjustments, including reductions, are required in the initial scope.
- Whether negative inventory is ever permitted for exceptional administrative operations. The default rule is no.
- Exact API request and response contracts.
- Exact error behavior for duplicate stock registration, unknown products and insufficient availability.

### Integration Messaging

- Exact integration event names, payloads and versions.
- Which domain events must be translated into integration events.
- Aggregate version generation and guarantees.
- Retry policies and dead-letter handling details.
- Message retention and Outbox cleanup policies.
- Consumer behavior when an event version is missing or unsupported.

### Projections

- Exact fields required by each projection.
- Projection initialization and reconciliation strategy.
- Whether a controlled synchronization mechanism is required for initial data.
- How projection freshness is communicated where relevant to API consumers.

### Catalog

- Whether Catalog will be implemented in the initial development scope or deferred.
- Product attributes and lifecycle rules.
- The exact integration events required by Orders and other consumers.

These decisions must be resolved in the relevant task specifications or ADRs before implementation. They must not be silently assumed.
