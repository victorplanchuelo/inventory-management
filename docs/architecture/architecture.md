# Architecture — StockFlow

## 1. Architectural Style

StockFlow evolves as a **Modular Monolith** with Hexagonal Architecture separation per bounded context.

Modules share the same process and deployment, but maintain explicit boundaries and independent responsibilities.

Initial modules:

- `Orders`: order lifecycle and coordination of its lines.
- `Inventory`: quantities, availability and reservations.
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
├── Orders/
│   ├── Domain/
│   ├── Application/
│   └── Infrastructure/
└── Inventory/
    ├── Domain/
    ├── Application/
    └── Infrastructure/
```

This structure is indicative and must be adapted to the actual needs of each context.

Not every feature needs to modify every layer.

Each bounded context is responsible for its own domain model. Aggregates must not be shared between contexts.

Shared capabilities must be limited to genuinely cross-cutting concepts and must not introduce accidental coupling.

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
- Outbox and publisher.
- Symfony Messenger and RabbitMQ integration.
- External service adapters.
- HTTP inbound adapters.

Infrastructure depends on the abstractions defined by inner layers and provides their concrete implementations.

#### API / HTTP Entry

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

### 3.4. Controllers and Form Requests — Directory Convention

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

The same convention applies to `Inventory`:

```text
src/
└── Inventory/
    └── Infrastructure/
        └── Api/
            ├── Controllers/
            └── Requests/
```

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
6. Availability is checked and the entire required stock quantity is reserved.
7. The order is confirmed and the reservations are persisted.
8. The corresponding integration messages are recorded in the Outbox within the same transaction.
9. The transaction is committed.
10. An independent publisher reads pending messages from the Outbox and publishes them to RabbitMQ.
11. Consumers process messages idempotently.

The decision to accept or reject a stock reservation must be synchronous and consistent with the business transaction.

This decision must not be delegated to an asynchronous message.

The details of consistency and atomic reservation are covered by the corresponding ADRs.

## 5. Consistency and Failure Handling

The design must account for the following scenarios:

- **Failure before commit:** the order, reservations and Outbox records are rolled back.
- **RabbitMQ unavailable after commit:** the message remains pending in the Outbox and can be retried.
- **Publisher fails after publishing but before marking the message as published:** duplicate publication may occur. Consumers must be idempotent.
- **Consumer failure:** retry policies and isolation of messages that cannot be processed are applied.
- **Duplicate messages:** consumers must prevent duplicate business effects.

Exactly-once delivery is not guaranteed.

The system is designed to support at-least-once delivery and idempotent effects.

## 6. Shared and Compatibility

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

If a shared contract does not meet the needs of the new context, the following options must be evaluated:

- Evolve the existing contract in a backward-compatible way.
- Introduce an independent abstraction.
- Maintain context-specific contracts.

Any cross-cutting change must be justified and covered by tests.

## 7. Events and Outbox

Domain events represent relevant business facts.

The publication of integration events must follow the transactional Outbox strategy.

When a business operation requires reliable publication:

- The business state change and the corresponding Outbox record must be persisted in the same transaction.
- Publication to RabbitMQ must take place after the commit.
- Publication failures must allow retries.
- Consumers must be idempotent.

The details of this architecture are defined in:

- `docs/adr/003-outbox-rabbitmq.md`

## 8. Testing and Quality

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
- No need to bootstrap Laravel when it is unnecessary.

### Infrastructure

- Integration tests.
- Verification of repositories and mappers.
- Verification of persistence and transactions.
- Verification of the Outbox.
- Verification of RabbitMQ integration where applicable.

### API

- Feature/HTTP tests.
- Verification of routes and Controllers.
- Request validation.
- Verification of responses and HTTP status codes.
- Verification of mapping between HTTP and Application.

### Quality Tools

Only tools actually configured in Composer and the project configuration will be used.

New code must comply with the established style and static analysis standards.

Baselines must not be modified, and mass corrections to legacy code must not be applied without authorization.

## 9. Architectural References

This document describes the overall architecture of StockFlow.

For detailed design and implementation rules for new bounded contexts, consult:

- `docs/architecture/architectural-reference.md`

Accepted architectural decisions are documented in:

- `docs/adr/001-modular-monolith.md`
- `docs/adr/002-atomic-stock-reservation.md`
- `docs/adr/003-outbox-rabbitmq.md`

Implementations must comply with these decisions.

If a feature requires changing an existing architectural decision, the change must be evaluated and approved before implementation.
