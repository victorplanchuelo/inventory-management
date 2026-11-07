# Architecture — StockFlow

## 1. Architectural Style

StockFlow evolves as a **Modular Monolith** with Hexagonal Architecture separation per bounded context.

Modules share the same process and deployment, but maintain explicit boundaries and independent responsibilities.

Initial modules:

- `Orders`: order lifecycle and coordination of its lines.
- `Inventory`: quantities, availability and reservations.
- `Catalog`: product identity and descriptive information, when introduced.
- `Shared`: existing cross-cutting capabilities that must be inspected before reuse.

The architecture must evolve while respecting the existing repository structure and namespaces.

The known PSR-4 autoload configuration is `Manager\\` → `src/`. Structures or namespaces that contradict the actual project configuration must not be imposed.

## 2. Bounded Context Organization

Each bounded context must maintain its own responsibilities and domain model.

The general organization is:

```text
src/
├── Shared/
│   ├── Domain/
│   └── Application/
├── Catalog/
│   ├── Domain/
│   ├── Application/
│   └── Infrastructure/
├── Orders/
│   ├── Domain/
│   ├── Application/
│   └── Infrastructure/
└── Inventory/
    ├── Domain/
    ├── Application/
    └── Infrastructure/
```

This structure is indicative and must be adapted to the actual needs of each context. `Catalog` is a planned bounded context and is not a prerequisite for the initial Inventory and Orders implementation.

Not every feature needs to modify every layer.

Each bounded context is responsible for its own domain model. Aggregates must not be shared between contexts.

Shared capabilities must be limited to genuinely cross-cutting concepts and must not introduce accidental coupling.

### 2.1. Context Ownership

Each context owns its data and business rules:

- `Catalog` owns Product identity and product descriptive information.
- `Inventory` owns stock quantities, availability and reservations.
- `Orders` owns orders, order lines and order lifecycle.

A context may store local read models or projections containing information originating from another context. These projections are owned and maintained by the consuming context; they do not transfer ownership of the original information.

`ProductId` is a cross-context reference to a product identity. Inventory and Orders must not create duplicate Product aggregates to represent that identity.

Until Catalog is implemented, `ProductId` may be used as a reference without requiring a Product aggregate to exist in the current codebase.

## 3. Architectural Layers

### 3.1. Domain

Contains the business model and its rules:

- Aggregate Roots.
- Entities.
- Value Objects.
- Domain Events.
- Domain Exceptions.
- Business Rules and Invariants.

Responsibilities:

- Protect domain invariants.
- Encapsulate business behavior.
- Control state changes through Aggregate Roots.
- Generate events as a consequence of actual domain facts.

Constraints:

- Must not depend on Laravel.
- Must not depend on Eloquent.
- Must not depend on HTTP.
- Must not depend on Symfony Messenger or RabbitMQ.
- Must not know about database tables or persistence details.
- Must not contain infrastructure implementations.

### 3.2. Application

Contains use case coordination:

- Application Use Cases.
- Commands and Command Handlers, when they provide value.
- Queries and Query Handlers, when they provide value.
- Input and Output Ports.
- Repository and service contracts that are required.
- Transaction coordination through appropriate abstractions.

Responsibilities:

- Orchestrate domain behavior.
- Coordinate the ports required to execute use cases.
- Define persistence and external service requirements through contracts.
- Coordinate transaction boundaries through the corresponding abstractions.
- Coordinate the creation of integration messages when required by a use case, without depending on the transport implementation.

Constraints:

- Must not contain Eloquent queries.
- Must not depend directly on Eloquent models.
- Must not contain HTTP logic.
- Must not use Laravel facades directly.
- Must not implement infrastructure adapters.
- Must not duplicate invariants that belong to the Domain.

CQRS must be applied pragmatically when it helps separate responsibilities and improve clarity.

### 3.3. Infrastructure

Implements the contracts and technical adapters required to execute use cases.

Responsibilities:

- MySQL/Eloquent persistence.
- Repository implementations.
- Persistence mappers.
- Unit of Work and transaction adapters.
- Outbox persistence and publisher.
- Inbox or processed-message persistence.
- Symfony Messenger and RabbitMQ integration.
- Integration event serialization and deserialization.
- External service adapters.
- HTTP inbound adapters.
- Projection persistence and message consumers.

Infrastructure depends on the abstractions defined by inner layers and provides their concrete implementations.

### 3.4. API / HTTP Entry

