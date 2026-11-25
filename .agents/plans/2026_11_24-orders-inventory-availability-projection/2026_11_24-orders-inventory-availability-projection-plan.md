---
name: "orders-inventory-availability-projection"
description: "Implement an Orders-owned, eventually consistent Inventory availability projection fed by Inventory integration events through the Inbox, expose an advisory API and provide a controlled snapshot initialization and reconciliation procedure."
created_at: "2026-11-25T09:40:50Z"

created_by:
  tool: "OpenCode"
  model:
    name: "DeepSeek V4.1 Flash Free"
    version: "4.1"
    reasoning_effort: "medium"
---

# Orders Inventory Availability Projection

## 1. Goal

Implement an Orders-owned, eventually consistent Inventory availability read model fed by Inventory integration events through the Task004 Inbox, expose it through an advisory API, and provide a controlled Inventory snapshot export and Orders import for initialization and reconciliation.

Inventory remains the sole authority for physical stock and reservations. Orders must not access Inventory tables, repositories or Eloquent models directly, and no cross-context transaction, order confirmation, generic projection framework, Event Sourcing, Redis, frontend or microservice may be introduced.

## 2. Context and authoritative references

- [AGENTS.md](../../../AGENTS.md): development workflow, architecture constraints, testing expectations and static-analysis rules.
- [docs/README.md](../../../docs/README.md): documentation index and reading order.
- [docs/tasks/005-orders-inventory-availability-projection.md](../../../docs/tasks/005-orders-inventory-availability-projection.md): source of truth for Task005 scope and acceptance criteria.
- [docs/adr/004-integration-events-and-cross-context-communication.md](../../../docs/adr/004-integration-events-and-cross-context-communication.md): integration-event envelope, event identity, contract versioning, consumer responsibilities and ordering rules.
- [docs/adr/005-cross-context-projections.md](../../../docs/adr/005-cross-context-projections.md): projection ownership, advisory nature, API reads and reconciliation boundaries.
- [docs/architecture/inventory-integration-events.md](../../../docs/architecture/inventory-integration-events.md): implemented Inventory event names, producer, aggregate type, contract version, payloads and the no-version limitation.
- [docs/architecture/inbox-operations.md](../../../docs/architecture/inbox-operations.md): per-context Inbox schema, consumer identity, atomic processing and ordering policies.
- [docs/architecture/open-decisions.md](../../../docs/architecture/open-decisions.md): OD-002 and OD-004 must be treated explicitly.
- [docs/architecture/architectural-reference.md](../../../docs/architecture/architectural-reference.md): Domain, Application, Infrastructure and API responsibilities, projections and test conventions.
- [docs/tasks/004-inbox-idempotent-consumers-and-event-ordering.md](../../../docs/tasks/004-inbox-idempotent-consumers-and-event-ordering.md): Inbox design and ordering-policy background.
- [docs/tasks/006-inventory-persistence-and-stock-reservation.md](../../../docs/tasks/006-inventory-persistence-and-stock-reservation.md): Inventory persistence, transaction and event-publication behavior.
- [docs/ai/working-agreements.md](../../../docs/ai/working-agreements.md): collaboration rules.
- [docs/ai/implementation-log.md](../../../docs/ai/implementation-log.md): implementation record and verification commands.

Existing code to inspect before implementing:

- [src/Shared/Application/Inbox/InboxProcessor.php](../../../src/Shared/Application/Inbox/InboxProcessor.php): claim-first atomic processing, duplicate suppression and ordering-policy application.
- [src/Shared/Application/Inbox/NoOrderingAggregateVersionPolicy.php](../../../src/Shared/Application/Inbox/NoOrderingAggregateVersionPolicy.php): ordering policy that processes every event without using `aggregateVersion`.
- [src/Shared/Application/IntegrationEvent/IntegrationEventSubscriber.php](../../../src/Shared/Application/IntegrationEvent/IntegrationEventSubscriber.php): subscriber contract with `subscribedTo()`, `consumerName()`, `orderingPolicy()` and `__invoke()`.
- [src/Shared/Application/IntegrationEvent/Exception/PermanentIntegrationEventFailure.php](../../../src/Shared/Application/IntegrationEvent/Exception/PermanentIntegrationEventFailure.php): base exception for failures that must be dead-lettered without retries.
- [src/Shared/Infrastructure/Inbox/InboxConnectionFactory.php](../../../src/Shared/Infrastructure/Inbox/InboxConnectionFactory.php): guarantees the application default connection for the Inbox transaction.
- [app/Providers/InboxServiceProvider.php](../../../app/Providers/InboxServiceProvider.php): subscriber tag, Inbox bindings, consumer wiring and context configuration.
- [config/inbox.php](../../../config/inbox.php): empty contexts map. Orders must add `'orders' => 'orders_inbox'`.
- [src/Inventory/Application/IntegrationEvent/StockItemIntegrationEventMapper.php](../../../src/Inventory/Application/IntegrationEvent/StockItemIntegrationEventMapper.php): exact Inventory event names and payload fields.
- [src/Inventory/Infrastructure/Persistence/Repositories/MySqlStockItemRepository.php](../../../src/Inventory/Infrastructure/Persistence/Repositories/MySqlStockItemRepository.php): Inventory `save()` behavior and UTC timestamp persistence.
- [src/Inventory/Infrastructure/Persistence/Eloquent/Models/StockItemEloquentModel.php](../../../src/Inventory/Infrastructure/Persistence/Eloquent/Models/StockItemEloquentModel.php): Inventory-owned persistence model. Orders must not use it.
- [app/Providers/InventoryServiceProvider.php](../../../app/Providers/InventoryServiceProvider.php): provider registration and default-connection binding conventions.
- [app/Providers/AppServiceProvider.php](../../../app/Providers/AppServiceProvider.php): query bus and handler tagging.
- [bootstrap/app.php](../../../bootstrap/app.php): route and command registration.
- [bootstrap/providers.php](../../../bootstrap/providers.php): registered service providers.
- [routes/user.php](../../../routes/user.php): existing route file conventions.
- [src/Api/User/Infrastructure/Index/GetAllUsersController.php](../../../src/Api/User/Infrastructure/Index/GetAllUsersController.php): legacy controller and query-bus conventions.
- [src/Shared/ApiBaseController.php](../../../src/Shared/ApiBaseController.php): base controller for API adapters.
- [database/migrations/2026_11_10_000001_create_outbox_table.php](../../../database/migrations/2026_11_10_000001_create_outbox_table.php): migration, index and check-constraint conventions.
- [database/migrations/2026_11_19_000001_create_inventory_stock_items_table.php](../../../database/migrations/2026_11_19_000001_create_inventory_stock_items_table.php): Inventory schema and UTC date-time conventions.
- [tests/Integration/Inventory/InventoryIntegrationTestCase.php](../../../tests/Integration/Inventory/InventoryIntegrationTestCase.php): integration test base and cleanup conventions.
- [tests/Integration/Inventory/Application/StockReservationConcurrencyTest.php](../../../tests/Integration/Inventory/Application/StockReservationConcurrencyTest.php): `pcntl_fork`, socket barriers, second-connection and lock-wait test conventions.
- [tests/Integration/Shared/Application/Inbox/InboxProcessorDuplicateRaceTest.php](../../../tests/Integration/Shared/Application/Inbox/InboxProcessorDuplicateRaceTest.php): duplicate-claim race test conventions.
- [tests/Integration/Shared/Infrastructure/Bus/Event/RabbitMq/RabbitMqIntegrationEventConsumerTest.php](../../../tests/Integration/Shared/Infrastructure/Bus/Event/RabbitMq/RabbitMqIntegrationEventConsumerTest.php): RabbitMQ consumer, retry and dead-letter test conventions.
- [tests/Shared/Infrastructure/IntegrationEvent/TestIntegrationEventSubscriber.php](../../../tests/Shared/Infrastructure/IntegrationEvent/TestIntegrationEventSubscriber.php): test subscriber shape.
- [tests/Shared/Infrastructure/Inbox/EphemeralInboxTable.php](../../../tests/Shared/Infrastructure/Inbox/EphemeralInboxTable.php): exact Task004 Inbox schema used by tests.
- [phpunit.xml](../../../phpunit.xml), [ecs.php](../../../ecs.php) and [phpstan.neon](../../../phpstan.neon): test suites, style and static analysis. The PHPStan baseline must not be extended.

