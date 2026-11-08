# Task 06 — Inventory Persistence and Stock Reservation

**Status:** Proposed  
**Bounded Context:** Inventory  
**Phase:** Persistence and Transactional Stock Operations

## 1. Objective

Implement MySQL persistence for the Inventory bounded context and make stock operations safe under concurrent requests.

This task builds on the Inventory domain and application foundation defined in Task 01.

It introduces the Infrastructure adapters required to persist and reconstruct `StockItem` aggregates, including their `StockReservation` entities.

It must ensure that stock changes, reservation records and corresponding Inventory Outbox records are committed atomically within the Inventory transaction.

The implementation must preserve Inventory ownership of stock and reservations and must not introduce cross-context persistence access.

## 2. Architectural Principles

The implementation must follow these principles:

- Inventory owns its stock and reservation persistence.
- `StockItem` remains the aggregate root for one `ProductId`.
- `StockReservation` remains an entity owned by `StockItem`.
- Domain and Application remain independent of Laravel, Eloquent and MySQL.
- Infrastructure implements the repository and transaction mechanisms required by Application.
- Stock counters and reservation records must remain consistent.
- Concurrent stock operations must be protected by database transactions and appropriate row locking.
- Inventory business changes and their corresponding Inventory Outbox records must be committed atomically.
- The Outbox publisher and RabbitMQ transport are provided by Task 03 and must not be reimplemented here.
- Inventory must not read or write Orders or Catalog persistence.
- No cross-context transaction is introduced.

## 3. Scope

### In scope

- Define the Inventory persistence schema.
- Create migrations for StockItems and StockReservations.
- Implement Eloquent models and persistence mappers.
- Implement the Inventory `StockItemRepository` adapter.
- Persist and reconstruct complete `StockItem` aggregates.
- Persist and reconstruct their reservation entities.
- Implement transactional stock operations.
- Lock StockItem records when required to protect concurrent updates.
- Support reservation lookup by `ReservationId` through its owning StockItem.
- Integrate Inventory business changes with the existing Outbox mechanism from Task 03.
- Map relevant Inventory business facts to integration-event contracts.
- Add persistence and concurrency integration tests using MySQL.

### Out of scope

- Inventory domain and application foundation already specified by Task 01.
- Generic Outbox publisher implementation.
- Generic Inbox and consumer infrastructure.
- RabbitMQ exchange, queue and routing redesign.
- Orders aggregate, persistence or lifecycle.
- Order confirmation coordination across Orders and Inventory.
- Catalog implementation or product lookup.
- HTTP controllers, routes and API resources.
- Cross-context projections.
- Warehouse, store or physical location management.
- Reservation expiration and automatic release policies.
- Distributed transactions or a generic saga/workflow engine.
- Event Sourcing.
- Unrelated refactoring of the existing User context.

## 4. Dependencies and Architectural Context

This task depends on:

- **Task 01 — Inventory Domain and Application Foundation:** provides the StockItem aggregate, reservation entity, identifiers, value objects, domain operations, repository contract and application use cases.
- **Task 02 — Shared Domain Event Compatibility and Test Structure:** provides the compatible shared event contract and established test organization. Task 02 is verified.
- **Task 03 — Transactional Outbox and Integration Infrastructure:** provides the Outbox mechanism used to persist and publish integration events.
- **ADR 003 — Transactional Outbox and RabbitMQ:** defines atomic business-change and Outbox persistence.
- **ADR 004 — Integration Events and Cross-Context Communication:** defines event ownership, envelope and producer responsibilities.

The exact Inventory integration-event contracts must be agreed before publishing events consumed by other contexts.

The task must not depend on Task 04 or Task 05 implementation. Those tasks consume events and maintain local projections; they do not own Inventory persistence.

### Dependency status

Implementation is blocked until the required domain/application foundation and Outbox infrastructure are available and reviewed.

If Task 01 or Task 03 changes its contracts during implementation planning, this specification must be reviewed before approval.

## 5. Persistence Model

The initial persistence model represents one StockItem per ProductId and its owned reservations.

### 5.1. `inventory_stock_items`

Proposed table:

| Column | Purpose |
|---|---|
| `id` | UUID identifying the StockItem aggregate |
| `product_id` | UUID identifying the product |
| `on_hand` | Total physical stock quantity |
| `reserved` | Total currently reserved quantity |
| `created_at` | Record creation timestamp |
| `updated_at` | Last persistence update timestamp |

