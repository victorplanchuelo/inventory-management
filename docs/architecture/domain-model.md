# Domain Model — StockFlow

This document describes the conceptual domain model for StockFlow. It identifies the bounded contexts, aggregates, entities, Value Objects, responsibilities and business invariants.

It distinguishes agreed design decisions from aspects that remain to be specified in task documents or ADRs.

The model follows the modular monolith and bounded-context boundaries defined in the architecture documentation.

## 1. Bounded Contexts

The planned business capabilities are organized into the following bounded contexts:

- **Orders**: manages customer orders and their lifecycle.
- **Inventory**: manages physical stock, reservations and availability.
- **Catalog**: owns product information when this context is introduced.

Catalog is desirable but is not a prerequisite for the initial Inventory and Orders implementation.

If Catalog is not implemented initially, `ProductId` must be treated as an external reference. Inventory and Orders must not create duplicate Product aggregates or assume ownership of product information.

Each context owns its own domain model, persistence and business rules.

## 2. Orders Context

### 2.1. Aggregate: Order

**Responsibility:** preserve order consistency and enforce valid order state transitions.

Concepts:

- `Order`: Aggregate Root.
- `OrderId`: order identity.
- `OrderItem`: order line, containing a product reference and requested quantity.
- `ProductId`: reference to a product owned by Catalog, or an opaque identifier if Catalog is not yet implemented.
- `Quantity`: positive quantity Value Object for order lines.
- `OrderStatus`: explicit order lifecycle state.
- `OrderRepository`: Domain repository contract for the `Order` aggregate.

`OrderRepository` belongs to the Domain layer because it represents the collection and lifecycle of Order aggregates. The Application layer uses this contract to coordinate use cases, and Infrastructure provides its implementation.

Candidate states include:

- `Pending`
- `Confirmed`
- `Cancelled`

The final state model and permitted transitions must be specified before implementation.

### 2.2. Candidate Invariants

- An order must contain at least one line.
- Every order line must have a positive quantity.
- An order that has been cancelled cannot be confirmed.
- An order cannot be cancelled in a non-cancellable state.
- An order cannot be confirmed until all required stock reservations have succeeded.
- An order must not be considered confirmed when only some of its lines have been reserved.

The aggregate must protect its own state and invariants.

The use case coordinates the reservation process across Orders and Inventory. The `Order` aggregate must not hold a direct reference to an Inventory aggregate or access Inventory persistence.

### 2.3. Candidate Domain Events

- `OrderCreated`
- `OrderConfirmed`
- `OrderCancelled`

These names are candidates. The exact events, payloads and timing must be defined in the relevant task specification.

### 2.4. Order and Product Information

Orders refer to products through `ProductId`.

If Catalog is available, Orders may maintain a local product projection for read purposes, containing only the information needed by its use cases and API responses.

For example, a projection may contain:

- `ProductId`
- SKU
- Product name

This projection is owned by Orders and updated through Catalog integration events.

It is not a Product aggregate and must not become an alternative source of truth for Catalog.

The exact product information that must be retained on an order line, including whether descriptive values are snapshotted at order creation, remains to be specified.

## 3. Inventory Context

### 3.1. Aggregate: StockItem

**Responsibility:** protect stock quantities, reservations and availability for one product.

`StockItem` is the Aggregate Root and initially there is exactly one `StockItem` per `ProductId`.

It owns:

- `StockItemId`: UUID identifying the aggregate.
- `ProductId`: UUID reference to the product.
- `onHand`: physical stock quantity.
- `reserved`: quantity committed to active reservations.
- Its collection of `StockReservation` entities.

The aggregate maintains:

`0 <= reserved <= onHand`

### 3.2. Stock Quantities

The stock relationship is:

`available = onHand - reserved`

Where:

- `onHand` represents the physical quantity currently held.
- `reserved` represents the quantity committed to active reservations.
- `available` represents the quantity that can still be reserved.

`onHand` and `reserved` are maintained as aggregate state. `available` is calculated and is not an independent persisted counter.

The aggregate must preserve these invariants:

- `onHand` cannot be negative.
- `reserved` cannot be negative.
- `reserved` cannot exceed `onHand`.
- `available` cannot be negative.
- Adding stock requires a strictly positive quantity.
- A reservation requires a strictly positive quantity.
- A reservation must not exceed available stock.
- Releasing a reservation must not release it more than once.

The `Quantity` Value Object must support zero because stock counters and calculated availability can legitimately be zero. Operations that add or reserve stock must explicitly require a strictly positive quantity.

Concurrent operations must not over-reserve stock. The persistence and transaction strategy must protect the aggregate invariants.

### 3.3. Stock Reservation

A reservation represents stock committed to an order.

`StockReservation` is an Entity owned by the `StockItem` aggregate, not an independent Aggregate Root.

