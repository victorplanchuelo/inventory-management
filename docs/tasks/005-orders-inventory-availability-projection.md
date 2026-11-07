# Task 05 — Orders Inventory Availability Projection

**Status:** Proposed  
**Scope:** Orders bounded context  
**Phase:** Cross-Context Read Models

## 1. Objective

Implement an Orders-owned projection of Inventory availability information.

The projection will provide a local, queryable view of stock availability without requiring Orders API queries to access Inventory tables or repositories.

The projection will be maintained asynchronously by consuming Inventory integration events.

It will provide advisory information only. It must never replace the authoritative stock validation and reservation performed by Inventory when an order is confirmed.

## 2. Architectural Principles

The implementation must follow these principles:

- Inventory remains the owner of physical stock and reservations.
- Orders owns its local Inventory availability projection.
- Orders must not query Inventory database tables directly.
- Projection updates are asynchronous and eventually consistent.
- The projection is a read model, not an authoritative source of stock.
- Order confirmation must synchronously reserve stock through Inventory's application boundary.
- Inventory must enforce stock invariants within its own database transaction.
- Projection consumers must use the Inbox mechanism for idempotent processing.
- No cross-context transaction is allowed.

## 3. Scope

### In scope

- Define the Orders-side Inventory availability projection.
- Consume relevant Inventory integration events.
- Persist projection data within Orders-owned infrastructure.
- Apply events idempotently through Inbox.
- Handle aggregate versions where available.
- Expose a query API for availability information.
- Define projection initialization and reconciliation requirements.
- Add unit, integration and API tests.

### Out of scope

- Inventory aggregate implementation.
- Inventory stock reservation implementation.
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
- Inventory integration events.

Inventory is the only authority allowed to confirm whether stock can actually be reserved.

### Orders

Orders owns:

- The local availability projection.
- Projection persistence.
- Inventory event consumers.
- Projection query ports.
- API endpoints exposing the local read model.

Orders must not modify Inventory stock through its projection.

## 5. Projection Model

The initial projection represents the latest known stock availability for a product.

The proposed model is:

**InventoryAvailabilityProjection**

| Field | Purpose |
|---|---|
| `product_id` | UUID identifying the product |
| `stock_item_id` | UUID identifying the Inventory StockItem |
| `on_hand` | Latest known physical stock |
| `reserved` | Latest known reserved quantity |
| `available` | Latest known available quantity |
| `aggregate_version` | Latest processed Inventory aggregate version |
| `updated_at` | Last projection update timestamp |

The initial model assumes one StockItem per ProductId, as agreed for the first version.

The projection must preserve the distinction between physical stock, reserved stock and available stock.

The invariant is:

`available = on_hand - reserved`

The projection must not calculate availability using order status or assumptions about pending orders.

The values represent the latest successfully processed Inventory information, not necessarily the current authoritative stock.

## 6. Integration Events

The projection will consume Inventory integration events.

The initial candidate events are:

- `StockAdded`
- `StockReserved`
- `StockReleased`

The final event payloads must be defined according to ADR 004.

Events must provide enough information to update the projection without accessing Inventory persistence.

At minimum, the consumer needs:

- Stable `eventId`.
- `eventName`.
- Event contract version.
- `aggregateId`.
- `aggregateVersion`, when guaranteed by the producer.
- `productId`.
- Updated stock quantities or sufficient information to derive the new projection state.

Prefer publishing the resulting stock quantities rather than requiring consumers to reconstruct business state from assumptions about individual operations.

The exact event payload must be finalized before implementation.

## 7. Projection Processing

The Orders consumer must:

1. Receive an Inventory integration event.
2. Validate its envelope and supported version.
3. Check whether its `eventId` has already been processed.
4. Validate aggregate-version behaviour where available.
5. Update the Orders-owned projection.
6. Record the event in the Orders Inbox.
7. Commit the projection update and Inbox record in the same database transaction.
8. Acknowledge RabbitMQ only after successful transaction completion.

If processing fails, the transaction must roll back and the message must follow the configured retry policy.

A duplicate event must not repeat the projection update.

## 8. Event Ordering

The projection must not be overwritten by stale information.

When Inventory guarantees sequential aggregate versions:

- The consumer must identify the latest successfully processed version.
- Older versions must not overwrite newer projection state.
- A missing predecessor must follow the agreed event-ordering policy.
- The consumer must not invent aggregate versions.

If aggregate versions are unavailable, the consumer must not claim ordering guarantees that do not exist.

The chosen strategy must be consistent with Task 04 and ADR 004.