Requirements:

- `id` must be unique and represent the StockItem identifier.
- `product_id` must have a unique constraint.
- `on_hand` and `reserved` must use an integer-compatible database type.
- `on_hand` and `reserved` must not allow negative values.
- The invariant `reserved <= on_hand` must be preserved.
- `available` must not be persisted as an independent counter.
- Database constraints should enforce the invariants that can safely be expressed at schema level.
- Application and domain validation remain mandatory; database constraints are an additional safeguard.

The final column types, UUID representation and constraint syntax must follow the existing MySQL version and repository conventions.

### 5.2. `inventory_stock_reservations`

Proposed table:

| Column | Purpose |
|---|---|
| `id` | UUID identifying the reservation |
| `stock_item_id` | UUID identifying the owning StockItem |
| `order_id` | UUID identifying the order requesting the reservation |
| `quantity` | Positive reserved quantity |
| `status` | Reservation state: active or released |
| `created_at` | Record creation timestamp |
| `updated_at` | Last persistence update timestamp |

Requirements:

- Every reservation belongs to exactly one StockItem.
- `stock_item_id` must reference `inventory_stock_items.id`.
- `quantity` must be strictly positive.
- `status` must represent only the states supported by the domain model.
- A reservation must not become an independent aggregate root.
- The persistence model must preserve the identity of released reservations.
- Foreign-key behavior must not allow deleting a StockItem while reservations still need to be preserved.

The final naming and representation of reservation status must follow the approved domain model.

### 5.3. Uniqueness and indexes

The implementation must define appropriate constraints and indexes.

At minimum:

- Unique index on `inventory_stock_items.product_id`.
- Primary or unique key on StockItem UUID.
- Primary or unique key on reservation UUID.
- Index on `inventory_stock_reservations.stock_item_id`.
- Index on `inventory_stock_reservations.order_id` if required by verified query or operational needs.

Do not introduce uniqueness on `order_id` alone: an order may contain reservations for multiple products.

Any additional uniqueness rule must be justified by an explicit business invariant.

## 6. Repository Implementation

Implement the Infrastructure adapter for the Inventory domain repository contract.

The adapter must:

- Retrieve StockItem aggregates by `StockItemId`.
- Retrieve StockItem aggregates by `ProductId`.
- Retrieve the owning StockItem by `ReservationId`.
- Persist new StockItem aggregates.
- Persist changes to existing StockItem aggregates.
- Persist reservation additions and state changes.
- Reconstruct the complete aggregate, including its reservation entities.
- Translate between Eloquent persistence models and domain objects.

The repository must not expose Eloquent models, query builders or database-specific types to Application or Domain.

### 6.1. Reservation lookup

Reservation release must locate the aggregate that owns the reservation.

The repository must provide a lookup equivalent to:

`searchByReservationId(ReservationId): ?StockItem`

It must return the fully hydrated owning aggregate, including its reservations.

A separate Reservation repository is not required because `StockReservation` is an entity owned by StockItem.

### 6.2. Aggregate reconstruction

When loading a StockItem, the repository must reconstruct:

- StockItem identifier.
- Product identifier.
- On-hand quantity.
- Reserved quantity.
- All persisted reservation entities.
- Reservation identifiers.
- Order identifiers.
- Reservation quantities.
- Reservation statuses.

The repository must not reconstruct business state by querying Orders or Catalog.

The domain constructor or reconstruction mechanism must validate the resulting state according to the approved domain contract.

### 6.3. Persistence consistency

The adapter must ensure that changes to a StockItem and its reservation entities are persisted consistently.

A partial persistence operation must not leave:

- The reserved counter updated without its corresponding reservation.
- A reservation inserted without the corresponding counter update.
- A released reservation while the reserved counter still includes its quantity.
- A reservation deleted when the domain requires its released state to remain identifiable.

## 7. Transaction Boundaries and Concurrency

Inventory stock operations must execute within an Inventory-owned database transaction.

The transaction must include:

- Loading the relevant StockItem with the required locking strategy.
- Applying the domain operation.
- Persisting StockItem changes.
- Persisting reservation changes.
- Persisting corresponding Inventory Outbox records when the operation produces an integration event.

All these operations must commit or roll back together.

### 7.1. Row locking