The HTTP entry layer is considered an inbound adapter in Hexagonal Architecture.

In StockFlow, its physical location is inside the `Infrastructure` directory of each bounded context.

Indicative structure:

```text
src/
└── Orders/
    ├── Domain/
    ├── Application/
    └── Infrastructure/
        ├── Api/
        │   ├── Controllers/
        │   └── Requests/
        ├── Persistence/
        └── Messaging/
```

Responsibilities:

- Controllers.
- Form Requests.
- HTTP validation.
- Response serialization and formatting.
- Mapping HTTP input to Application data.
- Mapping Application results to HTTP responses.
- HTTP-specific error handling.

Rules:

- Controllers delegate business operations to Application use cases.
- Controllers must not contain business rules or domain invariants.
- Controllers must not directly access Eloquent models or repository implementations.
- HTTP and Laravel dependencies must not leak into Application or Domain.
- The HTTP entry layer must communicate with Application through its contracts.

Although physically located inside Infrastructure, Controllers and Requests are inbound adapters, not components of the domain model.

### 3.5. Controllers and Form Requests — Directory Convention

Controllers and Form Requests must be located inside `Infrastructure/Api/` within their respective bounded contexts.

The following directory convention applies to `Orders`:

```text
src/
└── Orders/
    └── Infrastructure/
        └── Api/
            ├── Controllers/
            └── Requests/
```

The same convention applies to `Inventory` and, when introduced, `Catalog`.

**Controllers**

Controllers are responsible for:

- Receiving HTTP requests.
- Invoking the appropriate Application use cases.
- Mapping Application results to HTTP responses.
- Returning appropriate HTTP status codes.

Controllers must:

- Delegate business operations to Application use cases.
- Avoid implementing business rules or domain invariants.
- Avoid directly accessing Eloquent models or repository implementations.
- Avoid containing transaction or persistence logic.

**Form Requests**

Form Requests are responsible for:

- Validating HTTP request structure and input format.
- Applying HTTP-specific validation rules.
- Providing validated input to Controllers.

Form Requests must:

- Remain within the API entry layer.
- Avoid implementing business rules or domain invariants.
- Avoid depending on Domain or Infrastructure persistence implementations.
- Not replace domain validation or business invariant enforcement.

**Architectural constraints**

- Controllers belong in `Infrastructure/Api/Controllers/`.
- Form Requests belong in `Infrastructure/Api/Requests/`.
- HTTP-specific Laravel components, including `Request`, `JsonResponse` and `FormRequest`, must not leak into Application or Domain.
- Controllers must communicate with Application through its use cases and contracts.
- The API entry layer is conceptually an inbound adapter, even though it is physically located inside Infrastructure.

## 4. Main Flow: Creating and Confirming an Order

The order creation and confirmation flow must ensure that inventory reservation and order persistence remain consistent.

General flow:

1. The HTTP adapter receives and validates the request format.
2. The Controller invokes the corresponding Application use case.
3. Application coordinates the creation of the Order aggregate and its lines.
4. A MySQL transaction is started through the corresponding transaction abstraction.
5. The required inventory rows are locked using `SELECT ... FOR UPDATE`, following a deterministic order.
6. Availability is checked against the authoritative Inventory state.
7. The entire required stock quantity is reserved.
8. The order is confirmed and the reservations are persisted.
9. The corresponding Outbox messages are recorded within the same transaction.
10. The transaction is committed.
11. An independent publisher reads pending messages from the Outbox and publishes them to RabbitMQ.
12. Consumers process messages idempotently and update their own projections when applicable.

The decision to accept or reject a stock reservation must be synchronous and consistent with the business transaction.

This decision must not be delegated to an asynchronous message or a read projection.

An Inventory projection available to Orders may be used to display or search stock information. It is an eventually consistent read model and must never be used as the authority for confirming a reservation.

The details of consistency and atomic reservation are covered by the corresponding ADRs.

## 5. Cross-Context Communication

Bounded contexts must communicate through explicit contracts rather than direct access to one another's internal models or persistence.

### 5.1. Integration Events

An integration event represents a business fact that a context makes available to other contexts.

Rules:

- The producing context owns the event contract and its meaning.
- Integration events must be independent of Laravel, Eloquent, Symfony Messenger and RabbitMQ.
- Consumers must not depend on the producer's internal domain classes.
- Events must contain only the information required by consumers.
- Events must not expose secrets or sensitive information.
- Event contracts must be versioned when their structure or semantics change incompatibly.

