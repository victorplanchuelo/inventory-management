# Task 01 — Inventory Domain and Application Foundation

**Status:** Proposed  
**Bounded Context:** Inventory  
**Phase:** Domain and Application Foundation

## 1. Objective

Establish the Inventory bounded context's domain model and application contracts.

This task implements the business rules for stock management and reservations independently of Laravel, Eloquent, MySQL, RabbitMQ, Symfony Messenger and HTTP.

The result must provide a domain and application foundation that can later be used by persistence adapters and by other bounded contexts through explicit application boundaries.

## 2. Scope

### In scope

- Define the `StockItem` aggregate root.
- Define Inventory-specific identifiers and Value Objects.
- Define stock quantity and availability rules.
- Define `StockReservation` as an entity owned by `StockItem`.
- Define domain operations for:
  - Registering initial stock.
  - Adding stock.
  - Reserving stock.
  - Releasing a reservation.
- Define the `StockItemRepository` domain port.
- Define application use cases for the operations above.
- Define relevant domain exceptions.
- Record relevant domain events using the existing shared domain-event contract where appropriate.
- Add unit tests for domain rules and application behavior.

### Out of scope

- Eloquent models and database persistence.
- Database migrations.
- HTTP controllers, routes and API resources.
- RabbitMQ publication or consumption.
- Transactional Outbox and Inbox implementation.
- Integration-event contracts and transport.
- Cross-context projections.
- Orders implementation and order confirmation.
- Coordination of transactions across bounded contexts.
- Warehouse, store or physical location management.
- Changes to the existing User bounded context or shared infrastructure.
- Changes to the existing shared domain-event contract, which were addressed in Task 002.

## 3. Domain Model

### 3.1. StockItem aggregate root

`StockItem` is the aggregate root responsible for the stock of one product.

Initially, Inventory maintains exactly one `StockItem` per `ProductId`.

The aggregate contains:

- `StockItemId`: UUID identifying the StockItem aggregate.
- `ProductId`: UUID identifying the product in the wider system.
- `onHand`: total physical quantity currently held.
- `reserved`: quantity currently committed to active reservations.
- Its collection of `StockReservation` entities.

The aggregate must always preserve this invariant:

`0 <= reserved <= onHand`

Available stock is derived:

`available = onHand - reserved`

Available stock must not be maintained as an independently mutable counter.

The one-StockItem-per-ProductId rule is a domain-level business rule. A unique database constraint will provide an additional persistence-level safeguard when persistence is implemented.

### 3.2. Product identity

Inventory identifies products through `ProductId`.

`ProductId` is an external reference. Inventory must not depend on the Catalog aggregate, access Catalog repositories or take ownership of product descriptive information.

The initial system uses UUIDs for product references.

If Catalog is not implemented initially, ProductIds must still be generated and supplied through an explicitly defined mechanism outside Inventory. Inventory only validates that the supplied identifier is a valid UUID; it does not validate the existence of a Catalog product.

### 3.3. Identifiers

Use specific identifiers for Inventory concepts:

- `StockItemId`
- `ProductId`
- `ReservationId`
- `OrderId`

These identifiers must preserve type distinction in the domain rather than passing interchangeable primitive strings throughout the model.

Reuse the existing shared UUID abstraction in `Manager\Shared\Domain\ValueObject`. Do not create another generic UUID implementation.

The existing `Uuid` abstraction validates UUID values and provides value access. `SimpleUuid` is a concrete generic UUID implementation and is final; specific Inventory identifiers must use the shared abstraction without attempting to extend `SimpleUuid`.

All aggregate and reference identifiers must remain independent of database-specific identifier types.

### 3.4. StockReservation entity

`StockReservation` is an entity owned by the `StockItem` aggregate. It is not an independent aggregate root.

Each reservation contains:

- `ReservationId`: UUID.
- `OrderId`: UUID identifying the order requesting the reservation.
- `Quantity`: reserved quantity.
- `Status`: `Active` or `Released`.

The aggregate controls the lifecycle of its reservations and ensures that reservation state remains consistent with its stock counters.

A reservation can only be released once. Attempting to release an already released reservation must be rejected as an invalid domain operation. Making repeated release requests idempotent is not part of this task.