Operations that can change stock availability must protect the relevant StockItem records against concurrent updates.

The implementation should use MySQL row-level locking through an Infrastructure adapter.

For reservation operations:

1. Resolve the relevant StockItem identifiers.
2. Acquire locks in deterministic order when multiple StockItems are involved.
3. Load the current aggregate state under the required lock.
4. Apply domain behavior against that state.
5. Persist the complete aggregate changes.
6. Persist corresponding Outbox records.
7. Commit the transaction.

The exact query and locking strategy must be validated against the project's MySQL version and transaction isolation configuration.

### 7.2. Multiple reservations in one operation

The persistence boundary must support an operation that reserves stock for multiple products as one Inventory transaction.

If any requested reservation cannot be fulfilled:

- No reservation from that operation may remain persisted.
- No reserved counter may remain partially updated.
- No corresponding success integration event may remain committed.
- The transaction must roll back.

This requirement protects the Inventory operation itself. It does not establish atomicity with Orders persistence.

### 7.3. Deadlocks and retries

The implementation must define a bounded retry policy for database deadlocks or other explicitly retryable transaction failures.

Requirements:

- Acquire locks in deterministic order.
- Keep transactions short.
- Do not perform RabbitMQ network I/O inside the transaction.
- Do not retry business validation failures as infrastructure failures.
- Ensure retries do not create duplicate reservations or duplicate integration-event identities.
- Make retry behavior observable.

The retry policy must be compatible with the chosen transaction abstraction and Laravel/MySQL behavior.

### 7.4. Transaction ownership

Application use cases must coordinate the transaction through an appropriate abstraction.

Laravel database APIs and Eloquent transaction calls must remain in Infrastructure.

The implementation must reuse an existing project transaction abstraction if one exists and is suitable.

If no suitable abstraction exists, the implementation plan must propose the smallest appropriate application port and Infrastructure adapter rather than introducing a generic Unit of Work framework without demonstrated need.

## 8. Integration Event and Outbox Integration

Inventory is responsible for publishing integration events describing its committed business facts.

The implementation must follow ADR 004.

### 8.1. Candidate events

Initial candidate event names include:

- `StockAdded`
- `StockReserved`
- `StockReleased`

Initial stock registration must also have an explicit publication decision. A candidate is `StockRegistered`.

The final event catalogue must be approved before implementation.

### 8.2. Event payload

Events must contain enough information for consumers to update their own projections without accessing Inventory persistence.

The proposed payload should include, as applicable:

- `stockItemId`
- `productId`
- `onHand`
- `reserved`
- `available`, if useful as a derived published value
- Operation-specific information where required by consumers

The published quantities must represent the resulting committed Inventory state, not merely the requested operation.

Consumers must not need to reconstruct the authoritative stock state by making assumptions about the operation.

The event envelope must follow ADR 004, including stable `eventId`, event name, event contract version, aggregate identity and occurrence timestamp.

### 8.3. Aggregate versions

Do not assume that aggregate versioning already exists in the Inventory domain.

If sequential aggregate versions are required for these events:

- The version must be generated consistently with StockItem changes.
- The version and corresponding Outbox record must be persisted atomically with the business change.
- A failed transaction must not consume a committed version.
- The producer must guarantee monotonicity before consumers rely on it.

If the existing domain and persistence contracts do not support aggregate versions, this must be recorded as an open decision rather than silently inventing a version.

### 8.4. Outbox transaction

The Inventory business change and corresponding Outbox record must be written within the same Inventory transaction.

The task must integrate with Task 03's Outbox mechanism.

It must not:

- Implement another generic publisher.
- Publish directly to RabbitMQ from the aggregate or use case.
- Write to another bounded context's Outbox.
- Treat the Outbox as an event store.
- Publish a success event when the Inventory transaction rolls back.

The Outbox integration mechanism must preserve Inventory ownership of event creation and persistence.

## 9. Application and Domain Boundaries

Task 01 defines the Inventory domain and application use cases.

This task should not redesign those contracts unnecessarily.

If persistence requirements expose a missing application-level capability, document the gap and propose the smallest change.

Potential areas requiring validation include:

- How application use cases coordinate transaction boundaries.
- How the repository distinguishes new and existing aggregates.
- How a reservation request involving multiple StockItems is represented.
- How an operation's resulting domain events are made available for integration-event mapping.
- How retryable persistence failures are distinguished from domain failures.

