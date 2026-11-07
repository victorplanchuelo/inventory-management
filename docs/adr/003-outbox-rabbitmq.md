# ADR 003 — Transactional Outbox and RabbitMQ

- **Status:** Accepted as the initial direction
- **Date:** 2026-11-05

## Context

Publishing directly to RabbitMQ during a MySQL transaction creates a window of inconsistency:

- The database transaction may roll back after the message has been published.
- The database transaction may commit successfully even though RabbitMQ is unavailable.
- The application may fail between publishing a message and recording its publication status.

The repository already includes RabbitMQ and Symfony Messenger, so they should be leveraged before introducing another broker.

StockFlow requires reliable publication of integration events without coupling business transactions to RabbitMQ availability.

The system must support at-least-once delivery and tolerate duplicate messages.

## Decision

Persist each integration message in an Outbox table within the same MySQL transaction as the corresponding business change.

An independent publisher reads pending Outbox messages and sends them to RabbitMQ using the existing Symfony Messenger integration.

The publisher must operate independently of the HTTP request lifecycle and must not contain domain business rules.

Each bounded context that publishes integration events owns its Outbox records and publication responsibilities.

### Outbox Message Identity

Every message must have a stable, globally unique `eventId`.

The identifier must remain unchanged across publication attempts.

The internal Outbox row identifier and the integration message identifier may be separate fields when their responsibilities differ.

The integration message identifier is the identity used by consumers to recognize duplicate delivery.

### Suggested Outbox Structure

The minimum proposed Outbox contract includes:

- `id`: internal Outbox record identifier.
- `event_id`: stable integration message identifier.
- `event_name`: integration event name.
- `event_version`: contract version.
- `aggregate_type`: originating aggregate type.
- `aggregate_id`: originating aggregate identifier.
- `payload`: serialized integration event payload.
- `occurred_at`: time of the business occurrence.
- `published_at`: nullable publication timestamp.
- `attempts`: publication attempt count.

The implementation may require additional operational fields, such as:

- `available_at`: earliest time at which the message may be retried.
- `locked_at`: time at which a publisher claimed the message.
- `locked_by`: publisher identity, if required by the coordination strategy.
- `last_error`: information about the last publication failure, excluding secrets.
- A lease or equivalent recovery mechanism.

The final schema must be defined after reviewing the actual MySQL and worker environment.

### Publisher Responsibilities

The publisher must:

- Select eligible pending messages.
- Claim messages safely when multiple publishers are running.
- Avoid processing the same pending record concurrently where possible.
- Publish messages to RabbitMQ.
- Mark messages as published only after successful publication.
- Record failed attempts and schedule retries.
- Recover messages whose publisher has stopped unexpectedly.
- Expose enough operational information to identify stuck or repeatedly failing messages.

The implementation must define a safe claim and recovery strategy for concurrent publishers.

A publisher must not hold a database transaction open while waiting for an external RabbitMQ operation longer than necessary.

The exact locking, leasing and transaction strategy must be validated against the deployed MySQL version and the actual worker configuration.

## Delivery and Idempotency

Exactly-once delivery is not guaranteed.

Duplicates may occur if the publisher successfully publishes a message and crashes before marking the Outbox record as published.

StockFlow adopts at-least-once delivery with idempotent consumers.

Each consumer is responsible for recording processed message identifiers through an Inbox or equivalent mechanism.

The processed-message record and the consumer's local business or projection update must be committed in the same transaction.

Inbox ownership, message consumption and cross-context processing rules are defined separately in ADR 004 and ADR 005 where applicable.

### Retry and Failure Handling

Configure:

- Retries for transient publication errors.
- A dead-letter queue or equivalent mechanism for exhausted messages.
- A documented retry policy and backoff strategy.
- Logs containing correlation and message identifiers, without sensitive data.
- Metrics or visibility into pending, delayed, failed and old messages.
- A recovery procedure for messages left claimed by a publisher that stopped unexpectedly.

Permanent failures must remain observable and must not silently disappear.

The exact retry limits, backoff intervals and operational recovery procedures belong to the implementation specification.

## Scope and Boundaries

This ADR defines reliable integration message recording and publication.

It does not define:

- The complete integration event contract and versioning policy.
- The ownership and lifecycle of consumer subscriptions.
- The Inbox schema and all consumer processing rules.
- Cross-context projection ownership and update semantics.
- Projection initialization or rebuilding strategies.
- Event Sourcing or permanent event history.

Those responsibilities are addressed by:

- `docs/adr/004-integration-events-and-cross-context-communication.md`
- `docs/adr/005-cross-context-projections.md`

The Outbox is not an Event Store.

It is a reliable record of messages waiting to be published and their publication state. Its retention and cleanup policies must not be confused with permanent event history.

## Consequences

**Positive:**

- The business change and the intent to publish are stored atomically.
- RabbitMQ may be temporarily unavailable without losing the pending message.
- The business transaction is decoupled from external publication.
- Each bounded context retains ownership of its published messages.
- At-least-once delivery provides a realistic reliability model.

**Negative:**

- There are more components and operational states.
- Delivery is eventual.
- Duplicates, retries and stuck messages must be managed.
- Publisher coordination and recovery require additional implementation and tests.
- Outbox retention and cleanup require operational policies.

## Rejected Alternatives

- **Direct publishing inside the transaction:** It is not atomic between MySQL and RabbitMQ.
- **RabbitMQ as the only record of publication intent:** A database commit and broker publication cannot be made atomic through the existing infrastructure alone.
- **Redis as a second broker:** It duplicates infrastructure without a demonstrated need.
- **Exactly-once delivery:** It is not considered a realistic end-to-end guarantee; at-least-once delivery with idempotent consumers is adopted instead.

## Implementation Constraints

- Use the existing RabbitMQ and Symfony Messenger infrastructure where appropriate.
- Do not introduce Redis.
- Do not publish integration messages directly from domain aggregates.
- Do not make the success of a business transaction depend on RabbitMQ availability.
- Persist the Outbox record in the same transaction as the business change.
- Preserve the same `eventId` across retries.
- Keep publisher implementation and transport concerns in Infrastructure.
- Test atomic persistence, retry behavior, duplicate publication scenarios and publisher recovery.
- Do not introduce Event Sourcing as part of this decision.

Any change to these architectural constraints must be evaluated and documented through an ADR update.