A domain event and an integration event are not necessarily the same object.

A domain event represents a fact inside its owning domain model. An integration event is an external contract. A context may translate one or more domain events into an integration event, or create an integration event from an application-level business outcome.

Not every domain event must be published externally.

### 5.2. Integration Event Envelope

The integration messaging contract should provide a stable envelope containing, at minimum:

- `eventId`: globally unique message identifier.
- `eventName`: stable event name.
- `eventVersion`: version of the event contract.
- `aggregateType`: type of the originating aggregate.
- `aggregateId`: identifier of the originating aggregate, represented independently of database-specific types.
- `aggregateVersion`: sequential version of the aggregate state when available and required for ordering.
- `occurredAt`: timestamp of the business occurrence.
- `payload`: event-specific data.

The exact serialization format and storage representation belong to Infrastructure and must be defined in the relevant implementation task.

`aggregateVersion` is intended to help consumers detect stale or out-of-order updates. It must only be relied upon when the producer actually guarantees its generation and monotonicity.

### 5.3. Producer Responsibilities

Each bounded context that publishes integration events is responsible for:

- Defining and maintaining its public event contracts.
- Recording messages in its own Outbox.
- Persisting the business change and its Outbox records atomically.
- Publishing messages asynchronously after commit.
- Maintaining stable event identifiers.
- Supporting retries and observability.

A producer must not directly update another context's projection or database tables.

### 5.4. Consumer Responsibilities

Each consuming context is responsible for:

- Subscribing to the integration events it needs.
- Translating external contracts into its own application or projection model.
- Handling duplicate delivery safely.
- Validating supported event versions.
- Handling or isolating unsupported and malformed messages.
- Updating its local projection in a transaction with the corresponding processed-message record.

Consumers must not modify the producer's aggregates or persistence.

### 5.5. Inbox and Idempotency

Consumers must use an Inbox or equivalent processed-message mechanism to prevent duplicate delivery from repeating effects.

The processed-message record and the projection update must be committed in the same local database transaction.

A message identifier must be stable across retries.

Where aggregate versions are available, consumers may use them to reject stale updates or detect gaps. The behavior for out-of-order messages must be explicit for each projection.

Exactly-once delivery is not guaranteed. The architecture relies on at-least-once delivery and idempotent processing.

## 6. Cross-Context Projections and Read Models

A projection is a read-optimized representation of information maintained by a consuming bounded context.

Projections allow a context to answer queries without reading another context's tables or loading another context's aggregates.

### 6.1. Ownership

Each projection belongs to the bounded context that consumes and uses it.

For example:

- Orders may maintain a product projection containing the product information required to display order lines.
- Orders may maintain an inventory projection containing available and reserved quantities for read and display purposes.
- Other contexts may maintain their own projections according to their query needs.

The source context remains the owner of the original information.

A projection is not a shared aggregate, a shared repository or a replacement for the source context.

### 6.2. Updates

Projections are updated from integration events published by their source contexts.

The consuming context is responsible for:

- Defining the projection schema.
- Consuming the relevant events.
- Mapping event data into its local read model.
- Handling duplicate and out-of-order messages.
- Maintaining projection consistency within its own database transaction.
- Defining how the projection can be initialized or reconciled.

A context must not query another context's internal tables to maintain its projection.

### 6.3. Query APIs

A context may use its own projections as the source for API queries when they provide the required read model.

The API must not bypass the context boundary by reading another context's repositories, aggregates or tables.

Projection-based reads are eventually consistent. The API contract and use case must account for the possibility that a projection has not yet processed the latest event.

### 6.4. Projections Are Not Authoritative for Writes

A projection must not be used to make a business decision that requires the authoritative state of another context.

In particular, an Orders inventory projection is advisory.

Order confirmation must synchronously validate and reserve stock against Inventory's authoritative state, within the atomic transaction and locking strategy defined in ADR 002.

### 6.5. Rebuilding and Reconciliation

The Outbox is a publication mechanism, not an Event Store.

The system must not assume that all historical Outbox records will remain available indefinitely or that they can always be replayed to rebuild every projection.

A projection's initialization, rebuilding and reconciliation strategy must be defined according to its requirements. Possible approaches include controlled synchronization with the owning context or a separately retained event archive.

No general-purpose projection engine or Event Sourcing architecture is required.

## 7. Consistency and Failure Handling

The design must account for the following scenarios:

- **Failure before commit:** the order, reservations and Outbox records are rolled back.
- **RabbitMQ unavailable after commit:** the message remains pending in the Outbox and can be retried.
- **Publisher fails after publishing but before marking the message as published:** duplicate publication may occur. Consumers must be idempotent.
- **Consumer fails before its local transaction commits:** the processed-message record and projection changes are rolled back together.
- **Consumer fails after commit but before acknowledging the message:** the message may be delivered again. The Inbox prevents repeating the effect.
- **Duplicate messages:** consumers must prevent duplicate business effects.
- **Out-of-order messages:** consumers must apply an explicit policy, using aggregate versions where available.
- **Projection lag:** read models may temporarily represent an earlier state than the source context.

Exactly-once delivery is not guaranteed.

The system is designed to support at-least-once delivery and idempotent effects.

## 8. Shared and Compatibility

The repository contains shared contracts, buses and capabilities in `src/Shared`.

Before reusing any existing component:

1. Inspect its interfaces and contracts.
2. Review its implementations and configuration.
3. Identify its actual dependencies.
4. Check how events are serialized and transported.
5. Verify its compatibility with the requirements of the new bounded context.
6. Avoid regressions in the `User` context.

Existing abstractions must not be reused automatically merely because they are located in `Shared`.

The existing `DomainEvent` contract must be reviewed before being used in new contexts, especially regarding identifier types and date representation.

Integration event identifiers must not be constrained by database-specific integer identifiers. The contract must support the domain identity strategy selected by the producing context.

If a shared contract does not meet the needs of the new context, the following options must be evaluated:

- Evolve the existing contract in a backward-compatible way.
- Introduce an independent abstraction.
- Maintain context-specific contracts.

Any cross-cutting change must be justified and covered by tests.

## 9. Events and Outbox

Domain events represent relevant business facts.

The publication of integration events must follow the transactional Outbox strategy.

When a business operation requires reliable publication:

- The business state change and the corresponding Outbox record must be persisted in the same transaction.
- Publication to RabbitMQ must take place after the commit.
- Publication failures must allow retries.
- Consumers must be idempotent.
- Consumers that maintain projections must persist their processed-message record and projection update atomically.

The details of the Outbox architecture are defined in:

- `docs/adr/003-outbox-rabbitmq.md`

Cross-context integration contracts and consumption rules are defined in:

- `docs/adr/004-integration-events-and-cross-context-communication.md`

Projection ownership and consistency rules are defined in:

- `docs/adr/005-cross-context-projections.md`

## 10. Testing and Quality

Tests must respect the responsibilities of each layer.

### Domain

- PHPUnit unit tests.
- No need to bootstrap Laravel.
- Verification of Value Objects.
- Verification of Aggregate Roots and Entities.
- Verification of invariants and state transitions.
- Verification of domain events.
- Verification of `fromPrimitives()` and `toPrimitives()`.

### Application

- Unit tests for use cases.
- Test doubles for ports and dependencies.
- Verification of orchestration and coordination.
- Verification of transaction boundaries through appropriate abstractions.
- No need to bootstrap Laravel when it is unnecessary.

### Infrastructure

- Integration tests.
- Verification of repositories and mappers.
- Verification of persistence and transactions.
- Verification of Outbox and Inbox behavior.
- Verification of projection updates.
- Verification of duplicate and out-of-order message handling.
- Verification of RabbitMQ integration where applicable.

### API

- Feature/HTTP tests.
- Verification of routes and Controllers.
- Request validation.
- Verification of responses and HTTP status codes.
- Verification of mapping between HTTP and Application.
- Verification of projection-backed query behavior where applicable.

### Quality Tools

Only tools actually configured in Composer and the project configuration will be used.

New code must comply with the established style and static analysis standards.

Baselines must not be modified, and mass corrections to legacy code must not be applied without authorization.

## 11. Architectural References

This document describes the overall architecture of StockFlow.

For detailed design and implementation rules for new bounded contexts, consult:

- `docs/architecture/architectural-reference.md`

Accepted architectural decisions are documented in:

- `docs/adr/001-modular-monolith.md`
- `docs/adr/002-atomic-stock-reservation.md`
- `docs/adr/003-outbox-rabbitmq.md`
- `docs/adr/004-integration-events-and-cross-context-communication.md`
- `docs/adr/005-cross-context-projections.md`

Implementations must comply with these decisions.

If a feature requires changing an existing architectural decision, the change must be evaluated and approved before implementation.