Any change to Task 01 contracts must be reviewed before implementation.

The Domain must not acquire:

- Eloquent models.
- Laravel database facades.
- MySQL locking statements.
- RabbitMQ or Messenger dependencies.
- Outbox persistence classes.
- Integration-event transport dependencies.

## 10. Proposed Structure

The following structure is a proposal and must be validated against the actual project conventions and Task 01 implementation before planning.

```text
src/
└── Inventory/
    ├── Domain/
    │   ├── StockItem.php
    │   ├── StockReservation.php
    │   ├── StockItemRepository.php
    │   ├── ValueObjects/
    │   └── Exceptions/
    ├── Application/
    │   ├── RegisterInitialStock/
    │   ├── AddStock/
    │   ├── ReserveStock/
    │   └── ReleaseStock/
    └── Infrastructure/
        └── Persistence/
            ├── Eloquent/
            │   ├── Models/
            │   └── Mappers/
            └── Repositories/
```

Transaction and Outbox adapters must be placed according to the existing project conventions and the responsibilities established by Task 03.

Proposed migrations:

```text
database/
└── migrations/
    ├── create_inventory_stock_items_table.php
    └── create_inventory_stock_reservations_table.php
```

Proposed integration tests:

```text
tests/
└── Integration/
    └── Inventory/
        └── Infrastructure/
            └── Persistence/
```

The actual filenames and directory organization must be confirmed during implementation planning.

## 11. Files to Create or Modify

The implementation plan must identify exact files after inspecting Task 01 and the Outbox implementation.

Expected categories include:

### New files

- StockItems migration.
- StockReservations migration.
- Eloquent StockItem model.
- Eloquent StockReservation model, if required by the chosen mapping.
- StockItem persistence mapper.
- Reservation persistence mapper, if separate mapping is justified.
- StockItem repository adapter.
- Inventory transaction adapter, if no suitable adapter exists.
- Inventory integration-event mapping or recording adapter, following Task 03.
- Persistence and concurrency integration tests.

### Existing files to modify

Only files required to:

- Register or bind the Inventory repository adapter.
- Register the transaction adapter.
- Integrate Inventory event recording with the Outbox mechanism.
- Configure required Infrastructure services.

### Existing files to reuse

- Inventory Domain and Application contracts from Task 01.
- Shared event and UUID contracts from Task 02.
- Outbox technical components from Task 03.
- Existing database and test configuration.

No existing file should be modified merely for stylistic consistency or unrelated refactoring.

## 12. Testing Strategy

Tests must use the established test structure and actual MySQL infrastructure for persistence guarantees.

### 12.1. Repository integration tests

Test:

- Saving a new StockItem.
- Retrieving a StockItem by its UUID.
- Retrieving a StockItem by ProductId.
- Enforcing one StockItem per ProductId.
- Persisting and reconstructing reservations.
- Reconstructing active and released reservation states.
- Looking up the owning StockItem by ReservationId.
- Returning `null` when the StockItem or reservation does not exist.
- Preserving the aggregate's counters and reservation consistency after reload.

### 12.2. Transaction tests

Test:

- StockItem changes commit successfully.
- Reservation creation and reserved-counter changes commit together.
- Reservation release and reserved-counter changes commit together.
- A failure during persistence rolls back all related changes.
- A failure during Outbox persistence rolls back Inventory business changes.
- An Outbox record cannot commit without its corresponding Inventory business change.
- A rolled-back operation does not publish a success integration event.

### 12.3. Concurrency tests

Use separate database connections or independent transactions against real MySQL.

Test:

- Two concurrent reservations competing for the remaining stock.
- The total reserved quantity never exceeds on-hand quantity.
- A reservation exceeding current availability is rejected.
- Concurrent additions and reservations preserve the aggregate invariant.
- Concurrent release attempts cannot release the same active reservation twice.
- Multiple StockItems are locked in deterministic order when one operation affects more than one product.
- Deadlock retry behavior does not create duplicate reservations or Outbox records.

Tests must verify the resulting persisted state, not only the returned application response.

### 12.4. Integration event tests

Test:

- The correct Inventory event is created for each supported stock operation.
- Event payload quantities represent the resulting committed state.
- Event identifiers remain stable for the persisted Outbox record.
- Event contract versions follow the approved contract.
- Events and business changes are persisted atomically.
- Failed transactions leave no publishable success event.