### Current state verified during research

- No Orders bounded context exists. There is no Orders namespace, service provider, route, migration, projection or test suite.
- `config/inbox.php` has no production contexts. Task004 requires each context to add its own Inbox table when its first real consumer is implemented.
- Inventory publishes four version `1` integration events: `inventory.stock_registered`, `inventory.stock_added`, `inventory.stock_reserved` and `inventory.stock_released`.
- Every Inventory event payload contains `stockItemId`, `productId`, `onHand`, `reserved` and `available`. The Inventory producer does not publish `aggregateVersion`.
- The Outbox envelope supports an optional `aggregateVersion`, but Inventory never sets it. Orders must not invent one or reserve storage for it.
- `NoOrderingAggregateVersionPolicy` is the only applicable Inbox ordering policy for these events.
- `InboxProcessor` opens the processing transaction through `TransactionalSession`, which is bound to the application default connection. The Orders projection repository must use the same connection object so the projection update and Inbox claim commit or roll back together.
- `orders_inbox` does not exist yet. Its migration must copy the exact Task004 schema, indexes and processing-state check constraint with the `orders_inbox` prefix.
- The project runs MySQL 8.0.32. It enforces `CHECK` constraints and supports `INSERT ... ON DUPLICATE KEY UPDATE`.
- `PDO::MYSQL_ATTR_FOUND_ROWS` is not enabled in `config/database.php`.
- `MySqlStockItemRepository::save()` assigns `updated_at` from `new DateTimeImmutable('now', new DateTimeZone('UTC'))` after the Inventory domain event was constructed. `occurredAt` is generated at domain-event construction. Both are normalized to UTC and persisted at second precision, so for the state represented by a stock row, `updated_at >= occurredAt`.
- The API entry layer for new contexts belongs in `src/Orders/Infrastructure/Api/Controllers`, per the architectural reference. The legacy `src/Api/User` layout is not a precedent for new contexts.
- Task006 is implemented and committed as `48fc444` and is also approved. Task005 remains `Proposed`.

### Constraints

- The projection is advisory and eventually consistent.
- Inventory remains authoritative for physical stock and reservations.
- Orders must not query or import Inventory classes, tables, repositories or Eloquent models.
- The Inbox record and the projection write must commit in the same Orders transaction.
- No cross-context transaction, distributed transaction or two-phase commit is introduced.
- No generic projection framework, Event Sourcing, Redis, frontend, microservice or order-confirmation behavior is introduced.
- No `aggregateVersion` is stored, exposed or invented while Inventory does not publish it. OD-002 remains open.
- Task005 must not be marked `Verified` without explicit technical review approval.

## 3. Agreed contract decisions

### 3.1 Orders projection schema

Create two Orders-owned tables. All UUID identifiers use MySQL `CHAR(36)`, matching the existing Outbox and Inbox conventions. All date-time columns use MySQL `DATETIME` and store UTC wall-clock values.

`orders_inventory_availability`:

| Column | Type | Constraints |
|---|---|---|
| `product_id` | UUID | Primary key. The projection identity is the product. |
| `stock_item_id` | UUID | Not null, non-unique index `orders_inventory_availability_stock_item_id_index`. Source reference only. |
| `on_hand` | BIGINT UNSIGNED | Not null. |
| `reserved` | BIGINT UNSIGNED | Not null, default 0. |
| `source_occurred_at` | DATETIME | Not null, UTC. Watermark of the applied Inventory state. |
| `updated_at` | DATETIME | Not null, UTC. Local projection update time. |

Additional constraints:

```sql
CHECK (on_hand >= 0)
CHECK (reserved >= 0)
CHECK (reserved <= on_hand)
```

Rules:

- Do not create an `aggregate_version` column. OD-002 remains open and Inventory publishes no version.
- Do not persist `available`. It is derived as `on_hand - reserved`.
- `product_id` is the only unique key. `stock_item_id` is a non-unique index because the projection is keyed by product and the source reference must not create a second unique-key path for `ON DUPLICATE KEY UPDATE`.
- The model explicitly assumes one `StockItem` per `ProductId`. If Inventory later supports multiple stock items per product, this projection contract must be reviewed.
- Application and domain validation remain mandatory even when database constraints exist.
- Convert every date-time to UTC explicitly before formatting, for example with `setTimezone(new DateTimeZone('UTC'))`. Formatting alone does not change the timezone of the received object or compensate for the MySQL session timezone.

`orders_inbox`:

Copy the exact Task004 schema from [docs/architecture/inbox-operations.md](../../../docs/architecture/inbox-operations.md) section 5 with `orders_inbox` as the table name. Columns:

```text
id                 char(36) primary key
consumer           varchar(128) not null
event_id           char(36) not null
event_name         varchar(255) not null
event_version      varchar(32) not null
aggregate_type     varchar(255) not null
aggregate_id       varchar(255) not null
aggregate_version  unsigned bigint nullable
occurred_at        datetime not null
processed_at       datetime nullable
outcome            varchar(16) nullable
correlation_id     varchar(255) nullable
causation_id       varchar(255) nullable
created_at         datetime not null
```

Indexes and constraint:

```sql
UNIQUE orders_inbox_consumer_event_id_unique (consumer, event_id)
INDEX orders_inbox_consumer_aggregate_version_index (consumer, aggregate_type, aggregate_id, aggregate_version)
INDEX orders_inbox_consumer_processed_at_index (consumer, processed_at)
INDEX orders_inbox_consumer_outcome_index (consumer, outcome)
CHECK orders_inbox_processing_state_is_consistent:
    (processed_at IS NULL AND outcome IS NULL)
    OR (processed_at IS NOT NULL AND outcome IS NOT NULL)
```