Concepts:

- `ReservationId`: reservation identity.
- `OrderId`: the order requesting the reservation.
- `Quantity`: reserved quantity.
- Reservation lifecycle state, including whether it is active or released.

The reservation does not have an independent repository. Its lifecycle and consistency are controlled by its owning `StockItem`.

The aggregate must enforce the following invariants:

- A reservation must have a unique identity.
- The reserved quantity must be strictly positive.
- A reservation must not exceed available stock.
- Only an active reservation can be released.
- Releasing a reservation must happen at most once.
- Reservation records and stock counters must remain consistent.

To release a reservation, the application must retrieve its owning `StockItem` through a repository operation such as:

`searchByReservationId(ReservationId): ?StockItem`

This operation must return the fully hydrated aggregate, including its reservation entities, so that the domain can validate and execute the release.

The release command therefore does not need a `ProductId`. The owning aggregate is identified through the reservation identifier.

### 3.4. Stock Operations

The Inventory context must support the following business capabilities.

**Register initial stock**

- Create a stock item for a product with an initial quantity.
- Reject invalid quantities.
- Prevent duplicate stock registration for the same `ProductId`.

**Add stock**

- Increase physical stock by a strictly positive quantity.
- Preserve stock invariants.

**Reserve stock**

- Reserve a strictly positive quantity when sufficient stock is available.
- Create a reservation associated with an order.
- Reject a reservation that exceeds available stock.
- Protect the operation against concurrent over-reservation.

**Release stock**

- Release an active reservation.
- Prevent repeated releases from increasing availability more than once.
- Update reservation state and stock counters consistently.

**Retrieve stock**

- Provide on-hand, reserved and available quantities through context-owned query interfaces.

### 3.5. Candidate Domain Events

- `StockAdded`
- `StockReserved`
- `StockReleased`

The final event payloads and publication requirements must be specified before implementation.

## 4. Coordination Between Orders and Inventory

Order confirmation requires coordination between the Orders and Inventory contexts.

The confirmation use case is responsible for coordinating the operation through the application boundaries of both contexts.

The following consistency rules apply:

- Inventory owns stock reservation and its persistence transaction.
- Orders owns order confirmation and its persistence transaction.
- Inventory must reserve all requested stock for an order atomically within its own transaction.
- An order must never reach the confirmed state unless Inventory has successfully reserved all required stock.
- Orders must not access Inventory tables directly.
- Inventory must not depend on the Orders aggregate.
- `Order` must not reference `StockItem` as an internal entity.
- Inventory availability projections must never be used as the authority for reservation decisions.
- Each context must persist its own Outbox records atomically with its local business changes.

A single transaction spanning both contexts must not be assumed.

The coordination protocol must define how failures between the Inventory reservation and Orders persistence operations are handled, including retries, idempotency, compensation and recovery.

This cross-context coordination remains an open architectural decision tracked by OD-001. It must be resolved before implementing order confirmation.

## 5. Cross-Context Projections

A projection is a local read model owned by the context that consumes external information.

Projections allow a context to answer queries without loading aggregates or querying persistence owned by another context.

### 5.1. Orders Product Projection

When Catalog is implemented, Orders may maintain a product projection containing the product information required by order-related queries.

Candidate fields:

- `ProductId`
- SKU
- Product name

The projection is updated through Catalog integration events.

It does not own or modify Catalog product information.

### 5.2. Orders Inventory Projection

Orders may maintain an inventory projection containing information needed for order-related reads.

Candidate fields:

- `ProductId`
- Available quantity.
- Reserved quantity.
- Last applied aggregate version, when supported by the producer contract.

This projection is advisory and eventually consistent.

It may be used to display availability or provide preliminary information, but it must never authorize an order reservation.

The authoritative reservation decision always belongs to Inventory.

### 5.3. Projection Responsibilities

Each consuming context owns:

- Its projection schema.
- Its projection update logic.
- Its integration event consumers.
- Its Inbox or equivalent idempotency mechanism.
- Its projection query interfaces.
- Its initialization and reconciliation strategy.

Projection updates and processed-message records must be committed atomically.

A context must not update another context's projection or read model directly.

## 6. Catalog Context

Catalog is a planned bounded context, subject to available implementation time.

### 6.1. Candidate Aggregate: Product

**Responsibility:** own product identity and descriptive information.

Candidate concepts:

- `Product`: Aggregate Root.
- `ProductId`: product identity.
- `SKU`: product stock-keeping identifier.
- Product name and other descriptive attributes.
- Product lifecycle state, if required.

Candidate domain events:

- `ProductCreated`
- `ProductUpdated`
- `ProductDiscontinued`

The final product attributes, lifecycle and invariants remain to be specified.

### 6.2. Context Boundaries

