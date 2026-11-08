# Task 05 — Orders Inventory Availability Projection

**Status:** Proposed  
**Scope:** Orders bounded context  
**Phase:** Cross-Context Read Models

## 1. Objective

Implement an Orders-owned projection of Inventory availability information.

The projection will provide a local, queryable view of stock information without requiring Orders API queries to access Inventory tables or repositories.

The projection will be maintained asynchronously by consuming Inventory integration events.

It is an advisory read model. Inventory remains the sole authority for physical stock and reservation decisions.

## 2. Architectural Principles

The implementation must follow these principles:

- Inventory owns physical stock, reservations and stock availability calculations.
- Orders owns its local Inventory availability projection.
- Orders must not query Inventory database tables or repositories directly.
- Projection updates are asynchronous and eventually consistent.
- The projection is not an authoritative source for enforcing Inventory invariants.
- Projection consumers must use the Inbox mechanism for idempotent processing.
- Inbox records and projection updates must be committed atomically within Orders persistence.
- No cross-context database transaction is introduced.
- Integration event contracts must follow ADR 004.
- Projection design and API behavior must follow ADR 005.

## 3. Scope

### In scope

- Define the Orders-side Inventory availability projection.
- Consume relevant Inventory integration events.
- Persist projection data within Orders-owned infrastructure.
- Apply events idempotently through Inbox.
- Handle aggregate versions where the producer guarantees them.
- Expose a query API for availability information.
- Define projection initialization and reconciliation requirements.
- Add unit, integration and API tests.

### Out of scope

- Inventory aggregate implementation.
- Inventory persistence and stock reservation implementation.
- Orders aggregate and order lifecycle implementation.
- Order confirmation and Inventory reservation coordination.
- Decisions about atomicity or recovery across Orders and Inventory business operations.
- Catalog implementation.
- Generic projection framework.
- Event Sourcing.
- Direct cross-context database queries.
- Distributed transactions.
- Automatic projection reconstruction from Outbox records.

## 4. Ownership and Responsibilities

### Inventory

Inventory owns:

- StockItem aggregates.
- Physical stock quantities.
- Active reservations.
- Stock availability calculations.
- Stock reservation and release operations.
- Inventory integration events describing committed stock changes.

Inventory is the authority for the business facts represented by these events.

### Orders

Orders owns:

- The local availability projection.
- Projection persistence.
- Inventory event consumers.
- Projection query ports.
- API endpoints exposing the local read model.

Orders must not modify Inventory stock through its projection or use projected values to enforce Inventory business invariants.

## 5. Projection Model

The initial projection represents the latest known stock state for a product.

Proposed model:

**InventoryAvailabilityProjection**

| Field | Purpose |
|---|---|
| `product_id` | UUID identifying the product |
| `stock_item_id` | UUID identifying the Inventory StockItem |
| `on_hand` | Latest known physical stock |
| `reserved` | Latest known reserved quantity |
| `aggregate_version` | Latest processed Inventory aggregate version, when guaranteed |
| `source_occurred_at` | Time when the source business event occurred |
| `updated_at` | Time when the local projection was updated |

The initial model assumes one StockItem per ProductId.

Available quantity is derived:

`available = on_hand - reserved`

It should not be stored independently unless a verified query or performance requirement justifies doing so.

The projection must preserve the distinction between physical stock, reserved stock and available stock.

The values represent the latest successfully processed Inventory information, not necessarily the current authoritative stock.

## 6. Integration Events

The projection will consume Inventory integration events.

Initial candidate events:

- `StockAdded`
- `StockReserved`
- `StockReleased`

The final event contracts must follow ADR 004 and be defined by Inventory before implementation.

Events must provide enough information to update the projection without accessing Inventory persistence.

At minimum, the consumer needs:

- Stable `eventId`.
- `eventName`.
- Event contract version.
- `aggregateId`.
- `aggregateVersion`, when guaranteed by the producer.
- `productId`.
- Resulting stock quantities, or sufficient information to derive the new projection state reliably.

Prefer publishing the resulting stock quantities rather than requiring consumers to reconstruct business state from assumptions about individual operations.

The exact payloads and version guarantees must be agreed before implementation. The consumer must not assume that a candidate event name or field is already part of an implemented contract.

## 7. Projection Processing

The Orders consumer must:

1. Receive an Inventory integration event.
2. Validate its envelope and supported contract version.
3. Check whether its `eventId` has already been processed by this consumer.
4. Validate aggregate-version behavior where the producer guarantees versioning.
5. Update the Orders-owned projection.
6. Record the event in the Orders Inbox.
7. Commit the projection update and Inbox record in the same local database transaction.
8. Acknowledge RabbitMQ only after successful transaction completion.

If processing fails, the transaction must roll back and the message must follow the configured retry and failure-handling policy.

A duplicate event must not repeat the projection update.

The consumer must not call Inventory repositories or access Inventory tables to complete ordinary event processing.

## 8. Event Ordering

The projection must not be overwritten by stale information.

When Inventory guarantees sequential aggregate versions:

- The consumer must identify the latest successfully processed version.
- Older versions must not overwrite newer projection state.
- Missing predecessor behavior must follow the policy defined by Task 04 and ADR 004.
- The consumer must not invent aggregate versions.
- Version gaps must be interpreted according to the events subscribed to; a gap is not automatically evidence of message loss.

If aggregate versions are unavailable, the consumer must not claim ordering guarantees that do not exist.

The chosen strategy must be deterministic and covered by tests.

## 9. API Query

Orders will expose a read endpoint for the local availability projection.

The exact route and response contract must follow existing API conventions.

The response should communicate:

- Product identifier.
- Latest known physical stock.
- Latest known reserved quantity.
- Latest known available quantity, derived from the projected values.
- Source event timestamp.
- Local projection update timestamp, where useful.

The endpoint must make the advisory and eventually consistent nature of the information clear.

A missing projection must have an explicit response policy. It must not be interpreted as proof that no stock exists.

The endpoint must not query Inventory repositories or tables to fill missing projection data.

## 10. Projection Initialization and Reconciliation

The initial projection must have a defined initialization strategy.

Possible approaches include:

- A controlled snapshot supplied by Inventory.
- A dedicated synchronization process.
- A documented initial data import.

The selected approach must define how the snapshot and incoming events are coordinated so that changes occurring during initialization are not silently missed.

The implementation must not assume that historical Outbox records are permanently available for replay.

Reconciliation must be possible without modifying Inventory data from Orders.

The initial implementation may provide a controlled synchronization procedure rather than a generic projection rebuild framework.

The source of truth for reconciliation remains Inventory.

## 11. Testing Strategy

Tests must follow the established project test structure.

### Unit tests

Test:

- Projection creation.
- Projection quantity validation.
- Available quantity derivation.
- Stock quantity updates.
- Aggregate-version decisions.
- Stale-event handling.
- Missing projection behavior.
- Projection query results.

### Integration tests

Test:

- Inventory events update the Orders projection.
- Inbox and projection changes commit atomically.
- Failed processing rolls back projection changes and Inbox records.
- Duplicate events do not apply updates twice.
- Stale aggregate versions cannot overwrite newer values.
- Missing predecessors follow the documented policy.
- RabbitMQ acknowledgement occurs after successful transaction completion.

### API tests

Test:

- Availability endpoint returns the local projection.
- Missing projections follow the documented response policy.
- Response fields and derived availability are correct.
- API queries do not access Inventory persistence.
- The response communicates that availability is advisory and eventually consistent.

### Initialization and reconciliation tests

Test:

- Initial synchronization creates the expected projection state.
- Events occurring during initialization are not silently lost.
- Reconciliation updates the local projection without modifying Inventory data.
- The procedure does not depend on permanent Outbox history.

Order confirmation and authoritative reservation tests belong to a separate task.

## 12. Acceptance Criteria

- [ ] Orders owns the Inventory availability projection.
- [ ] Inventory remains the authoritative owner of physical stock and reservations.
- [ ] Projection data is maintained through Inventory integration events.
- [ ] The event contracts and supported versions are explicit.
- [ ] Projection updates are idempotent.
- [ ] Inbox and projection updates are committed atomically within Orders.
- [ ] Stale events cannot overwrite newer projection state where version guarantees exist.
- [ ] Missing predecessor behavior is explicitly defined.
- [ ] Orders exposes an API query backed by its local projection.
- [ ] Orders does not query Inventory tables or repositories directly.
- [ ] Missing projection data is handled explicitly.
- [ ] Available quantity is derived consistently from projected quantities.
- [ ] The API communicates the advisory and eventually consistent nature of availability.
- [ ] Projection initialization and reconciliation are documented and tested.
- [ ] Unit, integration and API tests cover the required behavior.
- [ ] No order confirmation or authoritative reservation behavior is implemented in this task.
- [ ] No generic projection engine or distributed transaction is introduced.

## 13. Dependencies

This task depends on:

- Task 02 — Shared Domain Event Compatibility and Test Structure.
- Task 03 — Transactional Outbox and Integration Infrastructure.
- Task 04 — Inbox, Idempotent Consumers and Event Ordering.
- Task 06 — Inventory Persistence and Stock Reservation (planned).
- ADR 004 — Integration Events and Cross-Context Communication.
- ADR 005 — Cross-Context Projections.

Task 06 is required because Inventory must be able to persist stock changes and publish corresponding integration events.

Implementation is blocked until the required Inventory event contracts and producer behavior are available.

ADR 002 is not a direct dependency of this projection task. Its order-confirmation transaction decision must be resolved separately before implementing order confirmation.

## 14. Follow-up Work

Future work may introduce:

- Orders domain and application foundation.
- Atomic order confirmation and Inventory coordination.
- Catalog product projections.
- Additional Orders read models.
- Inventory reconciliation tooling.
- Projection freshness monitoring.
- Controlled projection rebuild procedures.

These improvements must preserve bounded-context ownership and must not turn the initial implementation into a generic CQRS framework.