The Inbox table includes `aggregate_version` because it stores the received envelope metadata. The Orders projection table does not. The Inbox column remains null for all Inventory events.

### 3.2 Orders domain and application contracts

No `aggregateVersion` field is added to any Orders domain or application class.

Domain:

```php
namespace Manager\Orders\Domain\InventoryAvailability;

final readonly class InventoryAvailability
{
    public function __construct(
        string $productId,
        string $stockItemId,
        int $onHand,
        int $reserved,
        DateTimeImmutable $sourceOccurredAt,
        DateTimeImmutable $updatedAt,
    ) {}

    public function productId(): string;
    public function stockItemId(): string;
    public function onHand(): int;
    public function reserved(): int;
    public function available(): int;
    public function sourceOccurredAt(): DateTimeImmutable;
    public function updatedAt(): DateTimeImmutable;
}
```

Domain invariants:

- `productId` and `stockItemId` must be valid UUID strings.
- `onHand >= 0`.
- `reserved >= 0`.
- `reserved <= onHand`.
- `available()` returns `onHand - reserved`.
- Timestamps are normalized to UTC in the constructor.

Repository:

```php
interface InventoryAvailabilityRepository
{
    public function findByProductId(string $productId): ?InventoryAvailability;

    public function apply(InventoryAvailability $availability): void; // Phase 1
    // Phase 2 changes the return type to ProjectionApplyOutcome.
}
```

The MySQL adapter requires an active transaction for `apply()`. It throws `LogicException` before issuing any query when `transactionLevel() === 0`, because the projection write must share the Inbox transaction. It never starts, commits or rolls back a transaction itself.

Application:

```php
namespace Manager\Orders\Application\InventoryAvailability\Update;

final readonly class InventoryStockState
{
    public function __construct(
        public string $productId,
        public string $stockItemId,
        public int $onHand,
        public int $reserved,
        public DateTimeImmutable $occurredAt,
    ) {}
}

final readonly class UpdateInventoryAvailability
{
    public function __invoke(InventoryStockState $state): void; // Phase 1
    // Phase 2 changes the return type to ProjectionApplyOutcome.
}
```

`UpdateInventoryAvailability` builds an `InventoryAvailability` with a local `updatedAt` timestamp and delegates to the repository. It does not read Inventory data.

Query:

```php
namespace Manager\Orders\Application\InventoryAvailability\Get;

final readonly class GetInventoryAvailabilityQuery implements Query
{
    public function __construct(public string $productId) {}
}

final class GetInventoryAvailabilityQueryHandler implements QueryHandler
{
    public function __invoke(GetInventoryAvailabilityQuery $query): ?InventoryAvailabilityResponse;
}

final readonly class InventoryAvailabilityResponse implements Response
{
    public function productId(): string;
    public function stockItemId(): string;
    public function onHand(): int;
    public function reserved(): int;
    public function available(): int;
    public function sourceOccurredAt(): string;
    public function projectionUpdatedAt(): string;
}
```

The response formats both timestamps as UTC with the pattern `Y-m-d\TH:i:s\Z`, matching the Outbox envelope and the snapshot contract.

### 3.3 Integration-event consumption

Create one Orders subscriber:

```php
namespace Manager\Orders\Infrastructure\IntegrationEvent;

final readonly class InventoryStockProjectionSubscriber implements IntegrationEventSubscriber
{
    public function subscribedTo(): array;
    public function consumerName(): string;
    public function orderingPolicy(): AggregateVersionPolicy;
    public function __invoke(IntegrationEventEnvelope $event): void;
}
```

Contract:

- `subscribedTo()` returns:

```php
[
    'inventory.stock_registered' => ['1'],
    'inventory.stock_added' => ['1'],
    'inventory.stock_reserved' => ['1'],
    'inventory.stock_released' => ['1'],
]
```

- `consumerName()` returns `orders.inventory_availability_projection`.
- `orderingPolicy()` returns `new NoOrderingAggregateVersionPolicy()`.
- `__invoke()` validates the envelope and payload, maps the resulting stock state and calls `UpdateInventoryAvailability`.
- The subscriber never imports Inventory domain classes, repositories, Eloquent models or tables.

Payload validation:

- `aggregateType` must be `stock_item`.
- `aggregateId` must equal `payload.stockItemId`.
- `stockItemId` and `productId` must be valid UUID strings.
- `onHand`, `reserved` and `available` must be integers.
- `onHand >= 0`, `reserved >= 0` and `reserved <= onHand`.
- `available` must equal `onHand - reserved`. The projection derives `available`; the payload value is validated for contract consistency and is not stored.
- Operation-specific fields remain part of the published contract but are not required by the projection.
- Any validation failure throws a permanent Orders exception extending `PermanentIntegrationEventFailure`, so the RabbitMQ consumer dead-letters it without retrying.
- Unknown payload fields are ignored for forward compatibility.

Configuration and wiring:

- Add `'orders' => 'orders_inbox'` to `config/inbox.php`.
- Create `OrdersServiceProvider` and register it in `bootstrap/providers.php`.
- Bind `InventoryAvailabilityRepository` to `MySqlInventoryAvailabilityRepository`.
- Resolve the repository connection through `InboxConnectionFactory::connection()` so the projection write and the Inbox claim use the exact same default connection object.
- Tag `InventoryStockProjectionSubscriber` as `integration_event_subscriber`.
- Tag `GetInventoryAvailabilityQueryHandler` as `query_handler`.

### 3.4 Ordering policy without `aggregateVersion`

Inventory publishes no `aggregateVersion`. Orders must not claim version-based ordering and must not invent a version.

Inbox policy:

- Use `NoOrderingAggregateVersionPolicy`.
- The Inbox suppresses duplicates by `(consumer, eventId)`.
- The Inbox does not compare aggregate versions and does not serialize different events for the same aggregate by itself.

Projection policy:

- Phase 1 uses a simple last-arrival upsert. It is a development happy path only. Out-of-order delivery can regress the projection.
- Phase 1 must not be deployed to production without Phase 2. This limitation must be stated in the Phase 1 description, the projection architecture document and the phase review.
- Phase 2 applies an event only when its `sourceOccurredAt` is strictly newer than the stored `sourceOccurredAt`.
- Equal timestamps are treated as stale and leave the projection unchanged.
- The comparison is done in UTC at second precision.
- The projection does not claim aggregate-version ordering. OD-002 remains open.
- If Inventory later publishes `aggregateVersion`, a new migration and an explicit contract change are required. No column is reserved now.

Phase 2 atomic upsert:

```sql
INSERT INTO orders_inventory_availability
    (product_id, stock_item_id, on_hand, reserved, source_occurred_at, updated_at)
VALUES (?, ?, ?, ?, ?, ?) AS incoming
ON DUPLICATE KEY UPDATE
    stock_item_id = IF(incoming.source_occurred_at > orders_inventory_availability.source_occurred_at, incoming.stock_item_id, orders_inventory_availability.stock_item_id),
    on_hand = IF(incoming.source_occurred_at > orders_inventory_availability.source_occurred_at, incoming.on_hand, orders_inventory_availability.on_hand),
    reserved = IF(incoming.source_occurred_at > orders_inventory_availability.source_occurred_at, incoming.reserved, orders_inventory_availability.reserved),
    updated_at = IF(incoming.source_occurred_at > orders_inventory_availability.source_occurred_at, incoming.updated_at, orders_inventory_availability.updated_at),
    source_occurred_at = IF(incoming.source_occurred_at > orders_inventory_availability.source_occurred_at, incoming.source_occurred_at, orders_inventory_availability.source_occurred_at)
```

Rules:

- `source_occurred_at` is assigned last because MySQL evaluates assignments from left to right. Every earlier comparison must use the previous stored watermark.
- The statement is atomic for existing rows and for the first insertion. There is no row to lock before the first insert; the unique primary key and the duplicate-key path resolve the race.
- Do not add a second unique key on `stock_item_id`. It would make the duplicate-key target ambiguous.
- Determine the outcome from the affected-row count:
  - `1`: inserted, return `APPLIED`.
  - `2`: updated, return `APPLIED`.
  - `0`: no-op because the incoming state is stale, return `STALE`.
- Do not enable `PDO::MYSQL_ATTR_FOUND_ROWS`. Verify that it remains disabled and cover the no-op outcome with a test.
- If the MySQL version or SQL mode does not support the row alias, use `VALUES(column)` with a documented deprecation note. Do not change the outcome semantics.
- The Inbox outcome remains `processed` when the projection ignores a stale event. The event was handled correctly; the projection chose not to apply it. Log the projection-level stale outcome for observability.
- Equal-second events remain ambiguous. Reconciliation through the Phase 3 snapshot is the recovery mechanism for that case.

### 3.5 Advisory API contract

Route:

```text
GET /orders/inventory-availability/{productId}
```

- Register a `orders` route prefix in `bootstrap/app.php` and a `routes/orders.php` file.
- Constrain `productId` to a UUID.
- The controller delegates to `QueryBus::ask(new GetInventoryAvailabilityQuery($productId))`.
- The controller must not access the projection table, Eloquent models or Inventory persistence directly.

Successful response:

```http
HTTP/1.1 200 OK
Content-Type: application/json
```

```json
{
  "productId": "7b0f4a3e-2f41-4f4a-9d5e-0f2a6c1b8d11",
  "stockItemId": "b5a1c2d3-4e5f-4a6b-8c7d-9e0f1a2b3c4d",
  "onHand": 10,
  "reserved": 2,
  "available": 8,
  "sourceOccurredAt": "2026-11-24T12:00:00Z",
  "projectionUpdatedAt": "2026-11-24T12:00:01Z",
  "advisory": true,
  "consistency": "eventually_consistent"
}
```

Missing projection response:

```http
HTTP/1.1 404 Not Found
Content-Type: application/problem+json
```

```json
{
  "type": "https://stockflow.example/problems/inventory-availability-not-found",
  "title": "Inventory availability projection not found",
  "status": 404,
  "detail": "No Inventory availability projection is available for this product yet. This does not mean that the product has no stock.",
  "advisory": true,
  "consistency": "eventually_consistent"
}
```

Rules:

- `available` is always derived as `onHand - reserved`.
- The endpoint must not query Inventory tables, repositories or models to fill missing data.
- The response must make the advisory and eventually consistent nature explicit.
- A missing projection must never be interpreted as zero stock.
- The response contract does not expose `aggregateVersion`.

### 3.6 Snapshot initialization and reconciliation

Initialization and reconciliation use a controlled Inventory-owned snapshot export and an Orders-owned snapshot import. Historical Outbox replay is not assumed.

Snapshot JSON contract:

```json
{
  "schemaVersion": 1,
  "generatedAt": "2026-11-24T12:00:00Z",
  "rows": [
    {
      "productId": "7b0f4a3e-2f41-4f4a-9d5e-0f2a6c1b8d11",
      "stockItemId": "b5a1c2d3-4e5f-4a6b-8c7d-9e0f1a2b3c4d",
      "onHand": 10,
      "reserved": 2,
      "sourceOccurredAt": "2026-11-24T11:59:00Z"
    }
  ]
}
```

Rules:

- `schemaVersion` is `1`.
- `generatedAt` and `sourceOccurredAt` use UTC with the pattern `Y-m-d\TH:i:s\Z`.
- `sourceOccurredAt` comes from the Inventory row `updated_at`.
- The snapshot contains `onHand` and `reserved`. It does not contain `available`.
- The export reads Inventory-owned data. The import reads only the JSON file and writes Orders-owned data.
- No Orders code reads Inventory tables during import.

Temporal consistency of the watermark:

- `occurredAt` is generated when the Inventory domain event is constructed, before `save()`.
- `updated_at` is generated inside `MySqlStockItemRepository::save()` after the domain event.
- Both use `DateTimeImmutable` in UTC and are persisted at second precision.
- Therefore, for the state represented by a row, `updated_at >= occurredAt`.
- The snapshot watermark is conservative: it may be newer than the event that produced the state, but it is never older.
- A snapshot row with a newer watermark applies over an older event state. An older event cannot overwrite a newer snapshot row.
- If Inventory later changes event timestamp generation, save semantics or timestamp precision, the snapshot contract must be reviewed before rollout.
- Equal-second timestamps remain ambiguous. Reconciliation through a fresh snapshot is the recovery mechanism.

Coordination:

- The Inventory consumer does not need to be stopped during export or import.
- The import applies rows through the same strict `sourceOccurredAt` guard as live events.
- The import applies all rows in one Orders transaction and fails without partial changes if any row is invalid.
- Reconciliation is a new export followed by a new import. It never modifies Inventory data from Orders.
- The import may hold row locks while it runs. Run it during low traffic and document the operational caution.
- Re-importing the same snapshot is idempotent: missing rows are applied, existing rows with equal timestamps are stale.
- Duplicate `productId` values inside one snapshot are rejected before any write.
- An event applied during the import either waits for the row lock and then compares timestamps, or is already committed and causes the older snapshot row to be stale.

### 3.7 Open decisions

- OD-002 remains Open. Inventory publishes no aggregate version and Orders does not claim version ordering. The `sourceOccurredAt` guard is an explicit temporary policy, not a replacement for aggregate-version guarantees.
- OD-004 becomes Decided in Phase 3 with the Inventory export plus Orders import procedure. No Outbox replay is required.
- OD-001 and order confirmation remain out of scope.

## 4. Phases

### Phase 1: Projection and advisory endpoint happy path

#### Description

Create the minimal Orders foundation for the Inventory availability projection: both persistence tables, the projection read model and repository, the update and query use cases, an Inventory integration-event subscriber registered with the Inbox, and the advisory HTTP endpoint.

