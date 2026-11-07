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
**Then** only reservations that fit within the available stock are committed, and inventory never becomes negative.

### AC-08 — Deterministic Lock Ordering

Line items/products are locked in a stable order to reduce the risk of deadlocks.

### AC-09 — Rollback

If any write within the transactional operation fails, the order, line items, reservations, stock changes and associated Outbox messages are rolled back.

## Outbox and RabbitMQ

### AC-10 — Atomic Outbox Recording

The business change and its Outbox message are committed or rolled back together.

### AC-11 — RabbitMQ Unavailable

If RabbitMQ is unavailable after the commit, the confirmed order and Outbox message remain persisted for a subsequent retry.

### AC-12 — Duplicate Delivery

**Given** that a consumer has successfully processed an integration message  
**When** the same message is delivered again with the same `eventId`  
**Then** the consumer recognizes the duplicate and does not repeat its business effect or projection update.

### AC-13 — Consumer Failure

Transient errors are retried according to the configuration.

Permanent failures are isolated and observable through a dead-letter queue or an equivalent mechanism.

A failed processing transaction must not leave a processed-message record or partial local changes.

### AC-14 — Stable Message Identity

**Given** an Outbox message that has failed publication  
**When** the publisher retries it  
**Then** the message retains the same `eventId` and event contract identity.

## Integration Events and Inbox

### AC-15 — Context-Owned Integration Contracts

**Given** that a bounded context publishes an integration event  
**When** another context consumes it  
**Then** the consumer uses the published contract and does not depend on the producer's internal domain classes or persistence models.

### AC-16 — Independent Consumer Processing

**Given** that multiple bounded contexts consume the same integration event  
**When** each consumer processes it  
**Then** each context maintains its own processing state and local effects independently.

### AC-17 — Atomic Inbox and Local Update

**Given** an integration message that updates a local projection  
**When** the consumer processes the message  
**Then** the processed-message record and projection update are committed together.

If either operation fails, neither change remains committed.

### AC-18 — Out-of-Order Messages

**Given** that a consumer receives an event whose aggregate version is older than the version already applied  
**When** the consumer processes it  
**Then** the configured version policy prevents stale data from overwriting newer projection state.

### AC-19 — Missing Aggregate Version

**Given** that a consumer receives an event with a missing or unexpected aggregate version  
**When** version ordering is required by that projection  
**Then** the consumer follows its documented policy, such as deferring processing or initiating reconciliation, rather than silently assuming the event is safe to apply.

## Projections and Queries

### AC-20 — Projection Updated from Integration Event

**Given** that a context consumes a supported integration event  
**When** the message is successfully processed  
**Then** its own projection is updated with the relevant information.

### AC-21 — Projection Ownership

**Given** that a context requires information owned by another context  
**When** it maintains a local projection  
**Then** it updates that projection through integration events or an explicitly documented synchronization mechanism, without directly querying or modifying the source context's internal tables.

### AC-22 — Projection-Based API Query

**Given** that a context exposes a query backed by one of its projections  
**When** the API request is received  
**Then** the Application query uses a context-owned query interface and returns the documented response structure.

The controller must not access projection tables directly.

### AC-23 — Eventual Consistency

**Given** that a source context commits a business change and publishes an integration event  
**When** a consuming context has not processed that event yet  
**Then** its projection may temporarily show the previous state.

The system must not represent the projection as guaranteed to be immediately up to date.

### AC-24 — Stale Inventory Projection

**Given** that Orders has an inventory projection that reports sufficient available stock  
**When** an order is confirmed  
**Then** the confirmation use case checks and reserves stock against Inventory's authoritative state.

A stale projection must never cause an invalid reservation or allow stock to become negative.

### AC-25 — Projection Initialization and Reconciliation

**Given** that a projection must be initialized or reconciled  
**When** its documented synchronization strategy is executed  
**Then** the projection can be brought into a consistent state with its source without accessing the source context's internal persistence directly.

The exact synchronization mechanism must be defined by the projection specification.

## Catalog and Product References

### AC-26 — Product Reference Without Catalog

**Given** that Catalog has not yet been implemented  
**When** Orders or Inventory references a product  
**Then** it uses `ProductId` as an external reference without creating a duplicate Product aggregate.

### AC-27 — Catalog Product Projection

**Given** that Catalog is implemented and publishes a supported product integration event  
**When** Orders consumes that event  
**Then** its product projection is updated without modifying Catalog's product data.

This criterion applies when Catalog is included in the implementation scope.

## Quality and API

### AC-28 — Independent Domain

Domain unit tests run without starting Laravel, MySQL or RabbitMQ.

### AC-29 — HTTP Contract

Routes return documented status codes and response structures, and validate incoming data.

### AC-30 — Automated Quality

CI runs tests, static analysis and the code style checks configured in the repository.

### AC-31 — Reproducible Setup

Anyone who clones the project can start its dependencies, configure the environment, run migrations and execute tests by following the documentation.

## Acceptance Criteria Notes

- Criteria involving Catalog apply only when Catalog is included in the approved implementation scope.
- Projection-backed queries are eventually consistent.
- Inventory projections are advisory and must never replace authoritative stock validation.
- Aggregate version checks apply where the producer contract guarantees aggregate version generation.
- Projection initialization and reconciliation mechanisms must be specified before their implementation.
- The exact API response formats, HTTP status codes and error structures must be documented separately.
- These criteria describe observable behavior and do not prescribe unnecessary implementation details.
