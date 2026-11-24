---
name: "inventory-persistence-and-stock-reservation"
description: "Implement MySQL persistence for Inventory StockItem aggregates, transactional stock operations with row locking, atomic Outbox recording and Inventory integration-event contracts."
created_at: "2026-11-19T11:39:39Z"
status: "In progress"

created_by:
  tool: "OpenCode"
  model:
    name: "DeepSeek V4.1 Flash Free"
    version: "4.1"
    reasoning_effort: "medium"

implemented_by:
  tool: "OpenCode"
  model:
    name: "DeepSeek V4.1 Flash Free"
    version: "4.1"
    reasoning_effort: "medium"

last_implementation_at: "2026-11-23T10:55:06Z"
has_completed_all_phases: false
---

# Inventory Persistence and Stock Reservation

## 1. Goal

Implement Inventory-owned MySQL persistence for complete `StockItem` aggregates, including their `StockReservation` entities, and make stock operations atomic, concurrency-safe and reliably publishable through the existing Transactional Outbox.

The implementation must support single-product and multi-product reservations without introducing cross-context persistence access, another publisher, Event Sourcing, Redis or a generic workflow engine.

## 2. Context and authoritative references

- [AGENTS.md](../../../AGENTS.md): development workflow, architecture constraints, testing expectations and static-analysis rules.
- [docs/README.md](../../../docs/README.md): documentation index and reading order.
- [docs/tasks/006-inventory-persistence-and-stock-reservation.md](../../../docs/tasks/006-inventory-persistence-and-stock-reservation.md): source of truth for Task006 scope and acceptance criteria.
- [docs/tasks/001-inventory-foundation.md](../../../docs/tasks/001-inventory-foundation.md): Inventory domain and application contracts that must be preserved.
- [docs/tasks/003-transactional-outbox-and-integration-infrastructure.md](../../../docs/tasks/003-transactional-outbox-and-integration-infrastructure.md): existing Outbox contract and transaction guarantees.
- [docs/adr/002-atomic-stock-reservation.md](../../../docs/adr/002-atomic-stock-reservation.md): Inventory-owned reservation transaction and unresolved cross-context coordination.
- [docs/adr/003-outbox-rabbitmq.md](../../../docs/adr/003-outbox-rabbitmq.md): Transactional Outbox and asynchronous publication rules.
- [docs/adr/004-integration-events-and-cross-context-communication.md](../../../docs/adr/004-integration-events-and-cross-context-communication.md): integration-event envelope, naming, versioning, producer and consumer responsibilities.
- [docs/architecture/architectural-reference.md](../../../docs/architecture/architectural-reference.md): Domain, Application and Infrastructure responsibilities, transaction boundaries and testing strategy.
- [docs/architecture/domain-model.md](../../../docs/architecture/domain-model.md): Inventory aggregate model, quantities and reservation rules.
- [docs/architecture/open-decisions.md](../../../docs/architecture/open-decisions.md): OD-001, OD-002 and OD-003 must be treated explicitly.
- [docs/ai/working-agreements.md](../../../docs/ai/working-agreements.md): collaboration rules.
- [docs/ai/implementation-log.md](../../../docs/ai/implementation-log.md): implementation record and verification commands.

Existing code to inspect before implementing:

- [src/Inventory/Domain/StockItem.php](../../../src/Inventory/Domain/StockItem.php): aggregate root with a private constructor and domain-event recording.
- [src/Inventory/Domain/StockReservation.php](../../../src/Inventory/Domain/StockReservation.php): owned entity with a private constructor and immutable release behavior.
- [src/Inventory/Domain/StockItemRepository.php](../../../src/Inventory/Domain/StockItemRepository.php): domain repository contract.
- [src/Inventory/Domain/Event](../../../src/Inventory/Domain/Event): existing domain events and primitive representations.
- [src/Inventory/Application](../../../src/Inventory/Application): existing single-product use cases.
- [src/Shared/Application/Transaction/TransactionalSession.php](../../../src/Shared/Application/Transaction/TransactionalSession.php): existing transaction abstraction.
- [src/Shared/Infrastructure/Database/MySqlTransactionalSession.php](../../../src/Shared/Infrastructure/Database/MySqlTransactionalSession.php): existing MySQL transaction adapter.
- [src/Shared/Application/Outbox](../../../src/Shared/Application/Outbox): Outbox message and recorder contracts.
- [src/Shared/Infrastructure/Outbox](../../../src/Shared/Infrastructure/Outbox): Outbox persistence, serialization and publication infrastructure.
- [src/Shared/Domain/UuidGenerator.php](../../../src/Shared/Domain/UuidGenerator.php): UUID generator port without a production implementation.
- [app/Providers/OutboxServiceProvider.php](../../../app/Providers/OutboxServiceProvider.php): default connection, transaction and Outbox bindings.
- [app/Providers/AppServiceProvider.php](../../../app/Providers/AppServiceProvider.php): shared application bindings.
- [bootstrap/providers.php](../../../bootstrap/providers.php): registered service providers.
- [config/outbox.php](../../../config/outbox.php): `inventory` is already an allowed producer.
- [database/migrations/2026_11_10_000001_create_outbox_table.php](../../../database/migrations/2026_11_10_000001_create_outbox_table.php): migration and constraint conventions.
- [tests/Unit/Inventory](../../../tests/Unit/Inventory): existing Inventory unit tests and object mothers.
- [tests/Integration/Shared/Infrastructure/Outbox](../../../tests/Integration/Shared/Infrastructure/Outbox): real MySQL transaction and Outbox test conventions.
- [tests/Integration/Shared/Application/Inbox/InboxProcessorDuplicateRaceTest.php](../../../tests/Integration/Shared/Application/Inbox/InboxProcessorDuplicateRaceTest.php): `pcntl_fork`, socket barriers and second-connection concurrency-test conventions.
- [tests/Shared/Infrastructure/PhpUnit/InfrastructureTestCase.php](../../../tests/Shared/Infrastructure/PhpUnit/InfrastructureTestCase.php): integration test base.
- [phpunit.xml](../../../phpunit.xml), [ecs.php](../../../ecs.php) and [phpstan.neon](../../../phpstan.neon): test suites, style and static analysis. The PHPStan baseline must not be extended.