This phase consumes all four Inventory stock events from the start, so no supported event type is dead-lettered while Phase 2 is pending. It uses a simple last-arrival upsert.

**Phase 1 is a development happy path only. It is not production-ready because last-arrival upsert can regress under out-of-order delivery. Do not deploy Phase 1 to production without Phase 2.**

#### Public contracts

**Database**

- `orders_inventory_availability` with the columns, primary key, non-unique `stock_item_id` index and check constraints from section 3.1. No `aggregate_version` and no `available`.
- `orders_inbox` with the exact Task004 schema, indexes and processing-state check constraint from section 3.1.
- `config/inbox.php` adds `'orders' => 'orders_inbox'`.

**Domain**

- `Manager\Orders\Domain\InventoryAvailability\InventoryAvailability` with the constructor and getters from section 3.2.
- `Manager\Orders\Domain\InventoryAvailability\InventoryAvailabilityRepository` with:
  - `findByProductId(string $productId): ?InventoryAvailability`
  - `apply(InventoryAvailability $availability): void`

**Application**

- `Manager\Orders\Application\InventoryAvailability\Update\InventoryStockState` without `aggregateVersion`.
- `Manager\Orders\Application\InventoryAvailability\Update\UpdateInventoryAvailability::__invoke(InventoryStockState $state): void`.
- `Manager\Orders\Application\InventoryAvailability\Get\GetInventoryAvailabilityQuery`.
- `Manager\Orders\Application\InventoryAvailability\Get\GetInventoryAvailabilityQueryHandler`.
- `Manager\Orders\Application\InventoryAvailability\Get\InventoryAvailabilityResponse`.

**Integration**

- `Manager\Orders\Infrastructure\IntegrationEvent\InventoryStockProjectionSubscriber` for the four Inventory stock events, contract version `1`, consumer `orders.inventory_availability_projection`, `NoOrderingAggregateVersionPolicy`.
- `Manager\Orders\Application\InventoryAvailability\Update\Exception\InvalidInventoryStockEvent` extending `PermanentIntegrationEventFailure`.
- `App\Providers\OrdersServiceProvider` with repository, subscriber and query-handler bindings, registered in `bootstrap/providers.php`.

**Persistence**

- `Manager\Orders\Infrastructure\Persistence\Repositories\MySqlInventoryAvailabilityRepository` implementing `InventoryAvailabilityRepository`.
- The adapter uses the same default connection as the Inbox and requires an active transaction for `apply()`.

**API**

- `Manager\Orders\Infrastructure\Api\Controllers\GetInventoryAvailabilityController`.
- `routes/orders.php` and the `orders` route prefix registration in `bootstrap/app.php`.
- `GET /orders/inventory-availability/{productId}` with the 200 and 404 contracts from section 3.5.

**Tests**

- `tests/Unit/Orders/Domain/InventoryAvailability/InventoryAvailabilityTest.php`
  - `it_should_derive_available_from_on_hand_and_reserved`
  - `it_should_reject_negative_on_hand`
  - `it_should_reject_negative_reserved`
  - `it_should_reject_reserved_greater_than_on_hand`
  - `it_should_reject_invalid_identifiers`
  - `it_should_normalize_timestamps_to_utc`
- `tests/Unit/Orders/Application/InventoryAvailability/UpdateInventoryAvailabilityTest.php`
  - `it_should_apply_the_incoming_stock_state_to_the_repository`
  - `it_should_build_the_projection_with_the_event_occurrence_time`
  - `it_should_propagate_repository_failures`
- `tests/Unit/Orders/Application/InventoryAvailability/GetInventoryAvailabilityQueryHandlerTest.php`
  - `it_should_return_the_projection_response`
  - `it_should_return_null_when_the_projection_does_not_exist`
  - `it_should_format_timestamps_in_utc_iso_8601`
- `tests/Unit/Orders/Infrastructure/IntegrationEvent/InventoryStockProjectionSubscriberTest.php`
  - `it_should_subscribe_to_the_four_inventory_stock_events_with_contract_version_one`
  - `it_should_use_the_orders_consumer_name`
  - `it_should_use_the_no_ordering_policy`
  - `it_should_map_the_stock_registered_event_payload`
  - `it_should_map_the_stock_added_event_payload`
  - `it_should_map_the_stock_reserved_event_payload`
  - `it_should_map_the_stock_released_event_payload`
  - `it_should_reject_an_aggregate_type_other_than_stock_item`
  - `it_should_reject_a_payload_stock_item_id_that_does_not_match_the_aggregate_id`
  - `it_should_reject_missing_or_invalid_payload_fields`
  - `it_should_reject_negative_quantities`
  - `it_should_reject_reserved_greater_than_on_hand`
  - `it_should_reject_an_inconsistent_available_quantity`
  - `it_should_throw_a_permanent_failure_for_invalid_payloads`
- `tests/Integration/Orders/Infrastructure/Persistence/OrdersSchemaConstraintsTest.php`
  - `it_should_reject_a_negative_on_hand_value`
  - `it_should_reject_a_negative_reserved_value`
  - `it_should_reject_reserved_greater_than_on_hand`
  - `it_should_enforce_one_projection_per_product`
  - `it_should_create_the_orders_inbox_table_with_the_task004_schema`
- `tests/Integration/Orders/Infrastructure/Persistence/MySqlInventoryAvailabilityRepositoryTest.php`
  - `it_should_insert_a_new_projection`
  - `it_should_update_an_existing_projection`
  - `it_should_find_a_projection_by_product_id`
  - `it_should_return_null_for_an_unknown_product_id`
  - `it_should_reject_an_apply_outside_an_active_transaction`
  - `it_should_store_timestamps_in_utc_when_the_mysql_session_timezone_is_not_utc`
- `tests/Integration/Orders/Infrastructure/IntegrationEvent/InventoryStockProjectionConsumerTest.php`
  - `it_should_commit_the_projection_and_inbox_record_together`
  - `it_should_not_apply_a_duplicate_event_twice`
  - `it_should_roll_back_the_projection_and_inbox_when_the_projection_write_fails`
  - `it_should_process_all_four_supported_event_types`
  - `it_should_leave_no_projection_and_no_inbox_record_when_the_projection_write_fails`
- `tests/Api/Orders/Infrastructure/InventoryAvailability/GetInventoryAvailabilityControllerTest.php`
  - `it_should_return_the_local_projection`
  - `it_should_return_404_with_an_advisory_problem_when_the_projection_does_not_exist`
  - `it_should_reject_a_non_uuid_product_id`
  - `it_should_expose_advisory_and_eventual_consistency_metadata`
  - `it_should_not_query_inventory_tables`

**Documentation**

- Create `docs/architecture/orders-inventory-availability-projection.md` with the projection model, event mapping, API contract and the explicit Phase 1 production warning.
- Add the new document to `docs/README.md`.

#### To-do actions

