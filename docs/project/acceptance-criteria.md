

# Acceptance Criteria — StockFlow

The following criteria describe observable outcomes. They should be converted into automated tests wherever possible.

## Orders and Reservations

### AC-01 — Order with Sufficient Stock

**Given** that all products have sufficient available stock  
**When** an order is created with those line items  
**Then** the order is confirmed and one reservation is recorded for each line item.

### AC-02 — Insufficient Stock for One Line Item

**Given** that one order line item requests more units than are available  
**When** the order creation/confirmation is attempted  
**Then** the operation fails with a clear business error, the order is not confirmed, and no partial reservation is persisted.

### AC-03 — Empty Order

**When** an order is created without line items  
**Then** the domain rejects the operation.

### AC-04 — Invalid Quantity

**When** a line item has a zero or negative quantity  
**Then** it is rejected before any changes are persisted.

### AC-05 — Valid Cancellation

**Given** a cancellable order with active reservations  
**When** the order is cancelled  
**Then** the order transitions to the cancelled state and the reservations are released exactly once.

### AC-06 — Repeated Cancellation

**Given** an order that has already been cancelled  
**When** cancellation is requested again  
**Then** the behavior follows the domain's explicit policy and never duplicates the stock release.

## Concurrency and Persistence

### AC-07 — Two Orders Competing for the Last Available Units

**Given** that the available stock is insufficient to cover both orders  
**When** both attempt to reserve stock concurrently  
**Then** at most the order that fits within the available stock is confirmed, and inventory never becomes negative.

### AC-08 — Deterministic Lock Ordering

Line items/products are locked in a stable order to reduce the risk of deadlocks.

### AC-09 — Rollback

If any write within the transactional operation fails, the order, line items, reservations, and associated Outbox messages are rolled back.

## Outbox and RabbitMQ

### AC-10 — Atomic Outbox Recording

The business change and its Outbox message are committed or rolled back together.

### AC-11 — RabbitMQ Unavailable

If RabbitMQ is unavailable after the commit, the confirmed order and Outbox message remain persisted for a subsequent retry.

### AC-12 — Duplicate Delivery

If a consumer receives the same message twice, it does not repeat the business effect.

### AC-13 — Consumer Failure

Transient errors are retried according to the configuration. Permanent failures are isolated and observable.

## Quality and API

### AC-14 — Independent Domain

Domain unit tests run without starting Laravel, MySQL, or RabbitMQ.

### AC-15 — HTTP Contract

Routes return documented status codes and response structures, and validate incoming data.

### AC-16 — Automated Quality

CI runs tests, static analysis, and the code style checks configured in the repository.

### AC-17 — Reproducible Setup

Anyone who clones the project can start its dependencies, configure the environment, run migrations, and execute tests by following the documentation.