### 12.5. Regression tests

Run:

- Inventory domain unit tests from Task 01.
- Inventory application tests from Task 01.
- Existing shared domain-event tests.
- Relevant Outbox tests from Task 03.
- The full applicable test suite.

No test may be reported as passing until it has actually been executed.

## 13. Acceptance Criteria

- [ ] Inventory stock persistence is implemented in its own Infrastructure layer.
- [ ] StockItems and StockReservations have dedicated migrations.
- [ ] One StockItem per ProductId is enforced by a database unique constraint.
- [ ] StockItem identifiers and ProductIds use the approved UUID representation.
- [ ] Reservation records remain owned by StockItem.
- [ ] The repository persists and reconstructs complete StockItem aggregates.
- [ ] The repository can locate a StockItem by ReservationId.
- [ ] Active and released reservation states are persisted correctly.
- [ ] StockItem changes and reservation changes are committed atomically.
- [ ] Concurrent stock operations are protected by appropriate row locking.
- [ ] Multi-product reservation operations roll back completely when any requested reservation fails.
- [ ] Database deadlocks and retryable failures have an explicit bounded retry policy.
- [ ] Inventory integration events represent committed business facts.
- [ ] Inventory business changes and corresponding Outbox records are committed in the same transaction.
- [ ] The implementation reuses Task 03 Outbox infrastructure instead of creating another publisher.
- [ ] No RabbitMQ network I/O occurs inside the Inventory transaction.
- [ ] MySQL integration tests verify persistence, rollback and concurrency behavior.
- [ ] Existing Inventory domain and application tests continue to pass.
- [ ] No Orders or Catalog persistence is accessed.
- [ ] No cross-context transaction, Event Sourcing, warehouse support or generic workflow engine is introduced.

## 14. Risks and Open Decisions

The following points must be resolved or explicitly documented during implementation planning.

### 14.1. Task 01 compatibility

The actual domain and application implementation must be inspected before finalizing repository and transaction adapter contracts.

If the domain contract differs from this task, the implementation plan must identify the required adjustment rather than silently changing the domain.

### 14.2. Aggregate versioning

ADR 004 permits aggregate versions when supported by the producer.

The Inventory implementation must decide whether sequential StockItem versions are required for the initial integration-event consumers.

If versioning is introduced, its persistence and transaction semantics must be explicit.

### 14.3. Reservation idempotency

The domain foundation defines reservation identity and release behavior, but the cross-context confirmation workflow will require a robust idempotency contract.

The Inventory reservation operation must not silently create duplicate reservations when a request is retried.

The exact request identity and retry semantics must be decided before Inventory is used by the future Orders confirmation workflow.

This task must not invent a cross-context idempotency key without an approved contract.

### 14.4. Multi-product reservation API

The domain and application foundation must be reviewed to confirm how one Inventory operation represents a request involving multiple StockItems.

If the current use cases only support one product per invocation, the required atomic multi-product operation must be designed before implementing the future order-confirmation workflow.

### 14.5. Initial stock event

The event catalogue must decide whether registering initial stock publishes a distinct `StockRegistered` event or uses another explicitly defined contract.

Consumers must not infer initialization behavior from an unrelated event.

### 14.6. Database constraints

MySQL version and supported constraint behavior must be verified before relying on database-level checks.

Application and domain validation remain necessary even when database constraints are available.

### 14.7. Transaction abstraction

The existing project must be inspected for a reusable transaction abstraction.

Do not introduce a generic Unit of Work framework unless the implementation demonstrates a concrete need.

### 14.8. ADR 002 — Order confirmation coordination

This task implements Inventory-owned stock transactions only.

It does not resolve atomicity between Orders persistence and Inventory reservations.

That cross-context coordination decision remains open and must be resolved before implementing order confirmation.

## 15. Out of Scope and Future Work

The following capabilities belong to later tasks:

- Orders domain and application foundation.
- Order confirmation and cross-context reservation coordination.
- Orders Inventory availability projection.
- Inbox consumers and projection updates.
- Catalog product management.
- Reservation expiration.
- Multiple warehouses and stock locations.
- Stock transfers.
- Inventory reporting and analytics.
- Projection reconciliation and monitoring.

The implementation must provide a reliable Inventory persistence foundation without prematurely introducing those capabilities.
