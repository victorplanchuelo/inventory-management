# Architectural Reference

## 1. Purpose

This document defines the architectural principles and implementation guidelines that must be followed when developing new bounded contexts and features in StockFlow.

It captures the architectural lessons learned from reviewing the existing `User` bounded context.

The `User` context is considered a historical reference for understanding the current codebase, but it must not be treated as an unquestionable template for future development.

New bounded contexts, including `Orders`, `Inventory` and, when introduced, `Catalog`, must follow the principles defined in this document and the architectural decisions recorded in the project's ADRs.

## 2. Lessons Learned from the User Context

The existing `User` context provides useful examples of how the project is organized.

### 2.1. Practices to Preserve

The following practices are considered valuable and should be maintained where appropriate:

- Separation of Domain, Application and Infrastructure responsibilities.
- Domain code does not depend on Eloquent.
- Contracts are defined in the inner layer that conceptually owns them and implemented by Infrastructure.
- Application handlers orchestrate commands, queries and use cases.
- Domain events represent relevant business occurrences.
- Tests are organized according to their responsibility.
- Infrastructure implementations are kept separate from domain abstractions.

These practices provide a useful starting point for future bounded contexts.

### 2.2. Practices Not to Reproduce

The following issues were identified during the review and must not be reproduced in new contexts.

#### Anemic Domain Models

The `User` aggregate contains limited business behavior and relies heavily on external orchestration.

New aggregates should encapsulate their own business rules and invariants.

Aggregate Roots must control changes to their internal entities and ensure that domain invariants remain valid.

Business behavior should belong to the Domain rather than being distributed across Application handlers or Infrastructure services.

#### Application Layer Coupled to Laravel

The reviewed `User` context contains Application classes coupled to Laravel components, including:

- `FormRequest`.
- Laravel facades such as `Mail`.

New Application code must not depend directly on HTTP-specific Laravel components, Eloquent or Laravel facades.

The Application layer should define the application-specific ports it needs. Domain-owned abstractions, such as aggregate repository contracts, should remain in the Domain layer.

Infrastructure adapters must provide the concrete implementations.

HTTP validation and request handling belong to the API entry layer.

#### Sensitive Information in Domain Events and Responses

The reviewed implementation includes examples where password-related information is present in event or response structures.

New domain events, application outputs and API responses must never expose passwords, credentials, authentication secrets or other sensitive information.

Events must contain only the information required to represent and communicate the business occurrence.

#### Inconsistent Primitive Mapping

The reviewed `User` implementation demonstrates the use of:

- `fromPrimitives()`
- `toPrimitives()`

This pattern is useful and should be preserved, but its contract must be consistent.

The following rules apply:

- `fromPrimitives()` reconstructs domain objects and Value Objects from primitive values.
- `toPrimitives()` returns only primitive values and arrays containing primitive values.
- Both methods must be symmetrical: an object serialized through `toPrimitives()` must be reconstructible through `fromPrimitives()`, preserving its relevant domain state.
- Tests must verify both directions.

This representation is intended for mapping and reconstruction. It is not an API response, a domain event or a database persistence model.

Infrastructure mappers are responsible for adapting this representation to the persistence schema.

The Domain must not become coupled to database column names or Eloquent models.

#### Shared Contracts Must Be Reviewed Before Reuse

The existing Shared implementation contains contracts and assumptions that may not be appropriate for every new bounded context.

For example, the reviewed domain event contract assumed an integer aggregate identifier, while the existing `domain_events` migration uses UUIDs.

New contexts must not automatically adopt existing Shared contracts without checking whether their assumptions are compatible with the intended domain model.

Domain identifiers must not be coupled unnecessarily to database storage types.

Any required changes to shared contracts must be evaluated explicitly before implementation.

#### Direct Event Publishing Instead of Transactional Outbox

The reviewed RabbitMQ event bus publishes events directly and includes a database fallback.

This approach does not provide the transactional guarantees required by StockFlow.

New business operations that require reliable event publication must follow the transactional Outbox approach defined in ADR 003.

The business state change and the corresponding Outbox record must be persisted within the same database transaction.

Event publication must take place asynchronously after the transaction has committed.

#### Repository Responsibilities

The reviewed `UserRepository` combines write and read responsibilities.

New contexts should define repository and query abstractions according to their actual responsibilities.

The location of a contract must reflect its conceptual ownership:

- A repository representing the collection and lifecycle of Domain aggregates belongs in Domain.
- A port required specifically by an Application use case belongs in Application when it does not represent a Domain-owned abstraction.
- Infrastructure implements these contracts.

Read-specific ports or projections may be introduced when they provide a clear benefit.

Avoid creating generic repository abstractions that combine unrelated responsibilities without a concrete need.

#### Test Isolation

Some existing Application tests rely on Laravel and `RefreshDatabase`.

New contexts should distinguish between test types and avoid introducing unnecessary framework dependencies into unit tests.

The expected testing responsibilities are defined in section 10.

## 3. Architectural Layers and Dependency Rules

New bounded contexts must maintain clear separation of responsibilities and follow an inward dependency direction.

The Domain must not depend on Application or Infrastructure.

Application may depend on Domain abstractions and coordinate their behavior.

Infrastructure implements the contracts defined by inner layers and provides technical adapters.

The API entry layer is an inbound adapter that invokes Application use cases.

### 3.1. Domain

The Domain layer contains the business model and business rules.

It is responsible for:

- Aggregate Roots.
- Entities.
- Value Objects.
- Domain events.
- Domain exceptions.
- Business invariants.
- Business behavior.
- Repository contracts representing Domain aggregate collections and lifecycle.

Rules:

- Must not depend on Laravel.
- Must not depend on Eloquent.
- Must not depend on HTTP.
- Must not depend on Symfony Messenger or RabbitMQ.
- Must not contain infrastructure implementation details.
- Must not depend on database schemas or storage-specific identifiers.
- Must not depend on Application services or use cases.

Aggregate Roots must protect their invariants and control changes to their internal entities.

Domain events must represent facts that have actually occurred in the domain.

Domain repository contracts must express Domain concepts and must not expose persistence-specific details such as Eloquent models, query builders, SQL or database transactions.

A Domain repository contract may expose operations required to retrieve and persist its aggregate roots. Its exact methods must be driven by the aggregate's lifecycle and business requirements.

Domain events must not be treated automatically as external integration contracts.

### 3.2. Application

The Application layer coordinates the execution of use cases.

It is responsible for:

- Application use cases.
- Commands and command handlers where useful.
- Queries and query handlers where useful.
- Input and output ports.
- Application-specific service and infrastructure ports.
- Orchestration of domain behavior.
- Coordination of transaction boundaries through appropriate abstractions.
- Defining the application-level requirements for reliable integration message recording when required by a use case.

Repository contracts do not automatically belong to Application.

When a repository represents the collection and lifecycle of a Domain aggregate, its contract belongs to Domain. Application use cases consume that contract.

Application-specific ports belong to Application when their purpose is to satisfy a use-case requirement rather than represent a Domain abstraction.

Rules:

- Must not depend on Eloquent.
- Must not depend on HTTP request or response classes.
- Must not use Laravel facades directly.
- Must not contain infrastructure implementations.
- Must not duplicate business invariants already owned by the Domain.
- Must not depend directly on RabbitMQ or Symfony Messenger.
- May depend on Domain contracts and abstractions.
- Must not introduce dependencies from Domain back into Application.

CQRS should be applied pragmatically where it improves clarity or separation of responsibilities.

### 3.3. Infrastructure

The Infrastructure layer implements the technical details required by the Application and Domain.

It is responsible for:

- Eloquent models.
- Domain repository implementations.
- Application port implementations.
- Persistence mappers.
- Database access.
- Unit of Work or transaction adapters where required.
- Outbox persistence and publishing.
- Inbox or processed-message persistence.
- Integration event serialization and deserialization.
- RabbitMQ and Symfony Messenger integration.
- Message consumers and subscribers.
- Projection persistence and mapping.
- External service adapters.

Infrastructure implementations must respect the contracts defined by inner layers.

Persistence concerns must not leak into the Domain or Application.

### 3.4. API / HTTP Entry Layer

The API / HTTP entry layer is the inbound adapter responsible for exposing Application use cases through HTTP.

In StockFlow, this layer is physically located inside the `Infrastructure` directory of each bounded context.

A typical organization is:

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

It is responsible for:

- Controllers.
- HTTP request validation.
- HTTP response formatting.
- Mapping HTTP input to Application input.
- Mapping Application output to HTTP responses.
- HTTP-specific exception handling.

HTTP-specific Laravel components, including `Request`, `JsonResponse` and `FormRequest`, belong in this layer.

