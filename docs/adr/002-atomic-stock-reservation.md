# ADR 002 — Atomic Inventory Reservation and Order Confirmation

- **Status:** Proposed — revision of the initial decision
- **Date:** 2026-11-08
- **Supersedes:** Initial transaction-boundary decision recorded on 2026-11-05

## Context

An order may contain multiple line items. Confirming only some inventory reservations would leave the stock operation in an inconsistent state.

Additionally, multiple orders may compete for the last available units.

StockFlow is a modular monolith organized around bounded contexts. Orders owns order lifecycle and order persistence. Inventory owns physical stock, reservations and stock availability.

Each bounded context owns its persistence and its transactional business operations.

The initial decision stated that order data, inventory reservations and Outbox records would be committed or rolled back together in a single MySQL transaction.

That statement does not clearly respect the ownership boundaries established by ADR 004:

- Inventory owns stock and reservation persistence.
- Orders owns order persistence.
- Each context owns its own Outbox.
- A context must not write to another context's persistence structures.

A single transaction spanning both contexts would therefore require an explicit architectural exception or a different coordination model.

This ADR revision separates the atomicity of Inventory reservation from the still unresolved coordination of order confirmation.

## Decision

### 1. Inventory reservation is atomic within Inventory

Inventory must reserve all requested stock for an order within one Inventory-owned database transaction.

The operation must:

1. Receive the required product identifiers and quantities through the Inventory application boundary.
2. Identify the corresponding StockItem aggregates.
3. Acquire the required database locks in deterministic order.
4. Validate the available quantity for every requested product.
5. Reject the complete operation if any requested quantity cannot be reserved.
6. Create all corresponding reservations.
7. Update the relevant stock counters.
8. Persist the corresponding Inventory Outbox records in the same transaction.
9. Commit the complete Inventory operation or roll it back.

A failed reservation must not leave a partially reserved order.

Inventory remains responsible for enforcing its invariants against authoritative persisted stock.

### 2. Inventory owns its reservation transaction and Outbox

The Inventory transaction must include:

- StockItem persistence changes.
- Reservation persistence changes.
- Inventory Outbox records corresponding to the committed business changes.

The Inventory Outbox records must be persisted in the same transaction as the Inventory business changes, following ADR 003 and ADR 004.

RabbitMQ publication must occur after the database transaction through the Outbox publisher.

No network communication with RabbitMQ may take place while the Inventory transaction is holding database locks.

### 3. Orders owns order persistence

Orders remains responsible for:

- Order aggregates and lifecycle.
- Order line items.
- Order persistence.
- Orders Outbox records.
- Order-related integration events.

Inventory must not write Orders tables or Orders Outbox records.

Orders must not write Inventory tables, reservations or Inventory Outbox records.

### 4. Cross-context order confirmation remains unresolved

Order confirmation requires synchronous authoritative stock validation and reservation through the Inventory application boundary.

However, the final coordination protocol between Orders and Inventory is not decided by this ADR revision.

The implementation must not assume that the following operations are one atomic transaction:

- Persisting the confirmed Order in Orders.
- Reserving stock in Inventory.

A failure can occur after one context commits and before the other context completes its operation.

The chosen coordination protocol must explicitly define:

- When an order becomes confirmed.
- What state an order occupies while reservation and order persistence are being coordinated.
- What happens when Inventory rejects a reservation.
- What happens when Inventory commits a reservation but Orders cannot persist the corresponding order state.
- How incomplete operations are detected and recovered.
- How repeated requests are handled idempotently.
- How reservations are released when the overall confirmation cannot complete.
- How timeouts and ambiguous outcomes are resolved without creating duplicate reservations.

No implementation may claim cross-context atomicity until this decision is explicitly resolved.

## Options to Evaluate for Cross-Context Coordination

The following alternatives require evaluation before order confirmation is implemented.

### Option A — Synchronous reservation with compensation

Orders invokes Inventory synchronously to reserve stock.

After a successful reservation, Orders persists the corresponding order state.

If Orders cannot complete the operation after Inventory has committed, a defined compensation mechanism must release the reservation.

The design must address process crashes, timeouts, retries and cases where the result of the Inventory operation is unknown.

### Option B — Explicit pending confirmation workflow

Orders persists an intermediate order state and coordinates reservation and confirmation through a durable workflow.

The workflow must define recovery behavior, idempotency and the lifecycle of reservations when confirmation cannot complete.

The design must preserve synchronous authoritative stock validation where required by the user-facing confirmation contract.

### Option C — Explicit shared transaction exception

A shared MySQL transaction could provide atomicity across both contexts, but it would weaken the persistence ownership boundaries established by ADR 004.

This option must not be adopted implicitly.

It would require an explicit architectural decision defining the permitted cross-context transaction boundary, ownership responsibilities and consequences for independent evolution.

## Consequences

### Positive

- Inventory reservation has a clear transactional owner.
- Multiple stock reservations for one order are all-or-nothing within Inventory.
- Inventory business changes and their Outbox records remain atomic.
- Orders and Inventory retain ownership of their respective persistence structures.
- Cross-context consistency risks are made explicit rather than hidden behind a shared-transaction claim.
- The architecture can evaluate a coordination strategy without prematurely coupling it to the projection implementation.

### Negative

- Inventory reservation and order persistence are not automatically atomic together.
- A coordination and recovery protocol is required before order confirmation can be implemented.
- Additional idempotency, failure-handling and possibly compensation behavior may be necessary.
- The final confirmation workflow may introduce intermediate order states or operational recovery requirements.

## Implementation Requirements

- Inventory must enforce stock invariants within its own transaction.
- All requested reservations for one Inventory operation must commit or roll back together.
- Lock acquisition must use deterministic ordering.
- Inventory business changes and corresponding Inventory Outbox records must commit atomically.
- No RabbitMQ network I/O may occur inside the Inventory transaction.
- Orders must not write to Inventory persistence.
- Inventory must not write to Orders persistence.
- Reservation operations must have an explicit idempotency strategy before they are used in a recoverable cross-context workflow.
- Concurrency tests must use a real MySQL instance.
- Tests must cover insufficient stock, rollback and competing reservations.
- The cross-context confirmation protocol must be explicitly decided before order confirmation implementation begins.

## Rejected Alternatives

- **Reserving each line item in separate transactions:** This allows partial reservations within one order.
- **Publishing an asynchronous command to RabbitMQ as the sole stock availability decision:** This complicates synchronous confirmation and does not provide immediate authoritative reservation results.
- **Assuming one transaction can transparently own both contexts' persistence:** This hides a violation of persistence ownership and leaves failure handling undefined.
- **Using the Orders availability projection to authorize reservations:** Projections are eventually consistent and are not authoritative for Inventory invariants.

## Open Decisions

The following decisions require explicit review and approval:

1. Which cross-context coordination option should be used for order confirmation?
2. Should Orders introduce an intermediate confirmation state?
3. What is the idempotency contract for Inventory reservation requests?
4. How will an Inventory reservation be released if Orders cannot complete confirmation?
5. How will interrupted or ambiguous confirmation operations be detected and recovered?
6. What response should the API return while confirmation is unresolved?

Until these questions are resolved, order confirmation implementation remains blocked.

Any accepted resolution must update this ADR and the relevant task specifications.
