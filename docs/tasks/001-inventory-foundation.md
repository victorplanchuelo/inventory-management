# Task 01 — Inventory Domain and Application Foundation

**Status:** Proposed  
**Bounded Context:** Inventory  
**Phase:** Domain and Application Foundation

## 1. Objective

Establish the Inventory bounded context's domain model and application contracts.

This task focuses on defining stock management and reservation rules independently of Laravel, Eloquent, MySQL, RabbitMQ, Symfony Messenger, and HTTP.

The result must provide a domain foundation that can later support persistence, transactional stock reservations, and coordination with the Orders bounded context.

## 2. Scope

### In scope

- Define the `StockItem` aggregate root.
- Define Inventory identifiers and value objects.
- Define stock quantity and availability rules.
- Define `StockReservation` as an entity owned by `StockItem`.
- Define domain operations for:
  - Registering initial stock.
  - Adding stock.
  - Reserving stock.
  - Releasing a reservation.
- Define the Inventory repository port.
- Define application contracts and use cases for the operations above.
- Define domain exceptions and relevant domain events, subject to the shared domain-event contract being compatible with UUID aggregate identifiers.
- Add unit tests for domain rules and application behaviour.

### Out of scope

- Eloquent models and database persistence.
- Database migrations.
- HTTP controllers, routes, and API resources.
- RabbitMQ publication and consumption.
- Transactional Outbox and Inbox implementation.
- Cross-context projections.
- Orders implementation and order confirmation.
- Warehouse, store, or physical location management.
- Changes to the existing User bounded context, except where a separately approved shared-infrastructure task requires compatibility work.

## 3. Domain Model

### 3.1 StockItem aggregate root

`StockItem` is the aggregate root responsible for the stock of one product.

Initially, the Inventory context will maintain exactly one `StockItem` per `ProductId`.

The aggregate will contain:

- `StockItemId`: UUID identifying the StockItem aggregate.
- `ProductId`: UUID identifying the product in the wider system.
- `onHand`: Total physical quantity currently held.
- `reserved`: Quantity currently reserved for orders.
- Its collection of stock reservations.

The following invariant must always hold:

`0 <= reserved <= onHand`

Available stock is derived:

`available = onHand - reserved`

`available` must not be independently persisted as a counter.

A unique database constraint on `product_id` must enforce the one-StockItem-per-product rule when persistence is introduced.

### 3.2 Identifiers

Use UUIDs for aggregate identifiers and product references.

Inventory must not depend on the Catalog aggregate or access Catalog repositories to validate a ProductId. ProductId is an external identity understood by Inventory, not a reference to another bounded context's domain object.

The initial implementation assumes Catalog will use UUID product identifiers as well. If Catalog is not included in the initial project scope, the system must still define how ProductIds are generated and supplied.

### 3.3 StockReservation entity

`StockReservation` is an entity owned by the `StockItem` aggregate. It is not an independent aggregate root.

Each reservation contains:

- `ReservationId`: UUID.
- `OrderId`: UUID identifying the order requesting the reservation.
- `Quantity`: Positive integer quantity.
- `Status`: Active or Released.

The aggregate is responsible for ensuring that reservations and stock counters remain consistent.

A reservation may be released only once. Releasing an already released reservation must be rejected or handled according to an explicitly documented idempotency policy.

### 3.4 Quantity rules

For the initial implementation, stock quantities are discrete positive integers.

- Initial stock must be greater than zero.
- Added stock must be greater than zero.
- Reservation quantity must be greater than zero.
- A reservation must not exceed available stock.
- Releasing a reservation must restore the corresponding available quantity.
- The aggregate must never allow negative stock or reserved quantities.

Fractional quantities are outside the initial scope.

## 4. Domain Operations

The aggregate must expose behaviour rather than allowing application services or infrastructure adapters to modify its internal state directly.

### Register initial stock

Create a StockItem for a ProductId with an initial positive quantity.

The operation must reject a duplicate StockItem for the same ProductId. Persistence-level uniqueness will provide an additional concurrency safeguard.

### Add stock

Increase `onHand` by a positive quantity.

Adding stock must not modify existing reservations.

### Reserve stock

Given an OrderId and a positive quantity:

1. Calculate available stock.
2. Reject the operation if the requested quantity exceeds availability.
3. Create an active StockReservation.
4. Increase the `reserved` counter.
5. Preserve the aggregate invariants.

### Release reservation

Given a ReservationId:

1. Locate the reservation owned by the StockItem.
2. Verify that it is active.
3. Mark it as released.
4. Decrease the `reserved` counter by the reservation quantity.
5. Preserve the aggregate invariants.

The operation must not release the same reservation twice.

## 5. Consistency and Concurrency

The domain aggregate protects its own invariants, but application and infrastructure layers must later ensure those invariants remain valid under concurrent requests.

When persistence is implemented:

- StockItem updates and reservation records must be committed in the same database transaction.
- Concurrent reservations for the same StockItem must be serialized or protected using an appropriate database locking strategy.
- A failed reservation must not leave a partially updated counter or reservation record.
- The database must enforce uniqueness of `product_id`.

The initial domain and application foundation must not introduce database-specific locking or transaction dependencies.

## 6. Application Layer

Define application use cases that coordinate domain operations and repository access.

Initial use cases:

- `RegisterInitialStock`
- `AddStock`
- `ReserveStock`
- `ReleaseStock`

The application layer must depend on repository ports defined within the Inventory context.

It must not depend on Eloquent models, HTTP requests, RabbitMQ, or other bounded contexts' repositories.

The repository contract should support retrieving and persisting StockItem aggregates without exposing infrastructure-specific details.

## 7. Proposed Structure

The initial structure should follow the project's PSR-4 mapping (`Manager\\` to `src/`) and bounded-context conventions.

```text
src/
└── Inventory/
    ├── Domain/
    │   ├── StockItem.php
    │   ├── StockReservation.php
    │   ├── StockItemRepository.php
    │   ├── ValueObjects/
    │   └── Exceptions/
    └── Application/
        ├── RegisterInitialStock/
        ├── AddStock/
        ├── ReserveStock/
        └── ReleaseStock/
```

Names and internal file organization may be refined during implementation, provided the boundaries and responsibilities remain intact.

## 8. Testing

Unit tests must mirror the internal structure of `src/`, with a separate top-level directory identifying the test type.

For this task, domain and application tests belong under:

```text
tests/
└── Unit/
    └── Inventory/
        ├── Domain/
        └── Application/
```

Tests must cover at least:

- StockItem creation with valid initial stock.
- Rejection of invalid initial quantities.
- Adding stock.
- Rejection of invalid added quantities.
- Successful stock reservation.
- Rejection of reservations exceeding available stock.
- Correct calculation of available stock.
- Reservation release.
- Rejection of repeated release.
- Preservation of aggregate invariants after each operation.
- Application use-case behaviour using repository test doubles.

Domain unit tests must not require Laravel, MySQL, RabbitMQ, or Symfony Messenger.

The PHPUnit configuration and existing test-suite organization will be reviewed as part of a separate shared testing-infrastructure task.

## 9. Dependencies and Related Work

This task depends on a compatible shared domain-event contract if Inventory domain events are introduced.

The existing `DomainEvent` contract currently uses an integer aggregate identifier. The project has agreed to use UUID aggregate identifiers.

The shared event-contract compatibility work must therefore be handled independently, with its own regression tests, before Inventory relies on that contract.

This task does not implement the Outbox or integration-event transport. Those responsibilities belong to separate tasks.

## 10. Acceptance Criteria

- [ ] The Inventory bounded context has a domain model independent of Laravel and infrastructure.
- [ ] StockItem is the aggregate root for one ProductId.
- [ ] StockItem and ProductId use UUID identifiers.
- [ ] StockReservation is an entity owned by StockItem.
- [ ] The aggregate maintains the invariant `0 <= reserved <= onHand`.
- [ ] Available stock is derived from `onHand - reserved`.
- [ ] Initial stock registration and stock addition reject invalid quantities.
- [ ] Stock reservations cannot exceed available stock.
- [ ] A reservation can only be released once.
- [ ] Repository ports are defined in the Inventory domain.
- [ ] Application use cases coordinate operations through domain behaviour and repository ports.
- [ ] Unit tests cover the relevant domain rules and application behaviour.
- [ ] No persistence, API, RabbitMQ, Outbox, Inbox, or Orders implementation is introduced by this task.
- [ ] The implementation does not introduce warehouse or location management.

## 11. Future Considerations

The following capabilities are intentionally excluded from the initial scope:

- Multiple warehouses or stock locations.
- Stock transfers between locations.
- Independent reservation aggregate roots.
- Fractional stock quantities.
- Advanced reservation expiration and automatic release policies.

These capabilities may be considered if future requirements justify them. Their absence must not lead to premature abstractions in the initial implementation.