Rules:

- Controllers must delegate business operations to Application use cases.
- Controllers must not contain business rules or domain invariants.
- Controllers must not access Eloquent models or repositories directly.
- HTTP-specific dependencies must not leak into Application or Domain.
- The API entry layer must depend on Application contracts rather than bypassing the Application layer.

Although physically located inside Infrastructure, this layer is conceptually an inbound adapter in the Hexagonal Architecture.

## 4. Domain Events and Integration Events

Domain events and integration events have different responsibilities.

### 4.1. Domain Events

Domain events:

- Represent facts that have occurred inside the owning domain.
- Are produced by domain behavior.
- Must not depend on RabbitMQ, Messenger or database models.
- Must not contain sensitive information.
- Must not be designed around transport serialization requirements.

A domain event is internal to its bounded context unless the context explicitly decides to expose the corresponding fact externally.

### 4.2. Integration Events

Integration events are external contracts used to communicate business facts between bounded contexts.

Rules:

- The producing context owns the contract and its meaning.
- Consumers must not depend on the producer's internal domain classes.
- Events must contain only the information required by consumers.
- Events must not expose secrets or sensitive information.
- Contracts must have explicit names and versions.
- Incompatible changes require a new contract version or an explicitly documented compatibility strategy.
- Transport-specific details must remain outside the Domain.

A context may translate one or more domain events into an integration event. It may also create an integration event from an application-level business outcome when appropriate.

Not every domain event must be published externally.

### 4.3. Integration Event Envelope

The integration message envelope should provide the following metadata:

- `eventId`: globally unique message identifier.
- `eventName`: stable event name.
- `eventVersion`: contract version.
- `aggregateType`: type of the originating aggregate.
- `aggregateId`: identifier of the originating aggregate, independent of database-specific types.
- `aggregateVersion`: sequential aggregate version when available and required.
- `occurredAt`: timestamp of the business occurrence.
- `payload`: event-specific data.

The exact serialization format and persistence representation belong to Infrastructure and must be defined by the relevant implementation task.

`aggregateVersion` may be used to detect stale or out-of-order messages. Consumers must only rely on it when the producer guarantees its generation and monotonicity.

### 4.4. Producer Responsibilities

Each bounded context that publishes integration events is responsible for:

- Defining and maintaining its public event contracts.
- Translating internal business facts into external contracts.
- Recording integration messages in its own Outbox.
- Persisting business changes and corresponding Outbox messages atomically.
- Publishing messages asynchronously after commit.
- Maintaining stable message identifiers across retries.
- Providing observability for publication failures and pending messages.

A producer must never update another context's projection or database tables directly.

### 4.5. Consumer Responsibilities

Each consuming context is responsible for:

- Subscribing only to the integration events it needs.
- Validating the event name and supported version.
- Translating external contracts into its own application or projection model.
- Handling duplicate delivery safely.
- Handling or isolating malformed and unsupported messages.
- Updating its local state and processed-message record atomically when both are required.

Consumers must not modify the producer's aggregates or persistence.

### 4.6. Integration Event Naming

Event names must describe a business fact that has already occurred.

Candidate event names include:

- Catalog: `ProductCreated`, `ProductUpdated`, `ProductDiscontinued`.
- Inventory: `StockAdded`, `StockReserved`, `StockReleased`.
- Orders: `OrderConfirmed`, `OrderCancelled`.

These are candidate contracts. Their final payloads, versions and publication requirements must be defined in the relevant task specifications before implementation.

## 5. Transactional Outbox

New business operations that require reliable integration event publication must use the transactional Outbox approach defined in ADR 003.

Rules:

- The business state change and its Outbox records must be persisted in the same database transaction.
- The Outbox must store the integration contract, not an arbitrary reference to an in-memory domain object.
- Publication to RabbitMQ must happen asynchronously after commit.
- A publisher failure must not lose the pending message.
- A message must retain the same `eventId` across publication retries.
- Duplicate publication must be expected and handled by consumers.
- The publisher must not own or modify domain business rules.

The publisher belongs to Infrastructure and must operate independently from the request lifecycle.

The Outbox is not an Event Store. It is a reliable record of messages waiting to be published and their publication state.

Retention, cleanup and archival policies must not be confused with event sourcing or assumed to provide a permanent event history.

## 6. Inbox and Idempotent Consumption

Consumers must be designed for at-least-once delivery.