### Current state verified during research

- `src/Inventory` has Domain and Application layers, four command handlers and unit tests. It has no Infrastructure layer, migrations, service provider or production bindings.
- `StockItem` and `StockReservation` have private constructors. Persistence cannot reconstruct them without an approved reconstruction mechanism.
- `StockItem` records domain events for initial registration, stock addition, reservation and reservation release.
- `StockReservation` is immutable, owned by `StockItem`, and `release()` returns a new released instance.
- `StockItemRepository` currently exposes `search`, `searchByProductId`, `searchByReservationId` and `save`.
- `TransactionalSession` and `MySqlOutboxRecorder` already receive the identical default MySQL connection through `OutboxConnectionFactory`.
- `config/outbox.php` already allows the `inventory` producer.
- `UuidGenerator` has no implementation, binding or production use outside mocked unit tests.
- The project runs MySQL 8.0.32, which enforces `CHECK` constraints.
- There is no aggregate versioning in Inventory. OD-002 remains open.
- The current application commands support one product per reservation operation.
- Task004 is Verified and committed. Task006 remains Proposed. No commit, push or pull request may be created without explicit approval.

## 3. Agreed contract decisions

### 3.1 Persistence schema

Create two Inventory-owned tables. All UUID identifiers use MySQL `CHAR(36)`, matching the existing Outbox table. All date-time columns use MySQL `DATETIME` and store UTC wall-clock values.

`inventory_stock_items`:

| Column | Type | Constraints |
|---|---|---|
| `id` | UUID | Primary key |
| `product_id` | UUID | Not null, unique index `inventory_stock_items_product_id_unique` |
| `on_hand` | BIGINT UNSIGNED | Not null |
| `reserved` | BIGINT UNSIGNED | Not null, default 0 |
| `created_at` | DATETIME | Not null, UTC |
| `updated_at` | DATETIME | Not null, UTC |

Additional constraint:

```sql
CHECK (reserved <= on_hand)
```

`inventory_stock_reservations`:

| Column | Type | Constraints |
|---|---|---|
| `id` | UUID | Primary key |
| `stock_item_id` | UUID | Not null, foreign key to `inventory_stock_items.id` |
| `order_id` | UUID | Not null |
| `quantity` | BIGINT UNSIGNED | Not null |
| `status` | VARCHAR(16) | Not null |
| `created_at` | DATETIME | Not null, UTC |
| `updated_at` | DATETIME | Not null, UTC |

Additional constraints and indexes:

```sql
INDEX inventory_stock_reservations_stock_item_id_index (stock_item_id)
FOREIGN KEY inventory_stock_reservations_stock_item_id_foreign (stock_item_id)
    REFERENCES inventory_stock_items (id)
    ON DELETE RESTRICT
CHECK (quantity > 0)
CHECK (status IN ('active', 'released'))
```

Rules:

- Do not persist `available`. It is always calculated as `on_hand - reserved`.
- Do not add a unique constraint on `order_id`. One order can reserve several products.
- Do not add an `order_id` index until a verified query or operational requirement exists.
- Released reservations are retained. Deleting a released reservation is not allowed.
- Application and domain validation remain mandatory even when database constraints exist.
- Convert every date-time to UTC explicitly before formatting, for example with `setTimezone(new DateTimeZone('UTC'))`. Formatting alone does not change the timezone of the received object or compensate for the MySQL session timezone.

### 3.2 Domain reconstruction

Add public named constructors:

```php
StockItem::reconstitute(
    StockItemId $id,
    ProductId $productId,
    Quantity $onHand,
    Quantity $reserved,
    array $reservations
): self

StockReservation::reconstitute(
    ReservationId $id,
    OrderId $orderId,
    Quantity $quantity,
    StockReservationStatus $status
): self
```

The private constructors must validate the complete state:

- `reserved` must not be greater than `onHand`.
- Every reservation must be a `StockReservation`.
- Reservation identifiers must be unique inside the aggregate.
- The sum of active reservation quantities must equal `reserved`.
- Reservation quantities must be strictly positive.
- Reconstruction must not record domain events.

No new domain events are introduced. Existing domain events keep their current names and primitive representations.

### 3.3 Application contracts

Create the Application port:

```php
namespace Manager\Inventory\Application\Stock;

interface StockItemExclusiveReader
{
    public function readByIdForUpdate(StockItemId $id): ?StockItem;

    public function readByProductIdForUpdate(ProductId $productId): ?StockItem;

    public function readByReservationIdForUpdate(ReservationId $reservationId): ?StockItem;
}
```

The `StockItemRepository` domain contract remains unchanged. The MySQL adapter implements both `StockItemRepository` and `StockItemExclusiveReader`. Binding both interfaces to the same singleton guarantees the same connection and mapper.

`StockItemExclusiveReader` contract guarantees:

- Every `...ForUpdate()` call must execute inside an active transaction. The adapter must check `transactionLevel() > 0` and throw `LogicException` before issuing any query when no transaction is active. It must never open a transaction implicitly, because the lock would be released immediately.
- The lock is held until the surrounding transaction commits or rolls back. The adapter must not commit, roll back or start transactions.
- Every mutating use case acquires the owning `StockItem` row lock before changing stock or reservations. No handler may load an existing aggregate through a non-locking repository read and then save it.
- `readByReservationIdForUpdate()` resolves the immutable `stock_item_id` with a non-locking read, then locks the owning `StockItem` row, then loads the owned reservations.
- Reservation rows are read only after the owning `StockItem` row lock is held.
- Registration is the only write path without a pre-existing aggregate row to lock; it still executes inside the same transaction boundary as its Outbox record.

Create the Application services:

```php
StockItemIntegrationEventMapper::map(DomainEvent $event): OutboxMessage

StockItemIntegrationEventRecorder::record(StockItem $stockItem): void

/**
 * @param callable(): list<StockItem> $operation
 */
StockItemTransaction::execute(callable $operation): void
```

`StockItemTransaction` requirements:

- The callable returns a `list<StockItem>` that has already been persisted by the caller.
- It opens `TransactionalSession::execute($callback, 3)`.
- After the callable returns, it records the pending domain events of every returned aggregate through `StockItemIntegrationEventRecorder`.
- It does not save aggregates itself.
- It is Inventory-specific. It is not a generic Unit of Work.

Modify the existing command handlers so that they execute their load, domain mutation, persistence and event recording inside `StockItemTransaction`:

- `RegisterInitialStockCommandHandler`
- `AddStockCommandHandler`
- `ReserveStockCommandHandler`
- `ReleaseStockCommandHandler`

The `StockItemId` for registration and the `ReservationId` for reservation must be generated before the transaction starts, so a transaction retry reuses the same identity.

Create `RamseyUuidGenerator implements UuidGenerator` in Shared Infrastructure and bind `UuidGenerator` to it in `AppServiceProvider`. Leave the obsolete commented legacy binding in that provider untouched.

Create `InventoryServiceProvider` and register it in `bootstrap/providers.php`. It must bind the persistence mapper, the repository and exclusive reader singleton, the integration-event mapper, the recorder and the transaction coordinator. It must tag the Inventory command handlers as `command_handler`.

### 3.4 Transaction and retry

Extend the shared transaction contract:

```php
public function execute(callable $operation, int $attempts = 1): mixed;
```

`MySqlTransactionalSession` requirements:

- Delegate retry to Laravel's `Connection::transaction($callback, $attempts)`.
- Laravel retries only concurrency failures such as deadlocks and lock wait timeouts.
- Business and domain exceptions must not be retried.
- Reject `attempts < 1` with `InvalidArgumentException`.
- Log every retry attempt above the first at warning level through `Psr\Log\LoggerInterface`, including `attempt` and `maxAttempts` context. Track callback invocations to detect retries; Laravel remains responsible for retry decisions and delays.
- Keep the logger optional in the constructor for backward compatibility with existing tests.
- Inject the application logger from `OutboxServiceProvider` in production.

Inventory stock operations use three attempts. Each retry re-executes the callback from the beginning. The callback must reload or reconstruct aggregates inside the callback on every attempt and must never reuse an aggregate instance mutated during a failed attempt. Repository reads are repeated on every attempt under the required locks.

### 3.5 Integration events

Producer: `inventory`  
Aggregate type: `stock_item`  
Contract version: `1`  
Aggregate version: omitted. OD-002 remains open and consumers must not assume ordering guarantees.

| Integration event | Source domain event | Operation-specific payload fields |
|---|---|---|
| `inventory.stock_registered` | `InitialStockRegisteredDomainEvent` | `initialQuantity` |
| `inventory.stock_added` | `StockAddedDomainEvent` | `addedQuantity` |
| `inventory.stock_reserved` | `StockReservedDomainEvent` | `orderId`, `reservationId`, `reservedQuantity` |
| `inventory.stock_released` | `ReservationReleasedDomainEvent` | `orderId`, `reservationId`, `releasedQuantity` |

Every payload also contains the resulting committed state:

- `stockItemId`
- `productId`
- `onHand`
- `reserved`
- `available`

The source events for addition, reservation and release were observed during research to carry the resulting `onHand` and `reserved`. Phase 2 must re-verify their actual properties before finalizing the mapper signature and must stop for review if they differ. `InitialStockRegisteredDomainEvent` carries only `initialQuantity`, so the mapper derives `onHand = initialQuantity`, `reserved = 0` and `available = initialQuantity`. No aggregate state has to be passed to the mapper.

Rules:

- `available` equals `onHand - reserved`.
- The Outbox `eventId` is the domain event `eventId`.
- The Outbox `occurred_at` comes from the domain event `occurredOn`.
- `correlationId` and `causationId` are omitted until an application-level correlation contract exists.
- Domain event names are not reused as integration event names. The external contract is explicit and independent.
- The recorder uses the existing `OutboxRecorder` and `MySqlOutboxRecorder`. Do not create another publisher.
- No RabbitMQ I/O executes inside the Inventory transaction.

### 3.6 Multi-product reservation

Create an additive Application contract:

```php
final readonly class ReserveStockLine
{
    public function __construct(public string $productId, public int $quantity) {}
}

final readonly class ReserveStockBatchCommand implements Command
{
    /**
     * @param list<ReserveStockLine> $lines
     */
    public function __construct(public string $orderId, public array $lines) {}
}

ReserveStockBatchCommandHandler::__invoke(ReserveStockBatchCommand $command): void
```

Handler requirements:

- Reject an empty line list.
- Reject duplicate `productId` values.
- Validate every `ProductId` and `Quantity` before starting the transaction.
- Generate one `ReservationId` per line before starting the transaction.
- Sort lines by `productId` before acquiring locks.
- Load every `StockItem` through `StockItemExclusiveReader::readByProductIdForUpdate()` in sorted order.
- Throw `StockItemNotFoundException` if any product does not exist.
- Apply every reservation before saving any aggregate.
- Save every aggregate in the same transaction.
- Return no synchronous result. Reservation identifiers are published in `inventory.stock_reserved` events.
- A failure in any line rolls back every reservation, counter and Outbox record from the operation.

The synchronous contract that Orders may need to obtain reservation identifiers belongs to OD-001. Reservation-request idempotency is documented as an open decision, not invented in this task.

### 3.7 Open decisions

- OD-001 remains Open. Task006 does not introduce atomicity between Orders persistence and Inventory reservations.
- OD-002 remains Open. Inventory publishes without `aggregateVersion`.
- OD-003 becomes Decided when the approved event catalogue is implemented and documented.
- Add OD-006: Reservation Request Idempotency, Open. It must define the identity and retry semantics of a reservation request before Orders confirmation relies on Inventory reservations.

### 3.8 Pre-implementation verification gates

Two assumptions must be verified before the contracts that depend on them are finalized:

- Domain-event payload shape: before finalizing `StockItemIntegrationEventMapper`, re-inspect `InitialStockRegisteredDomainEvent`, `StockAddedDomainEvent`, `StockReservedDomainEvent` and `ReservationReleasedDomainEvent`. Confirm that addition, reservation and release expose the resulting `onHand` and `reserved`, and that initial registration exposes `initialQuantity`. If the real properties differ, stop Phase 2 and revise the integration-event contract before implementing.
- Retry object lifecycle: before implementing retries, verify that every attempt performs a fresh load or reconstruction of the aggregates inside the callback. A retry must never reuse an aggregate instance that was already mutated during a failed attempt. Cover this with a test that forces one retry and asserts that the operation is reapplied to freshly loaded state.

