# Architectural Reference

## 1. Purpose

This document defines the architectural principles and implementation guidelines that must be followed when developing new bounded contexts and features in StockFlow.

It captures the architectural lessons learned from reviewing the existing `User` bounded context.

The `User` context is considered a historical reference for understanding the current codebase, but it must not be treated as an unquestionable template for future development.

New bounded contexts, including `Orders` and `Inventory`, must follow the principles defined in this document and the architectural decisions recorded in the project's ADRs.

## 2. Lessons Learned from the User Context

The existing `User` context provides useful examples of how the project is organized.

### 2.1. Practices to Preserve

The following practices are considered valuable and should be maintained where appropriate:

- Separation of Domain, Application and Infrastructure responsibilities.
- Domain code does not depend on Eloquent.
- Repository contracts are defined in inner layers and implemented in Infrastructure.
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

The Application layer should define the contracts it needs through ports or interfaces. Infrastructure adapters must provide the concrete implementations.

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

For example, the reviewed domain event contract assumes an integer aggregate identifier, while the existing `domain_events` migration uses UUIDs.

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

New contexts should define repository and query ports according to their actual use cases.

Read-specific ports or projections may be introduced when they provide a clear benefit.

Avoid creating generic repository abstractions that combine unrelated responsibilities without a concrete need.

#### Test Isolation

Some existing Application tests rely on Laravel and `RefreshDatabase`.

New contexts should distinguish between test types and avoid introducing unnecessary framework dependencies into unit tests.

The expected testing responsibilities are defined in section 7.

## 3. Architectural Layers

New bounded contexts must maintain clear separation of responsibilities.

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

Rules:

- Must not depend on Laravel.
- Must not depend on Eloquent.
- Must not depend on HTTP.
- Must not depend on Symfony Messenger or RabbitMQ.
- Must not contain infrastructure implementation details.
- Must not depend on database schemas or storage-specific identifiers.

Aggregate Roots must protect their invariants and control changes to their internal entities.

Domain events must represent facts that have actually occurred in the domain.

### 3.2. Application

The Application layer coordinates the execution of use cases.

It is responsible for:

- Application use cases.
- Commands and command handlers where useful.
- Queries and query handlers where useful.
- Input and output ports.
- Repository and service contracts required by use cases.
- Orchestration of domain behavior.
- Coordination of transaction boundaries through appropriate abstractions.

Rules:

- Must not depend on Eloquent.
- Must not depend on HTTP request or response classes.
- Must not use Laravel facades directly.
- Must not contain infrastructure implementations.
- Must not duplicate business invariants already owned by the Domain.

CQRS should be applied pragmatically where it improves clarity or separation of responsibilities.

### 3.3. Infrastructure

The Infrastructure layer implements the technical details required by the Application and Domain.

It is responsible for:

- Eloquent models.
- Repository implementations.
- Persistence mappers.
- Database access.
- Unit of Work or transaction adapters where required.
- Outbox persistence and publishing.
- RabbitMQ and Symfony Messenger integration.
- External service adapters.

Infrastructure implementations must respect the contracts defined by inner layers.

Persistence concerns must not leak into the Domain.

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

## 4. Domain Events and Integration

Domain events must represent meaningful business facts.

Rules:

- Events are created as a consequence of domain behavior.
- Events must not contain sensitive information.
- Events must not depend on RabbitMQ, Messenger or database models.
- Event identifiers must not impose unnecessary storage-specific constraints.
- Publishing integration events must respect the transactional Outbox architecture.

The transactional Outbox approach is defined in:

- `docs/adr/003-outbox-rabbitmq.md`

The event must be recorded in the Outbox within the same transaction as the business state change whenever reliable publication is required.

The publisher and message consumers belong to Infrastructure.

Consumers must be designed to handle duplicate delivery safely.

## 5. Primitive Mapping and Reconstruction

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

This representation must remain independent of API contracts and persistence schemas.

Infrastructure mappers are responsible for adapting it to database models and columns.

## 6. Identifiers and Value Objects

Domain identifiers must be represented through appropriate Value Objects.

Rules:

- Domain identifiers must encapsulate their own validation and behavior.
- Domain identifiers must not be designed around a specific database column type.
- The Domain must not assume that every identifier is an integer.
- UUIDs or other identifier formats may be used where appropriate.
- Persistence adapters are responsible for translating between domain identifiers and storage representations.

Shared identifier contracts must be reviewed before reuse.

## 7. Testing Strategy

Tests must be organized according to architectural responsibility.

### 7.1. Domain Unit Tests

Domain tests must:

- Use PHPUnit without requiring Laravel.
- Test Value Objects.
- Test Entities and Aggregate Roots.
- Verify business invariants.
- Verify valid and invalid state transitions.
- Verify domain events.
- Verify primitive mapping and reconstruction.

Domain tests must not require a database or external infrastructure.

### 7.2. Application Unit Tests

Application tests must:

- Test use case orchestration.
- Use test doubles for ports and dependencies.
- Verify interactions with repository contracts and other abstractions.
- Verify application-level error handling.
- Avoid requiring Laravel bootstrapping when it is not necessary.

### 7.3. Infrastructure Integration Tests

Infrastructure tests must verify concrete implementations, including where appropriate:

- Eloquent repositories.
- Database mappings.
- Transaction handling.
- Outbox persistence.
- RabbitMQ publishing and consumption.
- Integration with external services.

These tests may require Laravel, MySQL, RabbitMQ or other infrastructure.

### 7.4. API Feature Tests

API tests must verify:

- HTTP routes.
- Request validation.
- HTTP response structures and status codes.
- Mapping between HTTP and Application layers.
- HTTP-specific error handling.

Tests must cover the externally observable behavior of the API.

## 8. Bounded Context Independence

Each bounded context must own its domain model and business rules.

Rules:

- Aggregates must not be shared between bounded contexts.
- A context must not modify another context's internal domain objects.
- Cross-context communication must use explicit contracts and integration mechanisms.
- Shared code must be limited to genuinely generic concepts.
- Shared abstractions must not introduce accidental coupling between contexts.

The existence of a Shared namespace does not justify placing every reusable class or contract there.

## 9. Architecture Decision Records

Architecture decisions that affect the structure, consistency or long-term evolution of the system must be documented through ADRs.

Relevant existing decisions include:

- `docs/adr/001-modular-monolith.md`
- `docs/adr/002-atomic-stock-reservation.md`
- `docs/adr/003-outbox-rabbitmq.md`

New implementations must respect accepted ADRs.

If an implementation requires changing an existing architectural decision, the change must be evaluated and documented before implementation.

## 10. Applying This Reference to New Features

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

## 11. Scope and Evolution

This document establishes the architectural reference for new StockFlow development.

It does not require an immediate refactoring of the existing `User` context.

Existing code may contain historical decisions that differ from these guidelines.

Such differences should be addressed only when explicitly planned and approved, rather than through unrelated refactoring.

This document may evolve as the project develops, provided changes are reviewed and remain consistent with the accepted architectural decisions.