An Inbox or equivalent processed-message mechanism must prevent duplicate delivery from repeating local effects.

Rules:

- Every integration message must have a stable unique `eventId`.
- The consumer must identify whether that message has already been processed.
- The processed-message record and the local projection or business update must be committed in the same transaction.
- A failed transaction must leave neither a processed-message record nor partial projection changes.
- A message redelivered after a successful commit must not repeat its effects.
- Different consumers must maintain independent processing records.

The Inbox belongs to the consuming context. It must not become a central registry that knows every context's business model.

### 6.1. Out-of-Order Messages

Where aggregate versions are available, a consumer may use them to detect stale or out-of-order messages.

The policy must be explicit for each consumer or projection.

Possible behaviors include:

- Ignore an event whose aggregate version is older than the version already applied.
- Defer processing when a required previous version is missing.
- Reconcile the local projection with the owning context when a gap cannot be resolved through normal delivery.

Consumers must not silently assume that RabbitMQ delivery order is sufficient to guarantee projection consistency.

## 7. Cross-Context Projections and Read Models

A projection is a read-optimized representation of information maintained by a consuming bounded context.

Projections allow a context to answer its own queries without directly reading another context's tables or loading another context's aggregates.

### 7.1. Projection Ownership

Each projection belongs to the bounded context that consumes and uses it.

For example:

- Orders may maintain a product projection containing product information required to display order lines.
- Orders may maintain an inventory projection containing available and reserved quantities for read and display purposes.
- Other contexts may maintain their own projections according to their query requirements.

The source context remains the owner of the original information.

A projection is not a shared aggregate, shared repository or replacement for the source context.

### 7.2. Projection Updates

Projections must be updated through explicit integration event consumers.

The consuming context is responsible for:

- Defining the projection schema.
- Selecting the integration events it needs.
- Mapping event payloads into its local read model.
- Handling duplicate messages through its Inbox.
- Applying an explicit policy for out-of-order messages.
- Maintaining the projection and processed-message record atomically.
- Defining how the projection can be initialized or reconciled.

A context must not query another context's internal tables to maintain its projection.

### 7.3. Projection-Based Queries

A context may use its own projections as the source for API queries when they provide the required read model.

Rules:

- Query handlers must access projections through context-owned query ports or equivalent abstractions.
- API controllers must not access projection tables directly.
- A context must not bypass its boundary by reading another context's repositories, aggregates or tables.
- The API contract must account for eventual consistency where applicable.

Projection-backed reads are eventually consistent. They may temporarily represent an earlier state than the source context.

### 7.4. Projections Are Not Authoritative for Writes

A projection must not be used to make a business decision that requires the authoritative state of another context.

In particular, an Orders inventory projection is advisory.

Order confirmation must synchronously validate and reserve stock against Inventory's authoritative state, within the same MySQL transaction and locking strategy defined in ADR 002.

### 7.5. Projection Rebuilding and Reconciliation

The Outbox is a publication mechanism, not an Event Store.

The system must not assume that all historical Outbox records will remain available indefinitely or can always be replayed to rebuild every projection.

Each projection's initialization, rebuilding and reconciliation strategy must be defined according to its requirements.

Possible approaches include:

- Controlled synchronization with the owning context.
- A dedicated snapshot or export mechanism.
- A separately retained event archive, if historical replay is an explicit requirement.

No general-purpose projection engine or Event Sourcing architecture is required.

## 8. Primitive Mapping and Reconstruction

Domain objects may expose primitive representations through:

- `fromPrimitives()`
- `toPrimitives()`

These methods must follow a consistent contract.

### `fromPrimitives()`

Must:

- Accept primitive values or arrays of primitive values.
- Reconstruct the corresponding domain objects.
- Reconstruct Value Objects where required.
- Validate the resulting domain state through the normal domain construction rules.

### `toPrimitives()`

Must:

- Return primitive values only.
- Return arrays containing primitive values only.
- Avoid returning Value Objects, Entities or other domain objects.
- Provide a representation that can be consumed by the corresponding `fromPrimitives()` method.

### Required Tests

Tests must verify:

- Reconstruction from valid primitive data.
- Serialization into primitive data.
- Round-trip consistency.
- Preservation of relevant domain state.

This representation must remain independent of API contracts, integration event contracts and persistence schemas.

Infrastructure mappers are responsible for adapting it to database models and columns.