Both gates must be closed before Phase 2 finalizes its public contracts. If either fails, stop implementation and revise the affected contract instead of adapting the contract silently.

## 4. Phases

### Phase 1: MySQL persistence foundation for StockItem aggregates

#### Description

Deliver the Inventory-owned persistence schema, Eloquent models, persistence mapper, repository adapter, exclusive-reader port and domain reconstruction mechanism. The phase proves that complete aggregates, including active and released reservations, can be saved, locked, reloaded and validated against real MySQL.

No command handler or Outbox integration is included in this phase.

#### Public contracts

- Database schema for `inventory_stock_items` and `inventory_stock_reservations`.
- Domain reconstruction methods on `StockItem` and `StockReservation`.
- `Manager\Inventory\Infrastructure\Persistence\Eloquent\Models\StockItemEloquentModel`.
- `Manager\Inventory\Infrastructure\Persistence\Eloquent\Models\StockReservationEloquentModel`.
- `Manager\Inventory\Application\Stock\StockItemExclusiveReader` with its transaction-bound locking guarantees.
- `Manager\Inventory\Infrastructure\Persistence\Repositories\MySqlStockItemRepository` implements `StockItemRepository` and `StockItemExclusiveReader`.
- `Manager\Inventory\Infrastructure\Persistence\Mappers\StockItemPersistenceMapper`.
- `App\Providers\InventoryServiceProvider` with repository, exclusive reader and mapper bindings.
- Integration test base for Inventory persistence cleanup.

#### To-do actions

- [x] Create `database/migrations/2026_11_19_000001_create_inventory_stock_items_table.php` with the approved columns, unique product index and `reserved <= on_hand` constraint. Add the `CHECK` constraint with a raw `ALTER TABLE` statement, following the existing Outbox migration.
- [x] Create `database/migrations/2026_11_19_000002_create_inventory_stock_reservations_table.php` with the approved columns, `stock_item_id` index, foreign key with `ON DELETE RESTRICT`, positive quantity constraint and status constraint. Add the `CHECK` constraints with raw `ALTER TABLE` statements.
- [x] Add `StockItem::reconstitute()` and validate `reserved <= onHand`, unique reservation identifiers and `reserved` equal to the sum of active reservation quantities.
- [x] Add `StockReservation::reconstitute()` and validate positive quantities and supported statuses.
- [x] Ensure reconstruction never records domain events.
- [x] Create `StockItemEloquentModel` and `StockReservationEloquentModel` with explicit table names, string primary keys, no automatic timestamps and the owning relationship.
- [x] Create `StockItemPersistenceMapper` to translate between Eloquent models and complete domain aggregates and to build UTC persistence rows.
- [x] Create `MySqlStockItemRepository` implementing `StockItemRepository` and `StockItemExclusiveReader`.
- [x] Enforce the exclusive-reader transaction guard: throw `LogicException` when `transactionLevel() === 0`, before issuing any query, and never start, commit or roll back a transaction inside the adapter.
- [x] Make non-locking searches start from a normal read. Locking searches must lock the stock-item row first, hold that lock until the surrounding transaction ends, and only then load the owned reservation rows.
- [x] For `readByReservationIdForUpdate()`, resolve the immutable `stock_item_id` without blocking, then lock the owning stock item before loading reservations.
- [x] Implement `save()` as an explicit update-or-insert by aggregate identifier. Do not use MySQL `ON DUPLICATE KEY UPDATE` for stock items because a product-id conflict could overwrite the wrong aggregate.
- [x] Preserve `created_at` on updates and set `updated_at` on every save. Convert every `DateTimeImmutable` to UTC explicitly with `setTimezone(new DateTimeZone('UTC'))` and then format `Y-m-d H:i:s`; formatting alone does not change the timezone.
- [x] Map a duplicate `inventory_stock_items_product_id_unique` violation to `StockItemAlreadyExistsException`. Rethrow any other unique violation.
- [x] Persist active and released reservation statuses without deleting released reservations.
- [x] Persist reservations with per-entity update-or-insert by reservation identifier. Never delete and reinsert the collection, because that would reset the preserved `created_at`.
- [x] Create `InventoryServiceProvider`, register it in `bootstrap/providers.php` and bind `StockItemRepository` and `StockItemExclusiveReader` to the same MySQL adapter singleton using `OutboxConnectionFactory` as the default connection source.
- [x] Create `tests/Integration/Inventory/InventoryIntegrationTestCase.php` to track created stock items, reservations and Outbox event identifiers and to clean only the records created by each test.
- [x] Add the Phase 1 test suites and cases listed below.
- [x] Run the Inventory and schema migrations against the `testing` database.
- [x] Record the phase verification in `docs/ai/implementation-log.md`.

#### Tests

Unit, `tests/Unit/Inventory/Domain/StockItemTest.php`:

- [x] Reconstitute a stock item with active and released reservations.
- [x] Reject reconstruction when `reserved` exceeds `onHand`.
- [x] Reject reconstruction when `reserved` does not equal the sum of active reservation quantities.
- [x] Reject reconstruction with duplicate reservation identifiers.
- [x] Verify reconstruction records no domain events.
- [x] Keep every existing domain test passing.

Unit, `tests/Unit/Inventory/Domain/StockReservationTest.php`:

- [x] Reconstitute an active reservation.
- [x] Reconstitute a released reservation.
- [x] Reject reconstruction with a non-positive quantity.
- [x] Keep every existing reservation test passing.

Integration, `tests/Integration/Inventory/Infrastructure/Persistence/MySqlStockItemRepositoryTest.php`:

