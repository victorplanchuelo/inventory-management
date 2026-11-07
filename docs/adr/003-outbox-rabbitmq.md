

# ADR 003 — Transactional Outbox and RabbitMQ

- **Status:** Accepted as the initial direction
- **Date:** 2026-11-05

## Context

Publishing directly to RabbitMQ during a MySQL transaction creates a window of inconsistency: the database may roll back after publishing, or the commit may complete even though RabbitMQ is unavailable.

The repository already includes RabbitMQ and Symfony Messenger, so they should be leveraged before introducing another broker.

## Decision

Persist each integration message in an Outbox table within the same transaction as the business change. An independent publisher reads pending messages and sends them to RabbitMQ using the existing Symfony Messenger integration.

Suggested minimum Outbox contract:

- `id`
- `event_name`
- `payload`
- `occurred_at`
- `published_at` nullable
- `attempts`

The implementation may require `available_at`, `locked_at`, `last_error`, or a lease strategy to coordinate concurrent publishers. Define these after studying the actual environment.

## Delivery and Idempotency

Exactly-once delivery is not guaranteed. Duplicates may occur if the publisher successfully publishes a message and crashes before marking the record as published.

Each message must have a stable identifier. The consumer records or acknowledges this identifier in such a way that repeated delivery does not repeat the business effect.

Configure:

- Retries for transient errors.
- A dead-letter queue or equivalent mechanism for exhausted messages.
- Logs containing correlation/message IDs, without sensitive data.
- Metrics or visibility into pending and old messages.

## Consequences

**Positive:**
- The business change and the intent to publish are stored atomically.
- RabbitMQ may be temporarily unavailable without losing the event.
- The commit is decoupled from side effects.

**Negative:**
- There are more components and operational states.
- Delivery is eventual.
- Duplicates, retries, and stuck messages must be managed.

## Rejected Alternatives

- **Direct publishing inside the transaction:** It is not atomic between MySQL and RabbitMQ.
- **Redis as a second broker:** It duplicates infrastructure without a need.
- **Exactly-once delivery:** It is not considered a realistic end-to-end guarantee; at-least-once delivery with idempotent consumers is adopted instead.