Catalog owns product information.

Inventory owns stock information.

Orders owns order information.

Other contexts may reference `ProductId` and maintain their own read projections, but must not create competing Product aggregates or modify Catalog's data.

If Catalog is deferred, the initial implementation must use `ProductId` as a reference without introducing a partial Catalog model inside Inventory or Orders.

## 7. Identifiers and Value Objects

Domain identifiers and quantities must use explicit types where appropriate.

Identifiers:

- `OrderId`
- `ProductId`
- `StockItemId`
- `ReservationId`

The initial implementation uses UUID identifiers.

Specific identifier Value Objects must be used for each domain concept, built on the shared UUID abstraction where appropriate.

Rules:

- Identifiers must encapsulate their own validation and behavior.
- Domain identifiers must not be coupled unnecessarily to database column types.
- A context must not assume that identifiers are integers.
- References to another context's entities must use identifiers rather than direct object references.
- Value Objects must validate their primitive values during construction.
- `fromPrimitives()` must explicitly reconstruct Value Objects.
- `toPrimitives()` must return primitives, not Value Objects.
- Mutable internal state must not be exposed.

`Quantity` must support zero and reject negative values. Operations requiring positive quantities must enforce that additional constraint explicitly.

## 8. Domain Events and Integration Events

Domain events represent facts that occurred within their owning context.

Integration events represent explicit contracts published to other contexts.

The two concepts must remain separate.

Rules:

- Not every domain event must become an integration event.
- Integration contracts must not expose internal domain classes.
- Integration events must contain only the information required by consumers.
- Event names and versions must be explicit.
- Aggregate identifiers must use a transport-independent representation.
- Events must not contain secrets or sensitive information.
- Integration messages must be recorded through the producer's transactional Outbox.
- Consumers must handle duplicate delivery through an Inbox or equivalent mechanism.

Candidate integration events include:

| Producer | Candidate integration event |
|---|---|
| Catalog | `ProductCreated` |
| Catalog | `ProductUpdated` |
| Catalog | `ProductDiscontinued` |
| Inventory | `StockAdded` |
| Inventory | `StockReserved` |
| Inventory | `StockReleased` |
| Orders | `OrderConfirmed` |
| Orders | `OrderCancelled` |

These are candidate contracts, not a finalized publication requirement. Each event's consumers, payload, version and timing must be defined in the corresponding task specification.

## 9. Consistency and Concurrency

StockFlow must distinguish between authoritative domain state and eventually consistent read models.

The following consistency rules apply:

- Aggregate invariants must be enforced by the owning context.
- Stock reservation must use authoritative Inventory state.
- Inventory must reserve all stock required by an order atomically within its own transaction.
- An order must not be confirmed unless all required stock has been successfully reserved.
- Cross-context coordination must follow the explicitly approved protocol defined for OD-001.
- Outbox messages must be persisted in the same transaction as the business change they represent.
- Inbox records and local projection updates must be persisted atomically.
- Duplicate messages must not repeat local effects.
- Out-of-order messages must follow an explicit consumer policy.
- Projections may temporarily lag behind their source contexts.
- Projection data must not be treated as authoritative for write decisions.

Concurrency protection for stock operations must follow ADR 002, including deterministic lock ordering and appropriate transaction handling.

## 10. Open Design Decisions

The following points require explicit resolution in the relevant task specifications or ADRs:

1. The final order lifecycle states and permitted transitions.
2. Whether order lines retain product descriptive snapshots.
3. The exact integration event payloads, versions and publication requirements.
4. The aggregate versioning strategy for detecting out-of-order messages.
5. The initialization and reconciliation strategy for each projection.
6. The exact transaction abstraction and application-level ports for cross-context coordination.
7. The timing and scope of introducing Catalog.

The following decisions are already established and must not be reopened without an explicit architecture review:

- One `StockItem` per `ProductId`, enforced by a unique constraint.
- `StockItem` owns its `StockReservation` entities.
- `onHand` and `reserved` are aggregate state; `available` is calculated.
- `Quantity` is non-negative, while stock addition and reservation operations require positive quantities.
- A reservation is released by retrieving its owning `StockItem` using `ReservationId`.
- There is no independent Reservation repository.
- `StockItemRepository` and `OrderRepository` are Domain contracts representing their respective aggregate repositories.
- Repository implementations belong to Infrastructure.

## 11. Scope and Evolution

This is the conceptual domain model for the initial StockFlow implementation.

It does not require all candidate contexts, events or projections to be implemented at once.

The initial implementation should prioritize the Inventory and Orders capabilities required by the accepted requirements and ADRs.

Catalog may be introduced when its scope and available time permit. Until then, `ProductId` remains a reference.

Any change to aggregate boundaries, business invariants or cross-context ownership must be reviewed and documented before implementation.