- [x] Save and retrieve a stock item by `StockItemId`.
- [x] Retrieve a stock item by `ProductId`.
- [x] Find the owning stock item by `ReservationId`.
- [x] Return `null` for unknown stock item, product and reservation identifiers.
- [x] Enforce one stock item per product and map the duplicate-product violation to `StockItemAlreadyExistsException`.
- [x] Persist and reconstruct active reservations.
- [x] Persist and reconstruct released reservations.
- [x] Update `on_hand` and `reserved` counters.
- [x] Update reservation status without changing `created_at`.
- [x] Preserve the complete aggregate state after reload.
- [x] Lock the stock item row when an exclusive read is requested and make a second connection wait for the lock.
- [x] Reject an exclusive read invoked outside an active transaction with `LogicException` before issuing any query.
- [x] Hold the stock-item lock until the surrounding transaction commits or rolls back for every exclusive-reader method.
- [x] Store `created_at` and `updated_at` as UTC wall-clock values while the MySQL session timezone is not UTC.
- [x] Convert a timestamp supplied in a non-UTC timezone to the equivalent UTC value before persisting.

Integration, `tests/Integration/Inventory/Infrastructure/Persistence/InventorySchemaConstraintsTest.php`:

- [x] Reject a negative `on_hand` value.
- [x] Reject a negative `reserved` value.
- [x] Reject `reserved > on_hand`.
- [x] Reject a zero reservation quantity.
- [x] Reject a negative reservation quantity.
- [x] Reject an unknown reservation status.
- [x] Accept `active` and `released` statuses.
- [x] Reject deleting a stock item that still has reservations.
- [x] Enforce the unique `product_id` index.

#### Verification

- [x] Run `docker compose exec -T app php artisan migrate --env=testing --force`.
- [x] Run `docker compose exec -T app php vendor/bin/phpunit tests/Unit/Inventory tests/Integration/Inventory`.
- [x] Run `docker compose exec -T app php vendor/bin/ecs check` over the changed paths and then over the full project if the changed paths pass.
- [x] Run `docker compose exec -T app php vendor/bin/phpstan analyse --no-progress --memory-limit=1G`.
- [x] Verify that no PHPStan baseline entry was added, removed or modified.
- [x] Verify that no Orders, Catalog or User persistence code was changed.

#### Stop

- [x] STOP. Present the full diff, migration status and verification results for review. Suggest the commit message `feat(inventory): add stock item persistence foundation`. Do not proceed to Phase 2 until the user explicitly asks.

#### Phase 1 verification evidence

- `php artisan migrate --env=testing --force`: both Inventory migrations applied successfully.
- `phpunit tests/Unit/Inventory tests/Integration/Inventory`: 193 tests, 427 assertions, OK.
- `phpunit tests/Integration/Shared/Infrastructure/Outbox` regression: 46 tests, 405 assertions, OK.
- Targeted ECS over every changed path: OK. Full-project ECS still reports 46 pre-existing legacy findings in untouched files.
- PHPStan level 5: OK, no errors and no baseline changes.
- `phpstan-baseline.neon` was not modified. No Orders, Catalog or User persistence code was changed.

### Phase 2: Atomic stock registration and addition with Outbox

#### Description

Connect the persistence foundation to the existing transaction and Outbox infrastructure. Deliver end-to-end `RegisterInitialStock` and `AddStock` operations that persist the stock change and the corresponding `inventory.stock_registered` or `inventory.stock_added` Outbox record atomically.

#### Public contracts

- `TransactionalSession::execute(callable $operation, int $attempts = 1): mixed`.
- `MySqlTransactionalSession` bounded retry, retry logging and transaction cleanup.
- `Manager\Inventory\Application\Stock\StockItemTransaction`.
- `Manager\Inventory\Application\IntegrationEvent\StockItemIntegrationEventMapper`.
- `Manager\Inventory\Application\IntegrationEvent\StockItemIntegrationEventRecorder`.
- Modified `RegisterInitialStockCommandHandler` and `AddStockCommandHandler`.
- `Manager\Shared\Infrastructure\Uuid\RamseyUuidGenerator`.
- `UuidGenerator` binding in `AppServiceProvider`.
- Expanded `InventoryServiceProvider` bindings and command-handler tags.

#### To-do actions

- [x] Extend `TransactionalSession` with the optional `attempts` parameter.
- [x] Update `MySqlTransactionalSession` to validate `attempts`, delegate retry to Laravel and log every retry above the first at warning level with `attempt` and `maxAttempts` context.
- [x] Inject the application logger into `MySqlTransactionalSession` from `OutboxServiceProvider` while keeping the constructor compatible with existing tests.
- [x] Create `RamseyUuidGenerator` and bind `UuidGenerator` to it in `AppServiceProvider`; do not uncomment the obsolete legacy binding.
- [x] Re-verify the actual payload properties of the four domain events before finalizing the mapper. Stop and revise the contract if they differ from Section 3.5.
- [x] Create `StockItemIntegrationEventMapper` and map `InitialStockRegisteredDomainEvent` to `inventory.stock_registered` and `StockAddedDomainEvent` to `inventory.stock_added`.
- [x] Build payloads with the resulting state and `available`. Use `JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`.
- [x] Reject unsupported domain events with a clear `LogicException`.
- [x] Create `StockItemIntegrationEventRecorder` using the existing `OutboxRecorder` port.
- [x] Create `StockItemTransaction` with the three-attempt bound and event recording after the operation returns.
- [x] Ensure every transactional callback reloads or reconstructs its aggregates on each attempt and never captures an aggregate mutated during a previous attempt.
- [x] Modify `RegisterInitialStockCommandHandler` to generate the `StockItemId` before the transaction, execute inside `StockItemTransaction`, persist through `StockItemRepository::save()` and return the aggregate.
- [x] Modify `AddStockCommandHandler` to read through `StockItemExclusiveReader::readByProductIdForUpdate()` inside `StockItemTransaction`, apply the domain operation, persist and return the aggregate.
- [x] Register and tag both handlers in `InventoryServiceProvider`.
- [x] Add the Phase 2 test suites and cases listed below.
- [x] Record the phase verification in `docs/ai/implementation-log.md`.

#### Tests

Unit, `tests/Unit/Inventory/Application/Stock/StockItemTransactionTest.php`:

- [x] Execute the operation through `TransactionalSession::execute()` with three attempts.
- [x] Record one batch of events for every returned stock item.
- [x] Propagate operation failures without recording events.
- [x] Propagate recorder failures.
- [x] Do not record anything when the operation returns an empty list.