### 3.5. Quantity Value Object

Stock quantities are discrete integers in the initial implementation. Fractional quantities are out of scope.

`Quantity` must be a Value Object based on the existing `IntValueObject` abstraction.

The Value Object must reject negative values but allow zero.

Zero is valid for representing:

- No reserved stock.
- No available stock.
- A zero quantity resulting from a valid calculation.

Operations that introduce or reserve stock must enforce strictly positive quantities:

- Initial stock registration: greater than zero.
- Stock addition: greater than zero.
- Reservation: greater than zero.

The existing `IntValueObject` does not validate numeric ranges by itself. `Quantity` must implement the non-negative validation explicitly.

### 3.6. Aggregate invariants

The aggregate must preserve these invariants after every successful operation:

- `onHand` cannot be negative.
- `reserved` cannot be negative.
- `reserved` cannot exceed `onHand`.
- Available stock cannot be negative.
- Every active reservation has a strictly positive quantity.
- The sum of active reservation quantities must equal the aggregate's `reserved` counter.
- A released reservation no longer contributes to `reserved`.
- A reservation cannot be released more than once.

The aggregate must not expose mutable internal collections or allow Application and Infrastructure code to change reservation state directly.

## 4. Domain Operations

The aggregate must expose business behavior rather than allowing application services or infrastructure adapters to modify its internal state directly.

### Register initial stock

Create a `StockItem` for a `ProductId` with an initial positive quantity.

The operation must reject an invalid initial quantity.

The Application use case must check whether a StockItem already exists for the ProductId before registering it. Persistence-level uniqueness will provide an additional concurrency safeguard in a future task.

### Add stock

Increase `onHand` by a strictly positive quantity.

Adding stock must not modify existing reservations.

### Reserve stock

Given an `OrderId` and a strictly positive quantity:

1. Calculate available stock.
2. Reject the operation if the requested quantity exceeds availability.
3. Create an active `StockReservation` with a unique `ReservationId`.
4. Increase the `reserved` counter.
5. Preserve all aggregate invariants.

The operation must not allow the aggregate to become over-reserved.

### Release reservation

Given a `ReservationId`:

1. Locate the reservation owned by the aggregate.
2. Verify that it exists and is active.
3. Mark it as released.
4. Decrease the `reserved` counter by the reservation quantity.
5. Preserve all aggregate invariants.

The operation must reject a reservation that does not belong to the aggregate or has already been released.

## 5. Domain Events

The Inventory aggregate may record domain events for relevant business state changes, such as:

- Initial stock registration.
- Stock addition.
- Stock reservation.
- Reservation release.

Event names and payloads must describe facts that have occurred, not commands or future intentions.

Use the existing shared `AggregateRoot` and `DomainEvent` abstractions where appropriate.

The existing `DomainEvent` contract accepts UUID aggregate identifiers as strings. Inventory events must use the `StockItemId` value as their aggregate identifier.

Domain events are internal domain facts. They are not integration-event contracts and must not be published to RabbitMQ by this task.

This task does not implement an Outbox or any event transport mechanism.

## 6. Repository Port

Define `StockItemRepository` in the Inventory Domain.

The repository represents the collection and lifecycle of `StockItem` aggregates. Its contract must not expose Eloquent models, query builders, database schemas or other infrastructure-specific details.

The contract must support the operations required by the application use cases:

- Search a StockItem by `StockItemId`.
- Search a StockItem by `ProductId`.
- Search the owning StockItem by `ReservationId`.
- Persist a `StockItem` aggregate.

The reservation lookup must return the owning aggregate, including the reservation entities required to execute the release operation.

A separate `StockReservationRepository` must not be introduced because reservations are not independent aggregate roots.

The exact method names may follow the existing repository naming conventions, but the lookup by `ReservationId` must not require the caller to know the associated `ProductId`.

## 7. Application Layer

Define application use cases that coordinate repository access and domain behavior.

Initial use cases:

- `RegisterInitialStock`
- `AddStock`
- `ReserveStock`
- `ReleaseStock`

Responsibilities:

- Validate application input and resolve the required aggregate through repository ports.
- Invoke domain behavior.
- Persist the resulting aggregate through the repository.
- Return an appropriate application result or propagate a defined application/domain error.