## 9. Identifiers and Value Objects

Domain identifiers must be represented through appropriate Value Objects.

Rules:

- Domain identifiers must encapsulate their own validation and behavior.
- Domain identifiers must not be designed around a specific database column type.
- The Domain must not assume that every identifier is an integer.
- UUIDs or other identifier formats may be used where appropriate.
- Persistence adapters are responsible for translating between domain identifiers and storage representations.
- Integration event envelopes must represent aggregate identifiers in a transport-independent form.

Shared identifier and event contracts must be reviewed before reuse.

Changes to existing Shared contracts must consider compatibility with the historical `User` context.

## 10. Testing Strategy

Tests must be organized according to architectural responsibility.

### 10.1. Domain Unit Tests

Domain tests must:

- Use PHPUnit without requiring Laravel.
- Test Value Objects.
- Test Entities and Aggregate Roots.
- Verify business invariants.
- Verify valid and invalid state transitions.
- Verify domain events.
- Verify primitive mapping and reconstruction.

Domain tests must not require a database or external infrastructure.

### 10.2. Application Unit Tests

Application tests must:

- Test use case orchestration.
- Use test doubles for ports and dependencies.
- Verify interactions with Domain repository contracts and Application-specific ports.
- Verify application-level error handling.
- Verify transaction coordination through the relevant abstraction.
- Avoid requiring Laravel bootstrapping when it is not necessary.

### 10.3. Infrastructure Integration Tests

Infrastructure tests must verify concrete implementations, including where appropriate:

- Eloquent repositories.
- Database mappings.
- Transaction handling.
- Outbox persistence.
- Publisher behavior and retries.
- Inbox idempotency.
- Integration event serialization and deserialization.
- Projection updates.
- Duplicate and out-of-order message handling.
- RabbitMQ publishing and consumption.

These tests may require Laravel, MySQL, RabbitMQ or other infrastructure.

### 10.4. API Feature Tests

API tests must verify:

- HTTP routes.
- Request validation.
- HTTP response structures and status codes.
- Mapping between HTTP and Application layers.
- HTTP-specific error handling.
- Projection-backed query behavior where applicable.

Tests must cover the externally observable behavior of the API.

## 11. Bounded Context Independence

Each bounded context must own its domain model and business rules.

Rules:

- Aggregates must not be shared between bounded contexts.
- A context must not modify another context's internal domain objects.
- A context must not query another context's internal persistence tables.
- Cross-context communication must use explicit integration contracts and messaging mechanisms.
- Projections must be owned by the consuming context.
- Shared code must be limited to genuinely generic concepts.
- Shared abstractions must not introduce accidental coupling between contexts.

The existence of a Shared namespace does not justify placing every reusable class or contract there.

Shared transport infrastructure may be reused when it does not introduce dependencies on other contexts' domain models or business rules.

## 12. Architecture Decision Records

Architecture decisions that affect the structure, consistency or long-term evolution of the system must be documented through ADRs.

Relevant decisions include:

- `docs/adr/001-modular-monolith.md`
- `docs/adr/002-atomic-stock-reservation.md`
- `docs/adr/003-outbox-rabbitmq.md`
- `docs/adr/004-integration-events-and-cross-context-communication.md`
- `docs/adr/005-cross-context-projections.md`

New implementations must respect accepted ADRs.

If an implementation requires changing an existing architectural decision, the change must be evaluated and documented before implementation.

## 13. Applying This Reference to New Features

Before implementing a new feature:

1. Review the feature specification in `docs/tasks/`.
2. Review the relevant architectural decisions.
3. Identify the responsibilities of each affected layer.
4. Review this document when designing Domain, Application, Infrastructure or API components.
5. Identify any existing Shared contracts that need compatibility review.
6. Resolve relevant architectural questions before implementation.
7. Create and review an implementation plan.
8. Obtain explicit approval before implementing the plan.

The feature specification defines what must be achieved.

The implementation plan defines how it will be achieved.

The implementation must respect both the approved plan and the architectural constraints documented in this reference.

## 14. Scope and Evolution

This document establishes the architectural reference for new StockFlow development.

It does not require an immediate refactoring of the existing `User` context.

Existing code may contain historical decisions that differ from these guidelines.

Such differences should be addressed only when explicitly planned and approved, rather than through unrelated refactoring.

This document may evolve as the project develops, provided changes are reviewed and remain consistent with the accepted architectural decisions.