Unit, `tests/Unit/Inventory/Application/IntegrationEvent/StockItemIntegrationEventMapperTest.php`:

- [x] Map `InitialStockRegisteredDomainEvent` to `inventory.stock_registered`.
- [x] Map `StockAddedDomainEvent` to `inventory.stock_added`.
- [x] Assert producer, aggregate type, contract version, event identifier and occurrence timestamp.
- [x] Assert resulting `onHand`, `reserved` and `available` values.
- [x] Assert the exact JSON payload text for fixed event identifiers and timestamps.
- [x] Reject an unsupported domain event.

Unit, `tests/Unit/Inventory/Application/IntegrationEvent/StockItemIntegrationEventRecorderTest.php`:

- [x] Pull domain events and append one Outbox message per event.
- [x] Clear the aggregate events after recording.
- [x] Propagate mapping failures.

Unit, `tests/Unit/Inventory/Application/RegisterInitialStock/RegisterInitialStockCommandHandlerTest.php`:

- [x] Register initial stock and record the event through the transaction coordinator.
- [x] Generate the stock item identifier before the transaction.
- [x] Reject an existing product without saving.
- [x] Propagate repository failures.
- [x] Keep every existing invalid-input test passing.

Unit, `tests/Unit/Inventory/Application/AddStock/AddStockCommandHandlerTest.php`:

- [x] Add stock through the exclusive reader and transaction coordinator.
- [x] Reject an unknown product.
- [x] Reject non-positive quantities without accessing persistence.
- [x] Keep every existing invalid-input test passing.

Unit, `tests/Unit/Shared/Infrastructure/Uuid/RamseyUuidGeneratorTest.php`:

- [x] Generate a valid UUID on every call.

Integration, `tests/Integration/Shared/Infrastructure/Outbox/MySqlTransactionalSessionTest.php` (extend the existing suite):

- [x] Reject `attempts < 1` with `InvalidArgumentException`.
- [x] Retry a retryable deadlock or lock-wait failure and complete on a later attempt.
- [x] Do not retry a business exception.
- [x] Log every retry above the first at warning level with `attempt` and `maxAttempts` context.
- [x] Rethrow the final error after attempts are exhausted.
- [x] Leave no open transaction after success or failure.
- [x] Keep every existing transaction test passing.

Integration, `tests/Integration/Inventory/Application/StockRegistrationAndAdditionAtomicityTest.php`:

- [x] Commit stock registration and its Outbox record together.
- [x] Commit stock addition and its Outbox record together.
- [x] Roll back the stock row when the Outbox write fails.
- [x] Roll back the stock row and any reservation rows when a repository write fails inside the transaction.
- [x] Roll back the Outbox record when the business operation fails.
- [x] Leave no Outbox record when domain validation fails.
- [x] Persist the `inventory.stock_registered` payload with the resulting committed state.
- [x] Persist the `inventory.stock_added` payload with the resulting committed state.
- [x] Verify the repository, transaction session and recorder use the same default connection.
- [x] Verify no RabbitMQ publication occurs inside the transaction and only pending Outbox records exist after commit.
- [x] Verify there is no open transaction after success or failure.
- [x] Force one retry and verify the aggregate is reloaded and the operation is reapplied from fresh state, not from a mutated instance.

Regression:

- [x] Keep every existing Outbox and `TransactionalSession` test passing.
- [x] Verify the legacy User context and `RabbitMqEventBus` behavior are unchanged.

#### Verification

- [x] Run `docker compose exec -T app php vendor/bin/phpunit tests/Unit/Inventory tests/Unit/Shared/Infrastructure/Uuid tests/Integration/Inventory tests/Integration/Shared/Infrastructure/Outbox`.
- [x] Run `docker compose exec -T app php vendor/bin/ecs check` over the changed paths and then over the full project if the changed paths pass.
- [x] Run `docker compose exec -T app php vendor/bin/phpstan analyse --no-progress --memory-limit=1G`.
- [x] Verify that no PHPStan baseline entry was added, removed or modified.
- [x] Verify that the Outbox producer remains `inventory` and no other publisher was introduced.

#### Stop

- [x] STOP. Present the full diff, commit and rollback test evidence and verification results for review. Suggest the commit message `feat(inventory): persist stock registration and additions atomically`. Do not proceed to Phase 3 until the user explicitly asks.

#### Phase 2 verification evidence

- `phpunit tests/Unit/Inventory tests/Unit/Shared/Infrastructure/Uuid tests/Integration/Inventory tests/Integration/Shared/Infrastructure/Outbox`: 267 tests, 974 assertions, OK.
- `phpunit tests/Api/User` regression: 12 tests, 12 assertions, OK.
- Targeted ECS over the changed paths: OK, except for the pre-existing legacy formatting findings in `app/Providers/AppServiceProvider.php`; the new lines in that file are style-compliant and the full-project finding count remains 46.
- PHPStan level 5: OK, no errors and no baseline changes.
- Container verification: `RegisterInitialStockCommandHandler` and `AddStockCommandHandler` are tagged as `command_handler`; `UuidGenerator` resolves to `RamseyUuidGenerator` through an explicit binding at the top level of `AppServiceProvider::register()`.
- Retry gate: the atomicity test forces one deadlock and observes two distinct freshly loaded aggregates, two saves, a final `on_hand` of 15 and exactly one `inventory.stock_added` Outbox record.
- No Orders, Catalog or User persistence code was changed. The only publisher path added is the existing `OutboxRecorder` with the `inventory` producer.

### Phase 3: Row-locked reservation and release operations

#### Description

Deliver end-to-end single-product `ReserveStock` and `ReleaseStock` operations with row-level locking, atomic reservation persistence, Outbox recording and real MySQL concurrency tests.

#### Public contracts

- `inventory.stock_reserved` and `inventory.stock_released` integration-event contracts.
- Modified `ReserveStockCommandHandler` and `ReleaseStockCommandHandler`.
- Expanded `StockItemIntegrationEventMapper`.
- No new repository, transaction or publisher abstraction.

#### To-do actions