- [ ] Create `database/migrations/2026_11_24_000001_create_orders_inbox_table.php` with the exact Task004 schema, indexes and `orders_inbox_processing_state_is_consistent` check constraint. Add the check constraint with a raw `ALTER TABLE` statement following the existing Outbox and Task004 conventions.
- [ ] Create `database/migrations/2026_11_24_000002_create_orders_inventory_availability_table.php` with the approved columns, `product_id` primary key, non-unique `stock_item_id` index and the three check constraints. Add the checks with raw `ALTER TABLE` statements.
- [ ] Add `'orders' => 'orders_inbox'` to `config/inbox.php`.
- [ ] Implement `InventoryAvailability` with identifier validation, quantity invariants, derived `available()` and UTC normalization. Do not add `aggregateVersion`.
- [ ] Implement `InventoryAvailabilityRepository` and `MySqlInventoryAvailabilityRepository`. Use a simple atomic last-arrival upsert in this phase. Require an active transaction for `apply()` and throw `LogicException` before any query otherwise.
- [ ] Implement `InventoryStockState`, `UpdateInventoryAvailability`, `GetInventoryAvailabilityQuery`, `GetInventoryAvailabilityQueryHandler` and `InventoryAvailabilityResponse`. Do not add `aggregateVersion`.
- [ ] Implement `InventoryStockProjectionSubscriber` with the four event names, version `1`, consumer name, `NoOrderingAggregateVersionPolicy`, payload validation and permanent failure behavior.
- [ ] Implement `InvalidInventoryStockEvent` extending `PermanentIntegrationEventFailure`.
- [ ] Create `OrdersServiceProvider`, bind the repository through `InboxConnectionFactory::connection()`, tag the subscriber as `integration_event_subscriber`, tag the query handler as `query_handler` and register the provider in `bootstrap/providers.php`.
- [ ] Create `routes/orders.php`, register the `orders` prefix in `bootstrap/app.php` and implement `GetInventoryAvailabilityController` with the UUID route constraint, 200 response and 404 Problem Details response.
- [ ] Add the unit, integration and API tests listed above.
- [ ] Create `docs/architecture/orders-inventory-availability-projection.md` and index it in `docs/README.md`. Include the Phase 1 production warning.
- [ ] Run the migrations against the `testing` database.
- [ ] Verify the changes in terms of typechecking, linting and tests using `docker compose exec -T app php vendor/bin/ecs check`, `docker compose exec -T app php vendor/bin/phpstan analyse --no-progress --memory-limit=1G` and `docker compose exec -T app php vendor/bin/phpunit`. Fix issues if any. Do not add or modify PHPStan baseline entries.
- [ ] STOP. Present the changes, migration status and verification results to the user for review. Suggest the commit message `feat(orders): add inventory availability projection and advisory endpoint`. Do NOT proceed to Phase 2 until the user explicitly asks.

#### Verification

- [ ] Run `docker compose exec -T app php artisan migrate --env=testing --force`.
- [ ] Run `docker compose exec -T app php vendor/bin/phpunit tests/Unit/Orders tests/Integration/Orders tests/Api/Orders`.
- [ ] Run `docker compose exec -T app php vendor/bin/phpunit` to check the existing suites.
- [ ] Run `docker compose exec -T app php vendor/bin/ecs check` over every changed path, then over the full project if the changed paths pass.
- [ ] Run `docker compose exec -T app php vendor/bin/phpstan analyse --no-progress --memory-limit=1G`.
- [ ] Verify that `phpstan-baseline.neon` was not added to, removed from or modified.
- [ ] Verify that Orders code does not reference Inventory tables, repositories, Eloquent models or namespaces.
- [ ] Verify that no commit, push or pull request is created without explicit approval.

#### Stop

- [ ] STOP. Present the full diff, migration status and verification results for review. Suggest the commit message `feat(orders): add inventory availability projection and advisory endpoint`. Do not proceed to Phase 2 until the user explicitly asks.

### Phase 2: Ordering and failure hardening

#### Description

Replace the Phase 1 last-arrival upsert with the explicit no-version ordering policy. Only apply an event when its `sourceOccurredAt` is strictly newer than the stored watermark; equal timestamps are stale. Harden the consumer against malformed payloads, unsupported contract versions and failed projection writes, and cover the first-insert race and concurrent updates with real MySQL concurrency tests.

This phase removes the Phase 1 production limitation. No new endpoint or table is added.

#### Public contracts

**Domain and application**

- New `Manager\Orders\Domain\InventoryAvailability\ProjectionApplyOutcome`:
  - `APPLIED = 'applied'`
  - `STALE = 'stale'`
- Modified `InventoryAvailabilityRepository::apply(InventoryAvailability $availability): ProjectionApplyOutcome`.
- Modified `UpdateInventoryAvailability::__invoke(InventoryStockState $state): ProjectionApplyOutcome`.
- The repository still requires an active transaction.
- The repository returns `APPLIED` for inserts and strictly newer updates, and `STALE` for older or equal `sourceOccurredAt` values.

**Integration**

- `InventoryStockProjectionSubscriber` logs projection-level stale outcomes with the event identifier, product identifier and source timestamp. The Inbox outcome remains `processed`.
- `InvalidInventoryStockEvent` remains the permanent failure for malformed payloads.
- Unsupported event names and versions are rejected by the existing Task004 envelope validation and are dead-lettered as permanent failures.

**Tests**

- `tests/Integration/Orders/Infrastructure/IntegrationEvent/InventoryStockProjectionOrderingTest.php`
  - `it_should_apply_an_event_when_no_projection_exists`
  - `it_should_apply_a_newer_event`
  - `it_should_ignore_an_older_event`
  - `it_should_ignore_an_event_with_the_same_source_time`
  - `it_should_preserve_the_newer_projection_when_an_older_event_arrives_after_it`
  - `it_should_return_applied_or_stale_from_the_repository`
  - `it_should_log_a_stale_projection_outcome`
- `tests/Integration/Orders/Infrastructure/IntegrationEvent/InventoryStockProjectionConcurrencyTest.php`
  - `it_should_create_one_projection_when_two_first_events_for_the_same_product_arrive_concurrently`
  - `it_should_keep_the_newer_state_when_two_first_events_for_the_same_product_arrive_concurrently`
  - `it_should_keep_the_newer_state_when_two_updates_for_the_same_projection_arrive_concurrently`
  - `it_should_keep_the_newer_state_when_an_older_event_arrives_concurrently_with_a_newer_update`
  - `it_should_not_deadlock_when_two_first_events_for_the_same_product_arrive_concurrently`
  - `it_should_serialize_the_first_insert_and_the_second_transaction_on_the_primary_key`
- `tests/Integration/Orders/Infrastructure/IntegrationEvent/InventoryStockProjectionFailureTest.php`
  - `it_should_dead_letter_an_unsupported_event_version`
  - `it_should_dead_letter_a_malformed_payload`
  - `it_should_roll_back_the_projection_and_inbox_on_a_projection_write_failure`
  - `it_should_not_acknowledge_before_the_transaction_commits`
  - `it_should_leave_the_projection_unchanged_when_the_inbox_claim_fails`