The Application layer must depend on the Inventory Domain and the repository contract.

It must not depend on:

- Eloquent models.
- Laravel HTTP requests or responses.
- Laravel facades.
- RabbitMQ or Symfony Messenger.
- Other bounded contexts' repositories or domain models.

Application code must not duplicate business invariants already enforced by the aggregate.

No database transaction abstraction or persistence-specific locking mechanism is required in this task. Those responsibilities will be addressed when Inventory persistence is implemented.

## 8. Proposed Structure

The initial structure should follow the project's PSR-4 mapping (`Manager\` to `src/`) and bounded-context conventions.

```text
src/
└── Inventory/
    ├── Domain/
    │   ├── StockItem.php
    │   ├── StockReservation.php
    │   ├── StockItemRepository.php
    │   ├── ValueObject/
    │   │   ├── StockItemId.php
    │   │   ├── ProductId.php
    │   │   ├── ReservationId.php
    │   │   ├── OrderId.php
    │   │   └── Quantity.php
    │   ├── Event/
    │   └── Exception/
    └── Application/
        ├── RegisterInitialStock/
        ├── AddStock/
        ├── ReserveStock/
        └── ReleaseStock/
```

The structure is a proposal. Names and internal organization may be refined during implementation, provided the architectural boundaries and responsibilities remain intact.

Do not create empty directories or speculative abstractions without a concrete implementation need.

## 9. Testing

Domain and Application unit tests belong under:

```text
tests/
└── Unit/
    └── Inventory/
        ├── Domain/
        └── Application/
```

The test structure must mirror the internal structure of `src/`.

### Domain tests

Cover at least:

- Creation of a StockItem with valid initial stock.
- Rejection of negative and zero initial stock.
- Rejection of negative Quantity values.
- Acceptance of zero Quantity where zero is a valid state.
- Addition of positive stock.
- Rejection of zero or negative stock additions.
- Successful reservation.
- Rejection of zero or negative reservation quantities.
- Rejection of reservations exceeding available stock.
- Correct calculation of available stock.
- Correct updates to reserved quantity.
- Successful release of an active reservation.
- Rejection of release for an unknown reservation.
- Rejection of repeated release.
- Preservation of aggregate invariants after each operation.
- Consistency between active reservation quantities and the reserved counter.
- Correct recording of relevant domain events, if implemented.

### Application tests

Use repository test doubles to verify:

- Initial stock registration when no StockItem exists.
- Rejection of duplicate ProductId registration.
- Stock addition to an existing StockItem.
- Reservation of an existing StockItem.
- Rejection when the requested ProductId does not exist in Inventory.
- Release using ReservationId without requiring ProductId from the caller.
- Persistence of the aggregate after successful operations.
- Propagation of relevant domain/application errors.

Domain and Application unit tests must not require Laravel, MySQL, RabbitMQ or Symfony Messenger.

Use the existing PHPUnit configuration and test organization established by Task 002.

## 10. Dependencies and Related Work

- **Task 002 — Shared Domain Event Compatibility and Test Structure:** completed and verified. The shared `DomainEvent` contract supports UUID aggregate identifiers, and the Unit test structure is available.
- **ADR 001 — Modular Monolith:** defines the modular monolith and bounded-context organization.
- **Architectural Reference:** defines Domain, Application and Infrastructure responsibilities.

No Outbox, Inbox, integration-event transport, persistence adapter or Orders implementation is a prerequisite for this task.

## 11. Research Findings

The following findings were verified by inspecting the current `feature/stockflow-foundation` branch. No repository files were modified during Research.

### Existing Inventory Context

- No `src/Inventory` bounded context currently exists.
- Inventory Domain and Application classes must be introduced as a new bounded context.
- The existing `src/Api/User` context is a historical reference, not an implementation template. Its framework coupling and other documented limitations must not be reproduced.

### Shared UUID Abstractions

