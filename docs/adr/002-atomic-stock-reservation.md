

# ADR 002 — Atomic Inventory Reservation

- **Status:** Accepted as the initial direction
- **Date:** 2026-11-05

## Context

An order may contain multiple line items. Confirming only some reservations would leave the system in an inconsistent state. Additionally, multiple orders may compete for the last available units.

## Decision

Order creation/confirmation reserves all line items within a single MySQL transaction. The required inventory rows are locked using `SELECT ... FOR UPDATE` in a deterministic order. The order, line items, reservations, and Outbox records are committed or rolled back together.

Availability checks are synchronous. RabbitMQ does not participate in the decision of whether stock is available.

## Consequences

**Positive:**
- No partially reserved orders.
- MySQL protects mutual exclusion between transactions.
- Business data persistence and Outbox persistence can be atomic.

**Negative:**
- The transaction may hold locks for longer if it is extended improperly.
- Deadlocks and retries must be handled, and concurrency must be tested against a real MySQL instance.
- No network I/O to RabbitMQ must be performed inside the transaction.

## Implementation Requirements

- Sort IDs before acquiring locks.
- Keep the transaction as short as possible.
- Do not make external calls while holding locks.
- Define a retry policy for deadlocks.
- Test rollback when a line item has insufficient stock.
- Test two transactions competing for the last available units.

## Rejected Alternatives

- **Reserving each line item in separate transactions:** This allows partial reservations.
- **Publishing an asynchronous command to RabbitMQ to reserve stock:** This complicates the response to the client and does not provide local atomicity between the order and stock.