## 9. API Query

Orders will expose a read endpoint for the local availability projection.

The exact route and response contract must follow the existing API conventions.

The response should communicate:

- Product identifier.
- Latest known physical stock.
- Latest known reserved quantity.
- Latest known available quantity.
- Timestamp of the projection update.

The endpoint must make the advisory nature of the information clear.

A missing projection must have an explicit response policy. It must not be interpreted as proof that no stock exists.

The endpoint must not query Inventory repositories or tables to fill missing projection data.

## 10. Order Confirmation and Authoritative Stock

The projection must never be used to authorize an order confirmation.

When an order is confirmed:

1. Orders invokes the Inventory application boundary to reserve the required stock.
2. Inventory loads and locks the authoritative StockItem record.
3. Inventory validates the current available quantity.
4. Inventory creates the reservation and updates its stock counters atomically.
5. The operation commits or rolls back as one Inventory transaction.
6. Orders proceeds according to the result of the reservation operation.

The authoritative operation must protect against concurrent confirmations.

A projection showing sufficient availability does not guarantee that stock is still available.

If Inventory rejects the reservation, Orders must not confirm the order.

No distributed transaction between Orders and Inventory is introduced by this flow.

## 11. Projection Initialization and Reconciliation

The initial projection must have a defined initialization strategy.

Possible approaches include:

- A controlled snapshot supplied by Inventory.
- A dedicated synchronization process.
- A documented initial data import.

The implementation must not assume that historical Outbox records are permanently available for replay.

Reconciliation must be possible without directly modifying Inventory data from Orders.

The initial implementation may provide a controlled synchronization procedure rather than a generic projection rebuild framework.

## 12. Testing Strategy

Tests must follow the established project test structure.

### Unit tests

Test:

- Projection creation.
- Projection quantity invariants.
- Stock quantity updates.
- Aggregate-version decisions.
- Stale-event handling.
- Missing projection behaviour.
- Projection query results.

### Integration tests

Test:

- Inventory events update the Orders projection.
- Inbox and projection changes commit atomically.
- Failed processing rolls back projection changes.
- Duplicate events do not apply updates twice.
- Stale aggregate versions cannot overwrite newer values.
- Missing predecessors follow the documented policy.
- RabbitMQ acknowledgement occurs after successful processing.

### API tests

Test:

- Availability endpoint returns the local projection.
- Missing projections follow the documented response policy.
- Response fields are correct.
- API queries do not access Inventory persistence.
- The response communicates that availability is advisory.

### Order confirmation tests

Verify that:

- A sufficient projection does not bypass authoritative Inventory validation.
- Inventory rejects confirmation when authoritative stock is insufficient.
- Concurrent confirmations cannot reserve more stock than is available.
- Failed reservation prevents order confirmation.

## 13. Acceptance Criteria

- [ ] Orders owns the Inventory availability projection.
- [ ] Inventory remains the authoritative owner of physical stock and reservations.
- [ ] Projection data is maintained through Inventory integration events.
- [ ] Projection updates are idempotent.
- [ ] Inbox and projection updates are committed atomically.
- [ ] Stale events cannot overwrite newer projection state where version guarantees exist.
- [ ] Missing predecessor behaviour is explicitly defined.
- [ ] Orders exposes an API query backed by its local projection.
- [ ] Orders does not query Inventory tables or repositories directly.
- [ ] Missing projection data is handled explicitly.
- [ ] The API communicates the advisory nature of availability.
- [ ] Order confirmation always performs authoritative Inventory reservation.
- [ ] Inventory protects stock invariants under concurrent reservations.
- [ ] Projection initialization and reconciliation are documented.
- [ ] Unit, integration and API tests cover the required behaviour.
- [ ] No generic projection engine or distributed transaction is introduced.

## 14. Dependencies

This task depends on:

- Task 02 — Shared Domain Event Compatibility and Test Structure.
- Task 03 — Transactional Outbox and Integration Infrastructure.
- Task 04 — Inbox, Idempotent Consumers and Event Ordering.
- ADR 002 — Atomic Stock Reservation.
- ADR 004 — Integration Events and Cross-Context Communication.
- ADR 005 — Cross-Context Projections.

The Inventory StockItem foundation must also exist before its integration events can be produced.

## 15. Follow-up Work

Future work may introduce:

- Catalog product projections.
- Additional Orders read models.
- Inventory reconciliation tooling.
- Projection freshness monitoring.
- Controlled projection rebuild procedures.

These improvements must preserve bounded-context ownership and must not turn the initial implementation into a generic CQRS framework.