- `tests/Api/Orders/Infrastructure/InventoryAvailability/GetInventoryAvailabilityControllerTest.php`
  - `it_should_return_the_projection_after_a_stale_event_was_ignored`

**Documentation**

- Update `docs/architecture/orders-inventory-availability-projection.md` with the strict `sourceOccurredAt` policy, equal-timestamp limitation, failure behavior and concurrency guarantees.
- Update `docs/architecture/open-decisions.md` to record that OD-002 remains Open and Task005 uses the `sourceOccurredAt` policy.

#### To-do actions

- [ ] Add `ProjectionApplyOutcome` and change the repository and update-use-case signatures.
- [ ] Implement the atomic `INSERT ... ON DUPLICATE KEY UPDATE` with the strict `sourceOccurredAt` guard. Assign `source_occurred_at` last and use the affected-row count to return `APPLIED` or `STALE`.
- [ ] Verify that `PDO::MYSQL_ATTR_FOUND_ROWS` is not enabled. Add a test that an equal-timestamp or older event returns `STALE` and leaves the row unchanged.
- [ ] Ensure the repository still enforces an active transaction and never commits or rolls back itself.
- [ ] Log projection-level stale outcomes in the subscriber without changing the Inbox outcome.
- [ ] Add the ordering, concurrency and failure tests listed above. Use `pcntl_fork`, socket barriers, a second real MySQL connection and `information_schema.processlist` following `StockReservationConcurrencyTest.php` and `InboxProcessorDuplicateRaceTest.php`. Do not infer lock waits from fixed sleeps. Skip process-level tests only when `pcntl` is unavailable and document the skip.
- [ ] Verify the first-insert race deterministically: one worker inserts and holds the transaction open, the second worker blocks on the primary key, the first commits, and the second resolves through the duplicate-key path without a duplicate row.
- [ ] Verify the two-update race deterministically: one worker applies and holds the row lock, the second waits, the first commits, and the second compares timestamps and either applies or returns `STALE`.
- [ ] Update the projection architecture document with the strict policy, equal-timestamp limitation, first-insert behavior, lock-wait behavior and the fact that the projection does not claim aggregate-version ordering.
- [ ] Update `docs/architecture/open-decisions.md` for OD-002.
- [ ] Verify the changes in terms of typechecking, linting and tests using `docker compose exec -T app php vendor/bin/ecs check`, `docker compose exec -T app php vendor/bin/phpstan analyse --no-progress --memory-limit=1G` and `docker compose exec -T app php vendor/bin/phpunit`. Fix issues if any. Do not add or modify PHPStan baseline entries.
- [ ] STOP. Present the changes, concurrency evidence and verification results to the user for review. Suggest the commit message `feat(orders): harden inventory projection ordering`. Do NOT proceed to Phase 3 until the user explicitly asks.

#### Verification

- [ ] Run `docker compose exec -T app php vendor/bin/phpunit tests/Unit/Orders tests/Integration/Orders tests/Api/Orders`.
- [ ] Run `docker compose exec -T app php vendor/bin/phpunit` to check the existing suites.
- [ ] Run `docker compose exec -T app php vendor/bin/ecs check` over every changed path, then over the full project if the changed paths pass.
- [ ] Run `docker compose exec -T app php vendor/bin/phpstan analyse --no-progress --memory-limit=1G`.
- [ ] Verify that `phpstan-baseline.neon` was not added to, removed from or modified.
- [ ] Verify that an older event, an equal-timestamp event and a stale event arriving during a concurrent update all leave the newer projection intact.
- [ ] Verify that no deadlock, duplicate projection or lost newer state occurs in the first-insert race.
- [ ] Verify that no commit, push or pull request is created without explicit approval.

#### Stop

- [ ] STOP. Present the full diff, concurrency test evidence and verification results for review. Suggest the commit message `feat(orders): harden inventory projection ordering`. Do not proceed to Phase 3 until the user explicitly asks.

### Phase 3: Initialization and reconciliation

#### Description

Add the controlled snapshot procedure. Inventory exports a versioned JSON snapshot of its current stock state. Orders imports it through the same strict watermark path used by live events. Each row carries the Inventory `updated_at` as `sourceOccurredAt`, so the snapshot and live events converge without stopping the consumer or replaying the Outbox.

Reconciliation is a new export followed by a new import. This phase resolves OD-004.

#### Public contracts

**Snapshot JSON**

- `schemaVersion`: `1`.
- `generatedAt`: UTC timestamp with `Y-m-d\TH:i:s\Z`.
- `rows`: list of `productId`, `stockItemId`, `onHand`, `reserved` and `sourceOccurredAt`.
- No `available` field.
- No `aggregateVersion` field.

**Inventory application and CLI**

- `Manager\Inventory\Application\Snapshot\InventoryStockSnapshot`
  - `schemaVersion(): int`
  - `generatedAt(): DateTimeImmutable`
  - `rows(): array`
- `Manager\Inventory\Application\Snapshot\InventoryStockSnapshotRow`
  - `productId(): string`
  - `stockItemId(): string`
  - `onHand(): int`
  - `reserved(): int`
  - `sourceOccurredAt(): DateTimeImmutable`
- `Manager\Inventory\Application\Snapshot\InventoryStockSnapshotReader`
  - `all(): iterable`
- `Manager\Inventory\Application\Snapshot\ExportInventoryStockSnapshot`
  - `__invoke(): InventoryStockSnapshot`
- CLI command `manager:inventory:stock-snapshot:export --output=<path>`.
- The export reads only Inventory-owned persistence and never modifies Inventory data.

**Orders application and CLI**

- `Manager\Orders\Application\InventoryAvailability\Import\ImportInventoryAvailabilitySnapshot`
  - `__invoke(array $rows): ImportInventoryAvailabilitySnapshotResult`
- `Manager\Orders\Application\InventoryAvailability\Import\ImportInventoryAvailabilitySnapshotResult`
  - `total(): int`
  - `applied(): int`
  - `stale(): int`
- CLI command `manager:orders:inventory-availability:import --input=<path>`.
- The import applies all rows in one transaction and fails without partial changes if any row is invalid.
- The import rejects duplicate `productId` values before writing.
- The import validates every row before applying any row.

**Tests**

- `tests/Integration/Inventory/Application/InventoryStockSnapshotExportTest.php`
  - `it_should_export_every_stock_item_with_its_current_quantities`
  - `it_should_use_the_row_updated_at_as_the_source_watermark`
  - `it_should_export_an_empty_snapshot_when_no_stock_items_exist`
  - `it_should_export_a_watermark_not_earlier_than_the_originating_event_occurred_at`
  - `it_should_export_utc_watermarks_when_the_mysql_session_timezone_is_not_utc`
- `tests/Integration/Inventory/Infrastructure/Console/ExportInventoryStockSnapshotCommandTest.php`
  - `it_should_write_a_versioned_snapshot_file`
  - `it_should_fail_when_the_output_path_is_not_writable`
  - `it_should_write_utc_timestamps_in_the_expected_format`