- [x] Extend `StockItemIntegrationEventMapper` with `StockReservedDomainEvent` to `inventory.stock_reserved` and `ReservationReleasedDomainEvent` to `inventory.stock_released`.
- [x] Include `orderId`, `reservationId`, the operation quantity, `onHand`, `reserved` and `available`.
- [x] Modify `ReserveStockCommandHandler` to generate the `ReservationId` before the transaction, read through `StockItemExclusiveReader::readByProductIdForUpdate()`, reserve, persist and return the aggregate inside `StockItemTransaction`.
- [x] Modify `ReleaseStockCommandHandler` to read through `StockItemExclusiveReader::readByReservationIdForUpdate()`, release, persist and return the aggregate inside `StockItemTransaction`.
- [x] Ensure a release operation always locks the owning stock item before loading its reservations.
- [x] Ensure a reservation failure leaves no reservation row, no counter change and no success Outbox record.
- [x] Ensure a release failure leaves the reservation active, the reserved counter unchanged and no success Outbox record.
- [x] Add the Phase 3 test suites and cases listed below.
- [x] Record the phase verification in `docs/ai/implementation-log.md`.

#### Tests

Unit, `tests/Unit/Inventory/Application/ReserveStock/ReserveStockCommandHandlerTest.php`:

- [x] Reserve stock through the exclusive reader and transaction coordinator.
- [x] Generate the reservation identifier before the transaction.
- [x] Reject an unknown product without saving.
- [x] Reject insufficient stock without saving or recording events.
- [x] Propagate invalid quantities before accessing persistence.
- [x] Keep every existing test passing.

Unit, `tests/Unit/Inventory/Application/ReleaseStock/ReleaseStockCommandHandlerTest.php`:

- [x] Release a reservation through the exclusive reader and transaction coordinator.
- [x] Reject an unknown reservation without saving.
- [x] Propagate `ReservationAlreadyReleasedException`.
- [x] Keep every existing test passing.

Unit, `tests/Unit/Inventory/Application/IntegrationEvent/StockItemIntegrationEventMapperTest.php`:

- [x] Map `StockReservedDomainEvent` to `inventory.stock_reserved`.
- [x] Map `ReservationReleasedDomainEvent` to `inventory.stock_released`.
- [x] Assert resulting state, operation quantities and exact JSON payloads.

Integration, `tests/Integration/Inventory/Application/StockReservationAndReleaseAtomicityTest.php`:

- [x] Commit a reservation and its Outbox record together.
- [x] Roll back the reservation when the Outbox write fails.
- [x] Commit a release and its Outbox record together.
- [x] Roll back the release when the Outbox write fails.
- [x] Persist the `inventory.stock_reserved` payload with the committed state.
- [x] Persist the `inventory.stock_released` payload with the committed state.
- [x] Leave no success Outbox record when the reservation fails.
- [x] Preserve the identity and status of a released reservation after reload.

Integration, `tests/Integration/Inventory/Application/StockReservationConcurrencyTest.php`:

- [x] Allow only one of two competing reservations for the remaining stock.
- [x] Never persist `reserved > on_hand`.
- [x] Preserve consistency when stock is added concurrently with a reservation.
- [x] Locate the owning stock item through `readByReservationIdForUpdate()` before releasing.
- [x] Hold the owning stock-item lock while a concurrent release waits.
- [x] Make the waiting release observe the latest committed state after it acquires the lock.
- [x] Reject a second release of the same reservation.
- [x] Make the second reservation wait for the stock-item lock and then evaluate the latest committed state.
- [x] Assert the persisted stock counters and reservation rows after every concurrent scenario.
- [x] Use a second real MySQL connection and process-level synchronization following `InboxProcessorDuplicateRaceTest.php`.
- [x] Skip process-level tests only when `pcntl` is unavailable, and document the skip.

Regression:

- [x] Keep every existing Inventory domain and application test passing.
- [x] Keep the Phase 2 registration and addition tests passing.
- [x] Keep every existing Outbox test passing.

#### Verification

- [x] Run `docker compose exec -T app php vendor/bin/phpunit tests/Unit/Inventory tests/Integration/Inventory tests/Integration/Shared/Infrastructure/Outbox`.
- [x] Run `docker compose exec -T app php vendor/bin/ecs check` over the changed paths and then over the full project if the changed paths pass.
- [x] Run `docker compose exec -T app php vendor/bin/phpstan analyse --no-progress --memory-limit=1G`.
- [x] Verify that no PHPStan baseline entry was added, removed or modified.
- [x] Verify that no RabbitMQ network operation executes inside a reservation transaction.

#### Stop

- [x] STOP. Present the full diff, concurrency test evidence and verification results for review. Suggest the commit message `feat(inventory): reserve and release stock under row locks`. Do not proceed to Phase 4 until the user explicitly asks.

#### Phase 3 verification evidence

- `phpunit tests/Unit/Inventory`: 179 tests, 405 assertions, OK.
- `phpunit tests/Integration/Inventory`: 53 tests, 297 assertions, OK, including the atomicity and `pcntl_fork` concurrency suites on real MySQL connections.
- Full `phpunit`: 656 tests, 2.285 assertions, OK.
- Targeted ECS over every changed path: OK. Full-project ECS still reports the same 46 pre-existing legacy findings in untouched files; none belongs to a changed file.
- PHPStan level 5: OK, no errors and `phpstan-baseline.neon` unchanged.
- Concurrency evidence: two competing reservations for the remaining stock leave exactly one active reservation, one `inventory.stock_reserved` Outbox record and `reserved <= on_hand`; concurrent stock addition and reservation converge to `on_hand = 15` with both events; a waiting reservation re-evaluates the latest committed state after the lock is released; a waiting release observes the newly committed `on_hand`; a second release of the same reservation is rejected. The lock-wait tests synchronize through `information_schema.processlist`: while the parent holds the row lock, they wait until the worker's `FOR UPDATE` statement on `inventory_stock_items` is visible, so no fixed sleep or elapsed-time assertion infers the wait.
- Atomicity evidence: reservation and release commits persist their Outbox record together; an Outbox failure rolls back the reservation row, the released status and the counters; a failed reservation leaves no success Outbox record.
- Both handlers are tagged as `command_handler`; both use `StockItemExclusiveReader` inside `StockItemTransaction`, and no repository, transaction or publisher abstraction was added.
- No Orders, Catalog or User persistence code was changed. The existing `OutboxRecorder` remains the only publisher path with the `inventory` producer and no RabbitMQ I/O runs inside the transaction.
- Process-level tests skip with an explicit message only when `pcntl_fork` is unavailable; it was available in the verification environment.

