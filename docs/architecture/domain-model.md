# Domain Model — StockFlow

This document describes the current conceptual domain model for StockFlow. It identifies the main aggregates, entities, Value Objects, responsibilities and business invariants.

Some design details remain proposals and must be validated through task specifications, implementation plans and tests before being treated as final decisions.

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

Candidate concepts:

- `Order`: Aggregate Root.
- `OrderId`: order identity.
- `OrderItem`: order line, containing a product reference and requested quantity.
- `ProductId`: reference to a product owned by Catalog, or an opaque identifier if Catalog is not yet implemented.
- `Quantity`: positive quantity Value Object.
- `OrderStatus`: explicit order lifecycle state.
- `OrderRepository`: persistence port required by the Application layer.

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

**Responsibility:** protect stock quantities and availability for a product.

Candidate concepts:

- `StockItem`: Aggregate Root.
- `StockItemId`: stock item identity.
- `ProductId`: reference to the product whose stock is managed.
- `Quantity`: quantity Value Object.
- `StockReservation`: reservation record or entity.
- `StockRepository`: persistence port required by the Application layer.

Inventory owns stock information, not product descriptions or catalog metadata.

### 3.2. Stock Quantities

The conceptual stock relationship is:

`available = on_hand - reserved`

Where:

- `on_hand` represents the physical quantity currently held.
- `reserved` represents the quantity committed to active reservations.
- `available` represents the quantity that can still be reserved.

Candidate invariants:

- Available stock must never be negative.
- Reserved quantity must never exceed on-hand quantity.
- Adding stock requires a positive quantity.
- A reservation must not exceed available stock.
- Concurrent operations must not over-reserve stock.
- Releasing a reservation must not release it more than once.

The persistence strategy for `reserved` remains to be finalized.

Two possible approaches are:

1. Store `reserved` as a counter on `StockItem`, while maintaining reservation records and updating both atomically.
2. Derive `reserved` from active reservation records.

The first approach is recommended for efficient stock operations, provided all updates are protected by the same transaction and locking strategy. The final decision must be recorded in the Inventory implementation specification.

### 3.3. Stock Reservation

A reservation represents stock committed to an order.

Candidate concepts:

- `ReservationId`: reservation identity.
- `StockItemId`: the stock item being reserved.
- `OrderId`: the order requesting the reservation.
- `Quantity`: reserved quantity.
- `ReservationStatus`: reservation lifecycle state, such as `Active` or `Released`.

A reservation may be modeled as an entity or a dedicated persistence record, depending on the final aggregate design.

Candidate invariants:

- A reservation must reference a valid stock item.
- The reserved quantity must be positive.
- A reservation must not exceed the available quantity.
- Only an active reservation can be released.
- Releasing a reservation must happen at most once.
- Reservation records and stock counters must remain consistent.

The exact lifecycle, identity and aggregate ownership of reservations must be defined in the Inventory task specification.

### 3.4. Stock Operations

The Inventory context must support the following business capabilities:

**Register initial stock**

- Create stock for a product with an initial quantity.
- Reject invalid quantities.
- Prevent unintended duplicate stock registration.

Whether there must be exactly one `StockItem` per `ProductId` is still an open design decision. A unique constraint should only be introduced after confirming this business rule.

**Add stock**

- Increase physical stock by a positive quantity.
- Preserve stock invariants.

**Reserve stock**

- Reserve a positive quantity when sufficient stock is available.
- Reject a reservation that exceeds available stock.
- Protect the operation against concurrent over-reservation.

**Release stock**

- Release an active reservation.
- Prevent repeated releases from increasing availability more than once.

**Retrieve stock**

- Provide on-hand, reserved and available quantities through context-owned query interfaces.

### 3.5. Candidate Domain Events

- `StockAdded`
- `StockReserved`
- `StockReleased`

The final event payloads and publication requirements must be specified before implementation.

## 4. Coordination Between Orders and Inventory

Order confirmation requires coordination between the Orders and Inventory contexts.

The confirmation use case is responsible for coordinating the operation.

The expected consistency boundary is one MySQL transaction covering:

1. Loading and validating the order.
2. Reserving stock for all required order lines.
3. Persisting reservation records and stock changes.
4. Confirming the order.
5. Recording the required Outbox messages.

If any reservation fails, the transaction must roll back all changes.

The reservation operation must use Inventory's authoritative state and the deterministic locking strategy defined in ADR 002.

The following rules apply:

- Orders must not access Inventory tables directly.
- Inventory must not depend on the Orders aggregate.
- `Order` must not reference `StockItem` as an internal entity.
- Coordination belongs to the Application use case and its required ports.
- Inventory availability projections must never be used as the authority for reservation decisions.

The exact transaction abstraction and cross-context application ports must be defined in the implementation plan.

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

Candidate identifiers:

- `OrderId`
- `ProductId`
- `StockItemId`
- `ReservationId`

Rules:

- Identifiers must encapsulate their own validation and behavior.
- Domain identifiers must not be coupled unnecessarily to database column types.
- UUID or ULID strategies may be used where appropriate.
- A context must not assume that identifiers are integers.
- References to another context's entities must use identifiers rather than direct object references.
- Value Objects must validate their primitive values during construction.
- `fromPrimitives()` must explicitly reconstruct Value Objects.
- `toPrimitives()` must return primitives, not Value Objects.
- Mutable internal state must not be exposed.

The identifier strategy for each aggregate and the compatibility of existing Shared contracts must be reviewed before implementation.

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
- Order confirmation and all stock reservations must be atomic.
- Outbox messages must be persisted in the same transaction as the business change they represent.
- Inbox records and local projection updates must be persisted atomically.
- Duplicate messages must not repeat local effects.
- Out-of-order messages must follow an explicit consumer policy.
- Projections may temporarily lag behind their source contexts.
- Projection data must not be treated as authoritative for write decisions.

Concurrency protection for stock operations must follow ADR 002, including deterministic lock ordering and appropriate transaction handling.

## 10. Open Design Decisions

The following points require explicit resolution in the relevant task specifications or ADRs:

1. Whether `reserved` is stored on `StockItem` or derived from active reservations.
2. Whether one `StockItem` must exist per `ProductId`.
3. Whether `StockReservation` is an entity within `StockItem` or a separate persistence model with its own lifecycle.
4. The final order lifecycle states and permitted transitions.
5. Whether order lines retain product descriptive snapshots.
6. The exact integration event payloads, versions and publication requirements.
7. The aggregate versioning strategy for detecting out-of-order messages.
8. The initialization and reconciliation strategy for each projection.
9. The exact transaction and application port abstractions for cross-context coordination.
10. The timing and scope of introducing Catalog.

These decisions must not be silently assumed during implementation.

## 11. Scope and Evolution

This is the conceptual domain model for the initial StockFlow implementation.

It does not require all candidate contexts, events or projections to be implemented at once.

The initial implementation should prioritize the Inventory and Orders capabilities required by the accepted requirements and ADRs.

Catalog may be introduced when its scope and available time permit. Until then, `ProductId` remains a reference.

Any change to aggregate boundaries, business invariants or cross-context ownership must be reviewed and documented before implementation.