- `Manager\Shared\Domain\ValueObject\Uuid` is an abstract UUID Value Object.
- Its constructor is final and validates UUID values.
- `SimpleUuid` is final and must not be extended.
- Existing specific identifiers, such as `UserUuid`, extend `Uuid` directly.
- Inventory identifiers should follow that pattern.
- `Uuid::random()` declares a `self` return type, which resolves to `Uuid`. Do not assume that calling this inherited method provides a statically typed `StockItemId`, `ProductId`, `ReservationId` or `OrderId`. Use an explicit typed-ID factory or construct the specific identifier from a generated UUID value without modifying Shared as part of this task.

### Quantity and Aggregate Support

- `IntValueObject` stores an integer but does not enforce numeric ranges.
- `Quantity` must therefore implement its own non-negative validation before delegating to the parent constructor.
- `AggregateRoot` already provides `record()` and `pullDomainEvents()` for internal domain-event management.
- No new generic aggregate or Value Object abstraction is required for this task.

### Domain Events and Exceptions

- The existing `DomainEvent` contract accepts UUID aggregate identifiers as strings.
- It validates aggregate and event identifiers independently.
- Concrete events must implement `eventName()`, `fromPrimitives()` and `toPrimitives()`.
- Inventory events must use the `StockItemId` value as their aggregate identifier.
- `Manager\Shared\Domain\DomainError` provides the existing base class for domain errors. Inventory-specific exceptions should extend it rather than introduce another shared exception abstraction.
- Domain events remain internal to Inventory. This task does not implement external publication or an Outbox.

### Testing and Runtime

- Composer declares PHP `^8.3`, Laravel `^11` and PHPUnit `^11`.
- PHPUnit defines Unit, Integration and Api suites.
- Unit tests for Inventory belong under `tests/Unit/Inventory/`, mirroring the source structure.
- Existing Shared unit tests use `PHPUnit\Framework\TestCase` and PHPUnit's `#[Test]` attribute.
- Inventory Domain and Application unit tests must not require Laravel, MySQL, RabbitMQ or Symfony Messenger.

### Design Points to Settle During Planning

- Define how each typed Inventory identifier is generated without changing the Shared UUID contract.
- Confirm the namespace and directory naming convention for Inventory Value Objects.
- Define the exact application input/output contracts and repository method signatures.
- Decide explicitly which domain events are required in this foundation task, without introducing integration-event publication.
- Keep persistence, database transactions, locking and cross-context coordination outside this task's implementation scope.

## 12. Acceptance Criteria

- [ ] The Inventory bounded context has a domain model independent of Laravel and infrastructure.
- [ ] `StockItem` is the aggregate root for one `ProductId`.
- [ ] Exactly one StockItem per ProductId is the initial Inventory rule.
- [ ] Inventory identifiers use UUIDs and preserve type distinction.
- [ ] `ProductId` is treated as an external reference.
- [ ] `StockReservation` is an entity owned by `StockItem`.
- [ ] `Quantity` rejects negative values and permits zero.
- [ ] Operations that register, add or reserve stock require a positive quantity.
- [ ] The aggregate maintains `0 <= reserved <= onHand`.
- [ ] Available stock is derived from `onHand - reserved`.
- [ ] Active reservation quantities remain consistent with the reserved counter.
- [ ] Stock reservations cannot exceed available stock.
- [ ] A reservation can only be released once.
- [ ] The Inventory repository port is defined in the Domain.
- [ ] Reservation lookup returns the owning StockItem aggregate.
- [ ] Application use cases coordinate operations through domain behavior and repository ports.
- [ ] Relevant domain events use the shared UUID-compatible event contract, if recorded.
- [ ] Unit tests cover the relevant domain rules and application behavior.
- [ ] No persistence, API, RabbitMQ, Outbox, Inbox, projection or Orders implementation is introduced.
- [ ] No warehouse or location management is introduced.
- [ ] No unrelated Shared or User refactoring is introduced.

## 13. Future Considerations

The following capabilities are intentionally excluded:

- Multiple warehouses or stock locations.
- Stock transfers between locations.
- Independent reservation aggregate roots.
- Fractional stock quantities.
- Reservation expiration and automatic release policies.
- Inventory persistence and database concurrency control.
- Cross-context order confirmation and transaction coordination.
- Inventory API endpoints.
- Integration-event publication and consumption.

These capabilities may be considered in later tasks if requirements justify them. Their absence must not lead to premature abstractions in this task.