### Phase 4: Multi-product reservations and delivery closure

#### Description

Deliver atomic multi-product reservations with deterministic lock ordering, batch-level retry verification and the final Inventory integration-event documentation. Close Task006 after full verification and explicit review approval.

The transaction retry infrastructure itself is delivered and tested in Phase 2. This phase verifies that batch operations behave correctly when that infrastructure retries them.

#### Public contracts

- `ReserveStockBatchCommand`.
- `ReserveStockLine`.
- `ReserveStockBatchCommandHandler`.
- Batch retry semantics: a retried batch preserves pre-generated identities and applies exactly once.
- Public integration-event catalogue documented in `docs/architecture/inventory-integration-events.md`.
- OD-003 marked Decided and OD-006 added as Open.
- Task006 delivery record and status.

#### To-do actions

- [ ] Create `ReserveStockLine` and `ReserveStockBatchCommand` with the approved signatures.
- [ ] Create `ReserveStockBatchCommandHandler` with empty-list validation, duplicate-product rejection, pre-transaction identity generation and product-id sorting.
- [ ] Load all stock items through `StockItemExclusiveReader::readByProductIdForUpdate()` in sorted order.
- [ ] Apply every reservation before saving any aggregate.
- [ ] Save every aggregate inside one `StockItemTransaction` execution.
- [ ] Return no synchronous result.
- [ ] Verify that a retried batch reloads fresh aggregates, reuses every pre-generated reservation identifier and applies each reservation exactly once.
- [ ] Tag the batch handler as `command_handler` in `InventoryServiceProvider`.
- [ ] Add the Phase 4 test suites and cases listed below.
- [ ] Create `docs/architecture/inventory-integration-events.md` with the approved producer, aggregate, event names, version, payloads, mapping, ordering limitations and Outbox guarantees.
- [ ] Update `docs/README.md` to link the new integration-events document and Task006.
- [ ] Update `docs/architecture/open-decisions.md`: mark OD-003 Decided, add OD-006 as Open and record that Task006 publishes without `aggregateVersion`.
- [ ] Update `docs/tasks/README.md` and `docs/tasks/006-inventory-persistence-and-stock-reservation.md` with the delivery record, verification evidence and final status after explicit review approval.
- [ ] Update `docs/ai/implementation-log.md` with the phase and final verification record.

#### Tests

Unit, `tests/Unit/Inventory/Application/ReserveStockBatch/ReserveStockBatchCommandHandlerTest.php`:

- [ ] Reserve multiple products in one transaction.
- [ ] Sort lines by `productId` before calling the exclusive reader.
- [ ] Reject an empty request before accessing persistence.
- [ ] Reject duplicate products before accessing persistence.
- [ ] Reject an unknown product and leave no saves.
- [ ] Reject insufficient stock in any line and leave no saves.
- [ ] Generate every reservation identifier before the transaction.
- [ ] Return all reserved stock items from the operation callback so the transaction coordinator can record their events.
- [ ] Return no synchronous result.

Integration, `tests/Integration/Inventory/Application/StockBatchReservationTest.php`:

- [ ] Reserve multiple products atomically.
- [ ] Roll back every product when one reservation fails.
- [ ] Write one Outbox record per reserved product.
- [ ] Leave no partial counters, reservations or success Outbox records after rollback.
- [ ] Complete two concurrent batches with reversed input order without a deadlock because locks are sorted by product identifier.
- [ ] Assert every persisted counter and reservation after the batch completes.

Integration, `tests/Integration/Inventory/Application/StockBatchRetryTest.php`:

- [ ] Retry a batch operation after a retryable deadlock or lock-wait failure.
- [ ] Apply the complete batch exactly once after a retry.
- [ ] Preserve every pre-generated reservation identifier across retries.
- [ ] Do not create duplicate reservations after a retry.
- [ ] Do not create duplicate Outbox records after a retry.
- [ ] Reload fresh aggregates on every retry instead of reusing mutated instances.
- [ ] Stop after three attempts, rethrow the final failure and leave no partial counters, reservations or Outbox records.
- [ ] Leave no open transaction after exhausted retries.
- [ ] Do not retry a business validation failure in a batch.

Regression:

- [ ] Run the complete PHPUnit suite.
- [ ] Keep every existing Inventory, Shared, Outbox, Inbox and API test passing.
- [ ] Verify Task006 acceptance criteria item by item.
- [ ] Verify no Orders, Catalog or User persistence is accessed.
- [ ] Verify no cross-context transaction, Event Sourcing, warehouse support, Redis or generic workflow engine is introduced.

#### Verification

- [ ] Run `docker compose exec -T app php artisan migrate:status --env=testing`.
- [ ] Run `docker compose exec -T app php vendor/bin/phpunit`.
- [ ] Run `docker compose exec -T app php vendor/bin/ecs check`.
- [ ] Run `docker compose exec -T app php vendor/bin/phpstan analyse --no-progress --memory-limit=1G`.
- [ ] Verify that no PHPStan baseline entry was added, removed or modified.
- [ ] Verify that the full applicable test suite was executed, not inferred.
- [ ] Verify that no commit, push or pull request was created without explicit approval.

#### Stop

- [ ] STOP. Present the final diff, full verification results, acceptance-criteria checklist and documentation changes for review. Suggest the commit message `feat(inventory): support atomic multi-product reservations` and, if documentation is separated, `docs(inventory): document integration events and close task 006`. Do not mark Task006 as Verified or proceed to any follow-up task until the user explicitly approves.

## 5. Next step

Phase 3 is implemented, verified and awaiting explicit review approval. Do not commit and do not start Phase 4 until the user asks. Phase 4 covers multi-product reservations, the integration-event catalogue and Task006 closure.

Transactions retried safely thanks to [Codely](https://codely.com) AI tooling. 🔁 🐢 💨
