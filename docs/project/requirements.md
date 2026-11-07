

# Requirements — StockFlow

## Functional Requirements

### FR-01 — Create Order

The API must allow creating an order with at least one line item. Each line item identifies a product and a positive quantity.

### FR-02 — Atomic Confirmation

An order can only be confirmed if all its line items can be reserved. The entire operation must be atomic: if any line item fails, no partial reservation or confirmed order must remain.

### FR-03 — Retrieve Order

The API must allow retrieving an order's identifier, status, and line items.

### FR-04 — Cancel Order

The API must allow requesting the cancellation of an order in a cancellable state. If active reservations exist, they must be released consistently.

### FR-05 — Register Inventory

The API must allow registering the initial inventory quantity for a product, preventing invalid states and unplanned duplicates.

### FR-06 — Add Inventory

The API must allow increasing the stock of an existing product by a positive quantity.

### FR-07 — Retrieve Inventory

The API must allow retrieving available and reserved quantities and, if supported by the model, total quantities.

### FR-08 — Publish Events

Events that must be communicated to other components must be recorded in the Outbox within the same transaction as the business change.

### FR-09 — Consume Events

Messages published to RabbitMQ must be processed with retries and idempotency. Permanent failures must be isolatable through a dead-letter queue or an equivalent mechanism.

## Non-Functional Requirements

### NFR-01 — Consistency

Order persistence, reservations, and Outbox records must maintain transactional consistency in MySQL.

### NFR-02 — Concurrency

Reservations must be protected against concurrent race conditions through row locking and a deterministic lock acquisition strategy.

### NFR-03 — Separation of Concerns

The domain and use cases must not depend on Laravel, Eloquent, HTTP, or RabbitMQ.

### NFR-04 — Testability

Domain rules must be tested with fast unit tests. Adapters must be tested through integration tests.

### NFR-05 — Idempotency

Repeating a message must not repeat non-idempotent business effects.

### NFR-06 — Compatibility

New code must be compatible with PHP 8.3 and the versions of Laravel and libraries actually installed.

### NFR-07 — Security

Secrets and credentials must not be included in logs, events, or responses. External inputs must be validated.

### NFR-08 — Local Operability

The development environment must document the required services, environment variables, migrations, tests, and workers.

## Initial Business Rules

- An order must contain at least one line item.
- Requested quantities must be positive integers.
- More stock than is available cannot be reserved.
- An order cannot be partially confirmed.
- State transitions must be explicitly modeled.
- A reservation can only be released once.
- A cancellation must not release stock twice.
- Domain identifiers must not depend on specific database types.
- An event must not carry secrets or information that the consumer does not need.

## Pending Decisions Requiring Validation

- Whether an order is created directly in the `pending` state or confirmed within the same use case.
- Whether modifying already-created orders is allowed.
- Reservation expiration policy.
- Whether negative inventory is allowed for administrative adjustments (no, by default).
- HTTP request idempotency key policy.
- Exact error format and HTTP status codes.
- Authentication and authorization.
- Cancellation policy after events have been published.

