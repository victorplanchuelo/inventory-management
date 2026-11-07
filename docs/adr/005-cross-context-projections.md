# ADR 005 — Cross-Context Projections

- **Status:** Proposed
- **Date:** 2026-11-07

## Context

StockFlow is organized as a modular monolith with independent bounded contexts.

Orders, Inventory and the planned Catalog context own their respective domain models, application logic and persistence.

Some use cases require information owned by another bounded context. For example:

- Orders may need product descriptions for order-related queries.
- Orders may need inventory availability information for display.
- API endpoints may need to return information assembled from data maintained by different contexts.

Direct access to another context's repositories or database tables would violate bounded-context ownership and create coupling.

Integration events, as defined in ADR 004, provide a mechanism for contexts to publish business facts. Consumers can use these events to maintain local projections containing the information required by their own queries.

The system therefore needs explicit rules for projection ownership, updates, consistency, API access and reconciliation.

## Decision

Cross-context read requirements must be satisfied through projections owned by the consuming bounded context.

A projection is a local read model containing information derived from integration events or another explicitly approved synchronization mechanism.

A projection:

- Is owned by the bounded context that uses it.
- Is stored and updated within that context's persistence boundary.
- Does not become the authoritative owner of the source business information.
- Must not contain domain behavior belonging to the source context.
- May be used by application queries and API read endpoints.
- Must tolerate eventual consistency.

The architecture will use specific projections for concrete use cases rather than introducing a generic projection engine or a complete CQRS framework.

## Projection Ownership

The context consuming information owns its projection schema, update logic and query interface.

The source context remains the authority for the underlying business facts.

Examples:

| Information | Authoritative context | Possible consuming projection |
|---|---|---|
| Product name and SKU | Catalog | Orders Product Projection |
| Product name and SKU | Catalog | Inventory Product Projection |
| On-hand and reserved stock | Inventory | Orders Inventory Projection |
| Order lifecycle information | Orders | A future consumer-specific projection |

A context may maintain multiple projections of the same source information when different use cases require different read models.

Projections must not be shared as a common database model between contexts.

## Projection Update Mechanism

Projections are updated by consumers of integration events.

The expected flow is:

1. A source context commits a business change and its Outbox record in one transaction.
2. The Outbox publisher sends the integration event through RabbitMQ.
3. A consumer receives the event.
4. The consumer validates the event contract.
5. The consumer updates its own projection.
6. The consumer records the processed message in its Inbox.
7. The projection update and Inbox record commit in the same local transaction.

The Inbox and idempotency rules are defined in ADR 004.

A failed transaction must not leave the projection updated without its corresponding processed-message record, or vice versa.

Projection consumers must not call source-context repositories to complete ordinary event processing.

## Projection Data Design

Each projection must contain only the information required by its consuming use cases.

The projection schema must be designed around the queries it supports rather than copied from the source aggregate or database schema.

For example, an Orders Product Projection may contain:

- `product_id`
- `sku`
- `name`
- `status`
- `source_version`, when available
- `updated_at`

An Orders Inventory Projection may contain:

- `product_id`
- `on_hand`
- `reserved`
- `available`
- `source_version`, when available
- `updated_at`

These are candidate fields, not a mandatory universal schema. The final fields must follow the approved use cases and integration contracts.

Projection tables must use stable source identifiers and appropriate uniqueness constraints to prevent multiple rows representing the same projected entity where only one is expected.

Projection records must not expose or reuse source-context ORM models.

## API Read Responsibilities

API read endpoints may use projections as their data source.

The API controller must access the projection through an application query or query port belonging to its own bounded context.

The API must not query another context's tables directly or use another context's repositories.

The intended flow is:

`HTTP Request → Controller → Application Query → Local Projection Port → Infrastructure Read Adapter`

This allows API responses to be optimized for their read requirements without weakening domain boundaries.

A projection-backed response must not imply stronger consistency than the projection can guarantee.

Where relevant, the API should expose enough information for clients or operators to understand that projected information may be temporarily stale.

The exact API response shape and freshness metadata must be defined by the corresponding endpoint specification.

## Eventual Consistency

Cross-context projections are eventually consistent.

A successful business transaction in the source context does not imply that every consuming projection has already been updated.

The system must tolerate a period during which:

- The source context has committed a change.
- The corresponding integration event is waiting in the Outbox or RabbitMQ.
- One or more consuming projections still reflect an earlier state.

Projection consumers must process events idempotently and expose failures that prevent updates.

The architecture must not use a projection as an authoritative source for enforcing business invariants owned by another context.

### Inventory Projection Is Advisory

An Orders Inventory Projection may be used to:

- Display approximate availability.
- Provide preliminary feedback to a user.
- Support read queries and operational visibility.

It must not be used as the final authority for confirming an order or reserving stock.

Order confirmation must validate and reserve authoritative Inventory stock through the Inventory application boundary.

The stock reservation operation must enforce Inventory invariants against the authoritative Inventory persistence state.

For the initial Orders and Inventory workflow, the confirmation process must perform the required stock reservation within the approved atomic MySQL transaction strategy.

A stale projection must never allow an order to reserve stock that is no longer available.

## Ordering and Stale Data

Consumers must define how they handle duplicate and out-of-order events according to ADR 004.

Where source aggregate versions are available, a projection may store the last applied source version.

The consumer must not overwrite newer projected state with an older event.

Version gaps must be handled according to the subscription's requirements. A gap is not necessarily an error when the consumer subscribes to only a subset of the source events.

Where aggregate versioning is unavailable, the consumer must use an explicitly documented alternative policy and must not assume that message arrival order represents business order.

Projection update behavior must be deterministic and covered by tests.

## Projection Initialization and Reconciliation

A projection must have a documented initialization strategy.

Possible strategies include:

- Consuming events from the beginning of an available publication history.
- Performing an explicit synchronization with the authoritative source context.
- Importing a controlled snapshot from the source context.
- Rebuilding from another durable and explicitly supported data source.

The Outbox must not be treated as a permanent Event Store.

Outbox retention or cleanup may make complete historical replay impossible.

Therefore, projection rebuilding must not assume that every event ever published remains available.

The source context must provide an approved synchronization mechanism when a projection needs to be initialized or reconciled and retained Outbox messages are insufficient.

Reconciliation must respect bounded-context ownership and must not introduce direct database access between contexts.

The initial implementation must document the initialization and recovery approach for each projection that is required by the approved scope.

## Projection Failure Handling and Observability

Projection consumers must make failures observable.

Operational visibility should include:

- Consumer identity.
- Event identifier.
- Event name and version.
- Source aggregate identifier.
- Processing attempt information.
- Last successful processing time.
- Projection update failures.
- Pending or repeatedly failing messages.

Logs must not expose sensitive payload data.

The implementation must define retry and dead-letter behavior consistently with the messaging infrastructure.

A projection that stops receiving updates must be detectable and recoverable through its documented synchronization or reconciliation strategy.

## Boundaries and Constraints

- A context owns its projections.
- A context does not write to another context's projections.
- A context does not query another context's tables directly.
- Projection data is not an authoritative replacement for source aggregates.
- Projection update logic belongs to the consuming context.
- Projection queries belong to the consuming context's application layer.
- Infrastructure implements projection persistence and event consumption.
- API endpoints may query local projections through application query ports.
- No generic projection framework is required.
- No Event Sourcing is introduced.
- No distributed database or additional broker is introduced.

## Consequences

**Positive:**

- API read models can be optimized for concrete use cases.
- Bounded contexts preserve ownership of their data.
- Consumers remain independent of source persistence implementations.
- Cross-context queries do not require direct repository calls.
- Eventual consistency is explicit and manageable.
- The architecture can add projections as new use cases require them.

**Negative:**

- Projection data may temporarily be stale.
- Additional tables, consumers and tests are required.
- Initialization and reconciliation need explicit strategies.
- Projection failures require operational monitoring.
- Multiple projections may duplicate selected information.
- Read models must evolve when their consuming use cases change.

## Rejected Alternatives

- **Direct cross-context table queries:** They violate persistence ownership and couple contexts to each other's schemas.
- **Shared projection tables:** They create shared ownership and make independent evolution harder.
- **Using source aggregates for every read:** It introduces unnecessary coupling and may produce inefficient read paths.
- **Treating projections as authoritative:** It can violate business invariants when projected data is stale.
- **A generic CQRS or projection framework from the beginning:** It introduces abstraction and complexity before concrete use cases justify it.
- **Rebuilding every projection from the Outbox:** The Outbox is not a permanent event history and may have retention or cleanup policies.

## Implementation Constraints

- Implement only projections required by approved use cases.
- Keep projection schemas and update logic inside the consuming context.
- Consume versioned integration events through the contracts defined in ADR 004.
- Persist Inbox records and projection updates atomically.
- Make duplicate and out-of-order handling explicit.
- Use projections as API read sources only through local application query ports.
- Treat inventory availability projections as advisory.
- Enforce stock reservation against authoritative Inventory state.
- Document initialization and reconciliation for every required projection.
- Test eventual consistency, duplicate delivery, stale events, failed updates and reconciliation.
- Do not introduce a generic projection framework or Event Sourcing.

Any change to these architectural constraints must be evaluated and documented through an ADR update.
