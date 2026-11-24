# Inventory Integration Events

## 1. Purpose

This document defines the public integration-event catalogue published by the Inventory bounded context.

It records the event names, envelope, payloads, mapping from Inventory domain events and delivery guarantees implemented by Task006. It is the reference consumers must use instead of depending on Inventory domain classes or persistence.

## 2. Ownership and Producer

Inventory owns the meaning, payload and publication of these events.

- Outbox producer: `inventory`.
- Aggregate type: `stock_item`.
- Contract version: `1`.
- Publication mechanism: the shared Transactional Outbox defined in ADR 003 and ADR 004.
- The Outbox publisher and RabbitMQ transport are shared infrastructure. Inventory does not publish directly to RabbitMQ.

The `producer` value is persisted in the Outbox record and validated against the registered producers. It is not repeated inside the serialized event envelope.

## 3. Envelope

Every published event uses the shared Outbox envelope:

| Field | Type | Presence | Description |
|---|---|---|---|
| `eventId` | UUID string | Required | Stable identifier of the event. It is preserved across publication attempts. |
| `eventName` | string | Required | Integration-event name. |
| `eventVersion` | string | Required | Contract version. Inventory publishes version `1`. |
| `aggregateType` | string | Required | `stock_item`. |
| `aggregateId` | UUID string | Required | Identifier of the originating `StockItem`. |
| `aggregateVersion` | integer | Absent | Inventory does not publish aggregate versions. See section 7. |
| `occurredAt` | ISO 8601 UTC string | Required | Time at which the Inventory business fact occurred. |
| `payload` | object | Required | Event-specific primitive values. |
| `correlationId` | string | Absent | Not set by Inventory in this contract. |
| `causationId` | string | Absent | Not set by Inventory in this contract. |

## 4. Event Catalogue

All quantities are integers and represent the committed Inventory state after the operation. `available` is always `onHand - reserved`.

### 4.1. `inventory.stock_registered`

Published when a `StockItem` is registered with its initial quantity.

Source domain event: `InitialStockRegisteredDomainEvent`.

| Payload field | Type | Description |
|---|---|---|
| `stockItemId` | UUID string | Stock item identifier. |
| `productId` | UUID string | Product identifier. |
| `onHand` | integer | Initial on-hand quantity. |
| `reserved` | integer | Reserved quantity after registration. Always `0`. |
| `available` | integer | Available quantity after registration. |
| `initialQuantity` | integer | Quantity supplied during registration. |

### 4.2. `inventory.stock_added`

Published when stock is added to an existing `StockItem`.

Source domain event: `StockAddedDomainEvent`.

| Payload field | Type | Description |
|---|---|---|
| `stockItemId` | UUID string | Stock item identifier. |
| `productId` | UUID string | Product identifier. |
| `onHand` | integer | Resulting on-hand quantity. |
| `reserved` | integer | Resulting reserved quantity. |
| `available` | integer | Resulting available quantity. |
| `addedQuantity` | integer | Quantity added by the operation. |

### 4.3. `inventory.stock_reserved`

Published once per product when a reservation succeeds.

Source domain event: `StockReservedDomainEvent`.

| Payload field | Type | Description |
|---|---|---|
| `stockItemId` | UUID string | Stock item identifier. |
| `productId` | UUID string | Product identifier. |
| `onHand` | integer | Resulting on-hand quantity. |
| `reserved` | integer | Resulting reserved quantity. |
| `available` | integer | Resulting available quantity. |
| `orderId` | UUID string | Order that requested the reservation. |
| `reservationId` | UUID string | Reservation identifier. |
| `reservedQuantity` | integer | Quantity reserved by this operation. |

A multi-product batch publishes one event per reserved product, all within the same transaction. The batch either commits every event or none of them.

### 4.4. `inventory.stock_released`

Published when a reservation is released.

Source domain event: `ReservationReleasedDomainEvent`.

| Payload field | Type | Description |
|---|---|---|
| `stockItemId` | UUID string | Stock item identifier. |
| `productId` | UUID string | Product identifier. |
| `onHand` | integer | Resulting on-hand quantity. |
| `reserved` | integer | Resulting reserved quantity. |
| `available` | integer | Resulting available quantity. |
| `orderId` | UUID string | Order that owned the reservation. |
| `reservationId` | UUID string | Released reservation identifier. |
| `releasedQuantity` | integer | Quantity released by this operation. |

## 5. Domain-to-Integration Mapping

The mapping is implemented by `StockItemIntegrationEventMapper`.

| Inventory domain event | Integration event |
|---|---|
| `InitialStockRegisteredDomainEvent` | `inventory.stock_registered` |
| `StockAddedDomainEvent` | `inventory.stock_added` |
| `StockReservedDomainEvent` | `inventory.stock_reserved` |
| `ReservationReleasedDomainEvent` | `inventory.stock_released` |

Domain events are internal business facts. This table defines the only Inventory domain events currently mapped to public contracts. Future domain events require an explicit contract decision; they must not become public automatically.

## 6. Transaction and Delivery Guarantees

- The business change and its Outbox records are written in the same Inventory transaction.
- If the transaction rolls back, no success event is published.
- A multi-product reservation writes all of its `inventory.stock_reserved` records in one transaction.
- Reservation identifiers are generated before the transaction and reused when the transaction infrastructure retries the operation. A retried batch does not create duplicate reservations or duplicate Outbox records.
- `eventId` is stable across publication attempts.
- Delivery is at least once. A crash after RabbitMQ confirms a message but before the Outbox marks it as published can produce a duplicate delivery with the same `eventId`.
- No RabbitMQ network I/O executes inside the Inventory transaction.
- Outbox records are not an Event Store and are subject to the retention and purge policy in `outbox-operations.md`.

## 7. Ordering Limitations

Inventory does not publish `aggregateVersion`. Consumers must not assume:

- Global ordering across Inventory events.
- Per-`StockItem` ordering based on RabbitMQ delivery order.
- That a missing sequence number indicates a missing event.

OD-002 (aggregate versioning and event ordering) remains open. If Inventory introduces aggregate versions later, the contract and consumer policy must be documented and versioned explicitly.

Consumers that require ordering must define their own policy using the available fields, such as `occurredAt`, and must tolerate duplicates, late messages and gaps.

## 8. Consumer Responsibilities

Consumers of these events must:

- Depend only on this documented contract, not on Inventory domain classes, repositories or database tables.
- Validate the envelope and support only the event versions they explicitly handle.
- Process messages idempotently. The shared Inbox mechanism from Task004 is the supported pattern.
- Keep their local update and Inbox record in the same transaction.
- Treat `available` as a published derived value, not as authority to reserve stock.
- Request stock operations through Inventory application contracts, never by writing Inventory tables.

## 9. Related Documents

- `docs/adr/003-outbox-rabbitmq.md`
- `docs/adr/004-integration-events-and-cross-context-communication.md`
- `docs/architecture/outbox-operations.md`
- `docs/architecture/inbox-operations.md`
- `docs/architecture/open-decisions.md` (OD-002, OD-003, OD-006)
- `docs/tasks/003-transactional-outbox-and-integration-infrastructure.md`
- `docs/tasks/004-inbox-idempotent-consumers-and-event-ordering.md`
- `docs/tasks/006-inventory-persistence-and-stock-reservation.md`
