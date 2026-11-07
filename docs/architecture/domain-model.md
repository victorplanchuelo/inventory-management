# Domain Model — StockFlow

This document describes an initial model. Names, boundaries, and rules must be validated against the code and adjusted through tests and ADRs before being finalized.

## Orders Module

### Aggregate: Order
Responsibility: preserve the consistency of the order and its state transitions.

Candidate concepts:
- `OrderId`: order identity.
- `OrderItem`: order line, with `ProductId` and `Quantity`.
- `OrderStatus`: explicit state, for example `Pending`, `Confirmed`, `Cancelled`.
- `OrderRepository`: persistence port defined in Application or in the location that follows the existing convention.

Candidate invariants:
- An order contains at least one line.
- Quantities are positive.
- An order that has been cancelled cannot be confirmed.
- An order cannot be cancelled in a non-cancellable state.
- An order cannot be confirmed if the reservation of all its lines has not been completed.

The Order aggregate must not query Inventory directly through Eloquent or know about RabbitMQ. Coordination between aggregates belongs to the use case.

Candidate events:
- `OrderCreated`
- `OrderConfirmed`
- `OrderCancelled`

Integration events must contain only the identifiers and minimum data required, never passwords or secrets.

## Inventory Module

### Aggregate: StockItem
Responsibility: protect the quantity and availability of a product in inventory.

Candidate concepts:
- `StockItemId`
- `ProductId`
- `Quantity`
- `StockReservation` or a reservation entity/record with its own identity.
- `StockRepository`

Candidate invariants:
- Available stock is never negative.
- A reservation does not exceed available stock.
- A reservation is released only once.
- Adding stock requires a positive quantity.
- Concurrent operations cannot over-reserve units.

Conceptual definition:

`available = on_hand - reserved`

Agree on whether `reserved` is stored or derived from active reservations. Avoid duplicating counters without a clear consistency strategy.

Candidate events:
- `StockReserved`
- `StockReleased`
- `StockAdded`

## Coordination Between Modules

The order creation/confirmation use case coordinates Orders and Inventory. The reservation decision must be executed in the same MySQL transaction that persists the order.

It is not recommended for `Order` to hold a direct reference to the `StockItem` aggregate, or for Inventory to depend on the Order aggregate.

## Identifiers and Value Objects

- Prefer explicit types for IDs and quantities.
- Do not couple domain IDs to `int` if the chosen strategy uses UUID/ULID.
- Validate primitives when constructing Value Objects.
- `fromPrimitives()` must explicitly reconstruct Value Objects.
- `toPrimitives()` must return primitives, not Value Objects.
- Avoid exposing mutable properties internally.

## Domain and Integration Events

Distinguish:
- Domain event: a relevant fact that occurred within the model.
- Integration message: a contract published to other modules/processes.

Not all domain events have to be sent to RabbitMQ. Translation and publishing must be controlled by Application/Infrastructure and comply with existing contracts.