- `tests/Integration/Orders/Application/ImportInventoryAvailabilitySnapshotTest.php`
  - `it_should_create_projections_from_a_valid_snapshot`
  - `it_should_update_a_stale_projection_with_a_newer_snapshot_row`
  - `it_should_not_overwrite_a_newer_projection_with_an_older_snapshot_row`
  - `it_should_apply_all_rows_in_one_transaction_and_roll_back_on_failure`
  - `it_should_reject_an_invalid_snapshot_without_partial_changes`
  - `it_should_reject_duplicate_product_ids`
  - `it_should_be_idempotent_when_reimported`
  - `it_should_preserve_events_applied_during_the_import`
  - `it_should_compare_snapshot_and_event_timestamps_in_utc_when_the_mysql_session_timezone_is_not_utc`
  - `it_should_store_imported_timestamps_in_utc_when_the_mysql_session_timezone_is_not_utc`
- `tests/Integration/Orders/Infrastructure/Console/ImportInventoryAvailabilitySnapshotCommandTest.php`
  - `it_should_import_a_snapshot_file`
  - `it_should_fail_with_a_clear_message_when_the_file_is_missing`
  - `it_should_fail_with_a_clear_message_when_the_snapshot_is_invalid`
  - `it_should_reject_an_unsupported_schema_version`
  - `it_should_not_change_the_projection_when_the_file_is_invalid`

**Documentation**

- Update `docs/architecture/orders-inventory-availability-projection.md` with initialization, reconciliation, watermark coordination, equal-timestamp limitations and operational cautions.
- Mark OD-004 as `Decided` in `docs/architecture/open-decisions.md` with a link to the projection document.
- Update `docs/tasks/README.md`, `docs/tasks/005-orders-inventory-availability-projection.md` and `docs/ai/implementation-log.md` with the delivery record. Leave Task005 as `Implemented (pending review)`. Do not mark it `Verified` without explicit technical review approval.

#### To-do actions

- [ ] Implement the Inventory snapshot DTOs, reader, export use case and CLI command. Read `inventory_stock_items` only. Use the row `updated_at` as `sourceOccurredAt`. Format `generatedAt` and `sourceOccurredAt` in UTC with `Y-m-d\TH:i:s\Z`.
- [ ] Write the snapshot file to a temporary path and rename it into place so a partial export is not consumed as a valid snapshot.
- [ ] Register the Inventory snapshot bindings and command in `InventoryServiceProvider` and `bootstrap/app.php`.
- [ ] Implement the Orders snapshot parser, row validation, import use case, result DTO and CLI command. Validate every row and duplicate product IDs before starting the transaction. Apply all rows through `InventoryAvailabilityRepository::apply()` inside one `TransactionalSession` transaction. Do not introduce a new transaction abstraction.
- [ ] Register the Orders import binding and command in `OrdersServiceProvider` and `bootstrap/app.php`.
- [ ] Ensure the import does not read Inventory tables or instantiate Inventory classes. It receives only the decoded snapshot rows.
- [ ] Add the export, import and command tests listed above. Use the existing `withSessionTimeZone` pattern to verify UTC behavior when the MySQL session timezone is not UTC.
- [ ] Document the coordination rule: per-row `sourceOccurredAt` prevents snapshot rows from overwriting newer event state and prevents older late events from overwriting snapshot state.
- [ ] Document the equal-timestamp limitation and the recommendation to run the import during low traffic because it holds row locks.
- [ ] Document that a future change to Inventory event timestamp generation, save semantics or timestamp precision requires a review of the snapshot contract.
- [ ] Mark OD-004 as `Decided` with a link to the projection document.
- [ ] Update Task005, the task index and the implementation log. Leave Task005 as `Implemented (pending review)`.
- [ ] Verify the changes in terms of typechecking, linting and tests using `docker compose exec -T app php vendor/bin/ecs check`, `docker compose exec -T app php vendor/bin/phpstan analyse --no-progress --memory-limit=1G` and `docker compose exec -T app php vendor/bin/phpunit`. Fix issues if any. Do not add or modify PHPStan baseline entries.
- [ ] STOP. Present the changes, snapshot evidence and verification results to the user for review. Suggest the commit message `feat(orders): initialize inventory projection from snapshots`. Do NOT mark Task005 as `Verified` until the user explicitly approves.

#### Verification

- [ ] Run `docker compose exec -T app php artisan migrate:status --env=testing`.
- [ ] Run `docker compose exec -T app php vendor/bin/phpunit tests/Unit/Orders tests/Integration/Orders tests/Api/Orders`.
- [ ] Run `docker compose exec -T app php vendor/bin/phpunit tests/Integration/Inventory`.
- [ ] Run `docker compose exec -T app php vendor/bin/phpunit` to check the complete suite.
- [ ] Run `docker compose exec -T app php vendor/bin/ecs check` over every changed path, then over the full project if the changed paths pass.
- [ ] Run `docker compose exec -T app php vendor/bin/phpstan analyse --no-progress --memory-limit=1G`.
- [ ] Verify that `phpstan-baseline.neon` was not added to, removed from or modified.
- [ ] Verify that initialization and reconciliation do not depend on permanent Outbox history.
- [ ] Verify that Orders never reads Inventory tables or repositories during import, API queries or event processing.
- [ ] Verify that no commit, push or pull request is created without explicit approval.

#### Stop

- [ ] STOP. Present the full diff, snapshot export/import evidence, acceptance-criteria checklist and verification results for review. Suggest the commit message `feat(orders): initialize inventory projection from snapshots`. Leave Task005 as `Implemented (pending review)` and do not proceed to any follow-up task until the user explicitly approves.

## 5. Pre-implementation verification gates

Close these gates before finalizing the dependent contract:

- MySQL upsert syntax: before implementing Phase 2, verify on MySQL 8.0.32 that the row alias in `INSERT ... VALUES (...) AS incoming ON DUPLICATE KEY UPDATE ...` works with the installed SQL mode. If it does not, use `VALUES(column)` with a documented deprecation note and keep the same outcome semantics.
- Affected-row semantics: verify that `PDO::MYSQL_ATTR_FOUND_ROWS` is disabled in every environment. If it is enabled, do not infer `STALE` from a zero affected-row count. Stop and revise the outcome strategy before implementing.
- Timestamp invariant: before implementing Phase 3, verify with a real test that the exported Inventory `updated_at` is never earlier than the `occurredAt` of the event that produced the row state. If the invariant fails, stop and revise the snapshot watermark contract instead of adapting it silently.
- Timestamp precision: verify that both Inventory event timestamps and the projection store use second precision. If the precision changes, review the strict comparison and the equal-timestamp policy before rollout.

## 6. Next step

Begin with Phase 1: create the Orders projection tables, consume the four Inventory stock events through the Inbox, and expose the advisory availability endpoint. Phase 1 is a development happy path and must not be deployed to production without Phase 2.

Projection safely projected by 🐢 💨 (Turbotuga™, [Codely](https://codely.com)'s mascot).
