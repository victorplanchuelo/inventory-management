---
name: "inventory-foundation"
description: "Implement the Inventory bounded context domain model and application use cases for initial stock, stock addition, reservation and release."
created_at: "2026-11-08T17:07:38Z"

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

last_implementation_at: "2026-11-08T18:25:17Z"
has_completed_all_phases: "false"
---

# Inventory Domain and Application Foundation

## Goal

Implement the Inventory bounded context domain model and application use cases for initial stock registration, stock addition, stock reservation and reservation release.

Keep the domain independent from Laravel, Eloquent, MySQL, RabbitMQ, Symfony Messenger and HTTP. Do not introduce persistence, API endpoints, Outbox, Inbox, projections or Orders implementation.

## Context

- [AGENTS.md](../../../AGENTS.md): project workflow, architecture constraints, testing expectations and static-analysis rules.
- [docs/README.md](../../../docs/README.md): documentation index and reading order.
- [Task 001 specification](../../../docs/tasks/001-inventory-foundation.md): objective, scope, domain rules, proposed structure, testing requirements and acceptance criteria.
- [Architectural reference](../../../docs/architecture/architectural-reference.md): Domain, Application and Infrastructure responsibilities.
- [Architecture](../../../docs/architecture/architecture.md): modular monolith and bounded-context organization.
- [Domain model](../../../docs/architecture/domain-model.md): inventory ownership, stock reservation and quantity decisions.
- [Requirements](../../../docs/project/requirements.md): stock management and reservation requirements.
- [ADR 001](../../../docs/adr/001-modular-monolith.md): modular-monolith decision.
- [ADR 002](../../../docs/adr/002-atomic-stock-reservation.md): future atomic reservation and persistence concerns, which remain out of scope for this task.
- [Shared Uuid](../../../src/Shared/Domain/ValueObject/Uuid.php): abstract UUID Value Object with final constructor and validation.
- [Shared SimpleUuid](../../../src/Shared/Domain/ValueObject/SimpleUuid.php): final generic UUID, which must not be extended by Inventory identifiers.
- [Shared IntValueObject](../../../src/Shared/Domain/ValueObject/IntValueObject.php): integer Value Object base without range validation.
- [Shared AggregateRoot](../../../src/Shared/Domain/Aggregate/AggregateRoot.php): aggregate base with `record()` and `pullDomainEvents()`.
- [Shared DomainEvent](../../../src/Shared/Domain/Bus/Event/DomainEvent.php): UUID-compatible internal domain-event contract.
- [Shared DomainError](../../../src/Shared/Domain/DomainError.php): base class for domain errors.
- [Shared UuidGenerator](../../../src/Shared/Domain/UuidGenerator.php): UUID generation port used by Application.
- [Shared Command contracts](../../../src/Shared/Domain/Bus/Command): `Command` and `CommandHandler` interfaces.
- [User context](../../../src/Api/User): historical reference only; do not reproduce its framework coupling or other documented limitations.
- [Existing Unit tests](../../../tests/Unit): PHPUnit and Mockery conventions established by Task 002.
- [phpunit.xml](../../../phpunit.xml): Unit, Integration and Api suites.
- [ecs.php](../../../ecs.php): coding-style configuration.
- [phpstan.neon](../../../phpstan.neon): static-analysis configuration and baseline.

The project uses the `Manager\` PSR-4 mapping to `src/`. Inventory is a new bounded context under `src/Inventory`.

## Phases

### Phase 1: Inventory identity, Quantity and initial stock registration

**Description:** Introduce the Inventory bounded context with typed UUID identifiers, the Quantity Value Object and the `StockItem` aggregate registration behavior. Deliver the `RegisterInitialStock` use case and its unit tests as the first functional slice.

**Public contracts:**

- `Manager\Inventory\Domain\ValueObject\StockItemId extends Manager\Shared\Domain\ValueObject\Uuid`
- `Manager\Inventory\Domain\ValueObject\ProductId extends Manager\Shared\Domain\ValueObject\Uuid`
- `Manager\Inventory\Domain\ValueObject\ReservationId extends Manager\Shared\Domain\ValueObject\Uuid`
- `Manager\Inventory\Domain\ValueObject\OrderId extends Manager\Shared\Domain\ValueObject\Uuid`
  - Inventory identifiers inherit UUID validation and expose only the inherited behavior. They must not extend `SimpleUuid`.
  - Identifiers are constructed from UUID strings supplied by Application.
- `Manager\Inventory\Domain\ValueObject\Quantity extends Manager\Shared\Domain\ValueObject\IntValueObject`:
  - `__construct(int $value)`
  - `isPositive(): bool`
  - Rejects negative values with `InvalidQuantityException`.
  - Permits zero.
  - Aggregate operations receive an already valid `Quantity` and only need to reject zero.
- `Manager\Inventory\Domain\StockItem extends Manager\Shared\Domain\Aggregate\AggregateRoot`:
  - `public static function register(StockItemId $id, ProductId $productId, Quantity $initialQuantity): self`
  - `public function id(): StockItemId`
  - `public function productId(): ProductId`
  - `public function onHand(): Quantity`
  - `public function reserved(): Quantity`
  - `public function available(): Quantity`
  - `register()` rejects zero quantities with `QuantityMustBePositiveException`. Negative values cannot reach this method because `Quantity` rejects them during construction.
  - `register()` initializes `reserved` to zero and records `InitialStockRegisteredDomainEvent`.
  - `available()` returns `onHand - reserved` as a `Quantity`.
- `Manager\Inventory\Domain\StockItemRepository`:
  - `public function search(StockItemId $id): ?StockItem`
  - `public function searchByProductId(ProductId $productId): ?StockItem`
  - `public function searchByReservationId(ReservationId $reservationId): ?StockItem`
  - `public function save(StockItem $stockItem): void`
  - The full port is defined now because it represents the `StockItem` aggregate collection. `searchByReservationId` is used by Phase 4.
- `Manager\Inventory\Domain\Event\InitialStockRegisteredDomainEvent extends Manager\Shared\Domain\Bus\Event\DomainEvent`:
  - `eventName(): "inventory.stock_item.registered"`
  - `__construct(StockItemId $stockItemId, string $productId, int $initialQuantity, ?string $eventId = null, ?string $occurredOn = null)`
  - The constructor passes `$stockItemId->value()` to the base `DomainEvent`, so `aggregateId()` always equals `stockItemId->value()`.
  - The constructor validates `$productId` through `ProductId`, throwing `InvalidArgumentException` for a non-UUID value.
  - The constructor validates `$initialQuantity` through `Quantity` and requires it to be positive:
    - A negative value throws `InvalidQuantityException` while building the `Quantity`.
    - Zero throws `QuantityMustBePositiveException`.
  - The constructor stores the `StockItemId`, `ProductId` and `Quantity` in readonly properties so `toPrimitives()` can serialize their primitive values.
  - `fromPrimitives(string $aggregateId, array $body, string $eventId, string $occurredOn): self`
  - `fromPrimitives()` validates payload presence and basic types, then delegates constructor validation to `ProductId` and `Quantity`, so the invalid product and quantity rules also apply when reconstructing.
  - `fromPrimitives()` builds the `StockItemId` from `aggregateId`.
  - `toPrimitives(): array` with `stockItemId`, `productId` and `initialQuantity`.
  - `fromPrimitives()` throws `InvalidArgumentException` when `body['stockItemId']` does not match `aggregateId`.

All Inventory exceptions extend `Manager\Shared\Domain\DomainError`. Because `DomainError` has a no-argument constructor that builds the message from `errorMessage()`, each exception stores its contextual data in readonly properties before calling `parent::__construct()`.

- `Manager\Inventory\Domain\Exception\InvalidQuantityException extends Manager\Shared\Domain\DomainError`:
  - `__construct(int $value)`
  - Stores the rejected value before calling `parent::__construct()`.
  - `errorMessage()` includes the rejected value.
- `Manager\Inventory\Domain\Exception\QuantityMustBePositiveException extends Manager\Shared\Domain\DomainError`:
  - `__construct(int $value)`
  - Stores the rejected value before calling `parent::__construct()`.
  - `errorMessage()` includes the rejected value.
- `Manager\Inventory\Domain\Exception\StockItemAlreadyExistsException extends Manager\Shared\Domain\DomainError`:
  - `__construct(ProductId $productId)`
  - Stores the duplicate `ProductId` before calling `parent::__construct()`.
  - Exposes a stable `errorCode()` and `errorMessage()`.
- `Manager\Inventory\Application\RegisterInitialStock\RegisterInitialStockCommand implements Manager\Shared\Domain\Bus\Command\Command`:
  - `__construct(public string $productId, public int $initialQuantity)`
  - `productId(): string`
  - `initialQuantity(): int`
- `Manager\Inventory\Application\RegisterInitialStock\RegisterInitialStockCommandHandler implements Manager\Shared\Domain\Bus\Command\CommandHandler`:
  - `__construct(StockItemRepository $repository, UuidGenerator $uuidGenerator)`
  - `__invoke(RegisterInitialStockCommand $command): void`
  - Builds `ProductId` and `Quantity` from command input.
  - Searches by `ProductId`.
  - Throws `StockItemAlreadyExistsException` when a `StockItem` already exists.
  - Generates a UUID with `UuidGenerator` and builds `StockItemId`.
  - Creates the aggregate with `StockItem::register`.
  - Persists it with `StockItemRepository::save`.

**Test contracts:**

- `Tests\Unit\Inventory\InventoryUnitTestCase`: pure PHPUnit test case with Mockery integration, without Laravel.
- `Tests\Unit\Inventory\Domain\ValueObject\QuantityTest`:
  - Accepts zero.
  - Accepts positive values.
  - Rejects negative values with `InvalidQuantityException`.
- `Tests\Unit\Inventory\Domain\StockItemTest`:
  - Registers a `StockItem` with valid initial stock.
  - Rejects zero initial stock with `QuantityMustBePositiveException`.
  - Does not test negative initial stock at aggregate level because `QuantityTest` already proves that `new Quantity(-1)` fails before `register()` can be called.
  - Calculates `available` from `onHand - reserved`.
  - Exposes the `StockItemId` and `ProductId`.
  - Records `InitialStockRegisteredDomainEvent` with the expected payload.
- `Tests\Unit\Inventory\Domain\Event\InitialStockRegisteredDomainEventTest`:
  - Reconstructs the event from primitives.
  - Preserves the aggregate identifier.
  - Guarantees that the constructor derives `aggregateId()` from `StockItemId`.
  - Rejects a `stockItemId` that does not match `aggregateId` in `fromPrimitives()`.
  - Rejects an invalid product UUID directly in the constructor with `InvalidArgumentException`.
  - Rejects zero `initialQuantity` directly in the constructor with `QuantityMustBePositiveException`.
  - Rejects a negative `initialQuantity` directly in the constructor with `InvalidQuantityException`.
  - Rejects an invalid product UUID when reconstructing from primitives with `InvalidArgumentException`.
  - Rejects zero `initialQuantity` when reconstructing from primitives with `QuantityMustBePositiveException`.
  - Rejects a negative `initialQuantity` when reconstructing from primitives with `InvalidQuantityException`.
- `Tests\Unit\Inventory\Application\RegisterInitialStock\RegisterInitialStockCommandHandlerTest`:
  - Registers stock when the product does not exist.
  - Rejects an already registered `ProductId`.
  - Generates a `StockItemId` through `UuidGenerator`.
  - Persists the aggregate through the repository port.
  - Propagates quantity errors from the integer command input:
    - Zero reaches `StockItem::register()` and throws `QuantityMustBePositiveException`.
    - A negative value fails `Quantity` construction with `InvalidQuantityException`.
- `Tests\Unit\Inventory\Domain\ObjectMother\StockItemIdMother`
- `Tests\Unit\Inventory\Domain\ObjectMother\ProductIdMother`
- `Tests\Unit\Inventory\Domain\ObjectMother\QuantityMother`
- `Tests\Unit\Inventory\Domain\ObjectMother\StockItemMother`

**To-do actions:**

- [x] Create the `src/Inventory/Domain/ValueObject` directory and the four typed UUID identifiers extending the shared `Uuid` abstraction.
- [x] Create `Quantity` extending `IntValueObject`, validating non-negative values before delegating to the parent constructor.
- [x] Add `InvalidQuantityException`, `QuantityMustBePositiveException` and `StockItemAlreadyExistsException` extending `DomainError`, with the explicit constructors described above.
- [x] Implement `StockItem::register()`, its accessors, `available()` and `InitialStockRegisteredDomainEvent`. The event constructor must derive the base aggregate identifier from `StockItemId`.
- [x] Define the complete `StockItemRepository` port with the four methods listed above.
- [x] Implement `RegisterInitialStockCommand` and `RegisterInitialStockCommandHandler` using `StockItemRepository` and `UuidGenerator`.
- [x] Add the Inventory Unit test base case and the object mothers listed above.
- [x] Add the Quantity, StockItem, domain-event and command-handler tests listed above. Keep negative-quantity coverage in `QuantityTest` and command-input tests; aggregate tests cover zero.
- [x] Run `vendor/bin/phpunit --testsuite=Unit` and fix any failures.
- [x] Run ECS and PHPStan on the new Inventory paths and fix any issues introduced by this phase without adding baseline entries.
- [x] Verify the changes in terms of typechecking, linting and tests using the project's verification command. Fix issues if any.
- [x] STOP. Present the changes to the user for review and suggest commit messages. Do NOT proceed to the next phase until the user explicitly asks.

### Phase 2: Add stock

**Description:** Add the stock-addition operation to the `StockItem` aggregate, record its domain event and deliver the `AddStock` application use case with unit tests.

**Public contracts:**

- `Manager\Inventory\Domain\StockItem`:
  - `public function addStock(Quantity $quantity): void`
  - Rejects zero quantities with `QuantityMustBePositiveException`. Negative values cannot reach this method because `Quantity` rejects them during construction.
  - Increases `onHand` without modifying reservations.
  - Records `StockAddedDomainEvent` with the resulting `onHand` and `reserved`.
- `Manager\Inventory\Domain\Event\StockAddedDomainEvent extends Manager\Shared\Domain\Bus\Event\DomainEvent`:
  - `eventName(): "inventory.stock.added"`
  - `__construct(StockItemId $stockItemId, string $productId, int $addedQuantity, int $onHand, int $reserved, ?string $eventId = null, ?string $occurredOn = null)`
  - The constructor is the single validation source and converts primitives to value objects before storing them:
    - `productId` through `ProductId`, so an invalid UUID throws `InvalidArgumentException`.
    - `addedQuantity` through `Quantity`, so a negative value throws `InvalidQuantityException`; zero throws `QuantityMustBePositiveException`.
    - `onHand` and `reserved` through `Quantity`, so negative values throw `InvalidQuantityException`.
  - The constructor passes `$stockItemId->value()` to the base `DomainEvent`, so `aggregateId()` always equals `stockItemId->value()`.
  - The constructor stores the `StockItemId` in a readonly property so `toPrimitives()` can serialize it.
  - `fromPrimitives(string $aggregateId, array $body, string $eventId, string $occurredOn): self`
  - `fromPrimitives()` validates the payload shape and primitive types, requires `body['stockItemId']` to match `aggregateId` and delegates all value validation to the constructor.
  - `toPrimitives(): array` with `stockItemId`, `productId`, `addedQuantity`, `onHand` and `reserved`.
  - `fromPrimitives()` throws `InvalidArgumentException` for missing fields, wrong primitive types or a `stockItemId` that does not match `aggregateId`.
- `Manager\Inventory\Domain\Exception\StockItemNotFoundException extends Manager\Shared\Domain\DomainError`:
  - `__construct(ProductId $productId)`
  - Stores the missing `ProductId` before calling `parent::__construct()`.
  - Exposes a stable `errorCode()` and `errorMessage()`.
- `Manager\Inventory\Application\AddStock\AddStockCommand implements Manager\Shared\Domain\Bus\Command\Command`:
  - `__construct(public string $productId, public int $quantity)`
  - `productId(): string`
  - `quantity(): int`
- `Manager\Inventory\Application\AddStock\AddStockCommandHandler implements Manager\Shared\Domain\Bus\Command\CommandHandler`:
  - `__construct(StockItemRepository $repository)`
  - `__invoke(AddStockCommand $command): void`
  - Builds `ProductId` and `Quantity` from command input.
  - Searches by `ProductId`.
  - Throws `StockItemNotFoundException` when no aggregate exists.
  - Invokes `StockItem::addStock`.
  - Persists the aggregate with `StockItemRepository::save`.

**Test contracts:**

- `Tests\Unit\Inventory\Domain\StockItemTest` (modified):
  - Adds stock to a registered `StockItem`.
  - Rejects zero stock additions with `QuantityMustBePositiveException`.
  - Does not test negative stock additions at aggregate level because `QuantityTest` already proves that `new Quantity(-1)` fails before `addStock()` can be called.
  - Increases `onHand` and `available`.
  - Does not modify `reserved` or existing reservations.
  - Records `StockAddedDomainEvent` with the expected payload.
- `Tests\Unit\Inventory\Domain\Event\StockAddedDomainEventTest`:
  - Guarantees that the constructor derives `aggregateId()` from `StockItemId`.
  - Reconstructs the event from primitives preserving the aggregate identifier, payload, event ID and occurrence date.
  - Rejects a `stockItemId` that does not match `aggregateId` in `fromPrimitives()`.
  - Rejects an invalid `productId` in the constructor and when reconstructing from primitives.
  - Rejects zero and negative `addedQuantity` in the constructor and when reconstructing from primitives, preserving the `QuantityMustBePositiveException` / `InvalidQuantityException` distinction.
  - Rejects negative `onHand` and `reserved` values in the constructor.
- `Tests\Unit\Inventory\Application\AddStock\AddStockCommandHandlerTest`:
  - Adds stock to an existing `StockItem` and persists the updated aggregate.
  - Rejects an unknown `ProductId` with `StockItemNotFoundException` without saving.
  - Propagates quantity errors from the integer command input:
    - Zero reaches `StockItem::addStock()` and throws `QuantityMustBePositiveException` without saving.
    - A negative value fails `Quantity` construction with `InvalidQuantityException` before searching or saving.
  - Persists the aggregate through the repository port.
- `Tests\Unit\Inventory\Domain\ObjectMother\StockItemMother` needs no change because its existing `productId` and `initialQuantity` parameters cover the new fixtures.
- `Tests\Unit\Inventory\InventoryUnitTestCase` now hosts the generic typed Mockery helpers, backed by `Tests\Unit\Inventory\MockeryExpectation`, so both Inventory command-handler tests share the same setup.

**To-do actions:**

- [x] Add `StockAddedDomainEvent` with its event name, payload, primitive reconstruction and aggregate identifier derived from `StockItemId`.
- [x] Add `StockItemNotFoundException` with an explicit constructor storing the missing `ProductId`.
- [x] Implement `StockItem::addStock()` with zero-quantity validation, counter updates and event recording.
- [x] Implement `AddStockCommand` and `AddStockCommandHandler` using the repository port.
- [x] Extend the StockItem tests, add the StockAdded event test and the AddStock handler test. Keep negative-quantity coverage in `QuantityTest` and command-input tests; aggregate tests cover zero.
- [x] Extend the object mothers required by the new fixtures; no change was required because `StockItemMother` already accepts `productId` and `initialQuantity`.
- [x] Run `vendor/bin/phpunit --testsuite=Unit` and fix any failures.
- [x] Run ECS and PHPStan on the new and modified Inventory paths and fix any issues introduced by this phase without adding baseline entries.
- [x] Verify the changes in terms of typechecking, linting and tests using the project's verification command. Fix issues if any.
- [x] STOP. Present the changes to the user for review and suggest commit messages. Do NOT proceed to the next phase until the user explicitly asks.

### Phase 3: Stock reservation

**Description:** Introduce the `StockReservation` entity, implement the reservation operation with its availability rules and domain event, and deliver the `ReserveStock` application use case with unit tests.

**Public contracts:**

- `Manager\Inventory\Domain\ValueObject\ReservationStatus` enum:
  - `Active`
  - `Released`
- `Manager\Inventory\Domain\StockReservation`:
  - `public static function create(ReservationId $id, OrderId $orderId, Quantity $quantity): self`
  - `public function id(): ReservationId`
  - `public function orderId(): OrderId`
  - `public function quantity(): Quantity`
  - `public function status(): ReservationStatus`
  - `public function isActive(): bool`
  - `public function isReleased(): bool`
  - `create()` rejects zero quantities with `QuantityMustBePositiveException`. Negative values cannot reach this method because `Quantity` rejects them during construction.
  - The entity is immutable from the caller's perspective and belongs to the `StockItem` aggregate.
- `Manager\Inventory\Domain\StockItem`:
  - `public function reserve(ReservationId $reservationId, OrderId $orderId, Quantity $quantity): void`
  - `public function reservations(): array` annotated with `/** @return list<StockReservation> */`
  - `reserve()` rejects zero quantities with `QuantityMustBePositiveException`. Negative values cannot reach this method because `Quantity` rejects them during construction.
  - `reserve()` throws `ReservationAlreadyExistsException` when a reservation with the same `ReservationId` already exists in the aggregate, regardless of its status.
  - `reserve()` throws `InsufficientStockException` when the requested quantity exceeds `available()`.
  - `reserve()` creates an active `StockReservation`, adds it to the aggregate, increases `reserved` and records `StockReservedDomainEvent`.
  - `reservations()` returns a copy of the internal list and does not expose a mutable collection.
  - No one-reservation-per-`OrderId` rule is introduced. Multiple reservations for the same order are not prohibited by this task.
- `Manager\Inventory\Domain\Event\StockReservedDomainEvent extends Manager\Shared\Domain\Bus\Event\DomainEvent`:
  - `eventName(): "inventory.stock.reserved"`
  - `__construct(StockItemId $stockItemId, string $productId, string $orderId, string $reservationId, int $reservedQuantity, int $onHand, int $reserved, ?string $eventId = null, ?string $occurredOn = null)`
  - The constructor passes `$stockItemId->value()` to the base `DomainEvent`, so `aggregateId()` always equals `stockItemId->value()`.
  - The constructor stores the `StockItemId` in a readonly property so `toPrimitives()` can serialize it.
  - `fromPrimitives(string $aggregateId, array $body, string $eventId, string $occurredOn): self`
  - `fromPrimitives()` validates the payload and builds the `StockItemId` from `aggregateId`.
  - `toPrimitives(): array` with `stockItemId`, `productId`, `orderId`, `reservationId`, `reservedQuantity`, `onHand` and `reserved`.
  - `fromPrimitives()` throws `InvalidArgumentException` when `body['stockItemId']` does not match `aggregateId`.
- `Manager\Inventory\Domain\Exception\InsufficientStockException extends Manager\Shared\Domain\DomainError`:
  - `__construct(ProductId $productId, Quantity $requested, Quantity $available)`
  - Stores the `ProductId`, requested quantity and available quantity before calling `parent::__construct()`.
  - Exposes a stable `errorCode()` and `errorMessage()`.
- `Manager\Inventory\Domain\Exception\ReservationAlreadyExistsException extends Manager\Shared\Domain\DomainError`:
  - `__construct(ReservationId $reservationId)`
  - Stores the duplicate `ReservationId` before calling `parent::__construct()`.
  - Exposes a stable `errorCode()` and `errorMessage()`.
- `Manager\Inventory\Application\ReserveStock\ReserveStockCommand implements Manager\Shared\Domain\Bus\Command\Command`:
  - `__construct(public string $productId, public string $orderId, public int $quantity)`
  - `productId(): string`
  - `orderId(): string`
  - `quantity(): int`
- `Manager\Inventory\Application\ReserveStock\ReserveStockCommandHandler implements Manager\Shared\Domain\Bus\Command\CommandHandler`:
  - `__construct(StockItemRepository $repository, UuidGenerator $uuidGenerator)`
  - `__invoke(ReserveStockCommand $command): void`
  - Builds `ProductId`, `OrderId` and `Quantity` from command input.
  - Searches by `ProductId`.
  - Throws `StockItemNotFoundException` when no aggregate exists.
  - Generates a UUID with `UuidGenerator` and builds `ReservationId`.
  - Invokes `StockItem::reserve`.
  - Persists the aggregate with `StockItemRepository::save`.

**Test contracts:**

- `Tests\Unit\Inventory\Domain\StockReservationTest`:
  - Creates an active reservation.
  - Exposes its identifier, order identifier and quantity.
  - Rejects zero reservation quantities with `QuantityMustBePositiveException`.
  - Does not test negative reservation quantities at entity level because `QuantityTest` already proves that `new Quantity(-1)` fails before `create()` can be called.
- `Tests\Unit\Inventory\Domain\StockItemTest` (modified):
  - Reserves stock from an existing `StockItem`.
  - Rejects zero reservation quantities with `QuantityMustBePositiveException`.
  - Does not test negative reservation quantities at aggregate level because `QuantityTest` already proves that `new Quantity(-1)` fails before `reserve()` can be called.
  - Rejects a second reservation that reuses an existing `ReservationId`.
  - Rejects a reservation exceeding available stock.
  - Updates `reserved` and `available` correctly.
  - Keeps the sum of active reservation quantities equal to `reserved`.
  - Returns a list copy from `reservations()`.
  - Records `StockReservedDomainEvent` with the expected payload.
- `Tests\Unit\Inventory\Domain\Event\StockReservedDomainEventTest`:
  - Reconstructs the event from primitives.
  - Preserves the aggregate identifier and payload.
  - Guarantees that the constructor derives `aggregateId()` from `StockItemId`.
  - Rejects a `stockItemId` that does not match `aggregateId` in `fromPrimitives()`.
- `Tests\Unit\Inventory\Application\ReserveStock\ReserveStockCommandHandlerTest`:
  - Reserves stock for an existing `StockItem`.
  - Rejects an unknown `ProductId`.
  - Rejects insufficient stock through the domain behavior.
  - Propagates quantity errors from the integer command input:
    - Zero reaches `StockItem::reserve()` and throws `QuantityMustBePositiveException`.
    - A negative value fails `Quantity` construction with `InvalidQuantityException`.
  - Generates a `ReservationId` through `UuidGenerator`.
  - Persists the aggregate through the repository port.
- `Tests\Unit\Inventory\Domain\ObjectMother\ReservationIdMother`
- `Tests\Unit\Inventory\Domain\ObjectMother\OrderIdMother`
- `Tests\Unit\Inventory\Domain\ObjectMother\StockReservationMother`
- `Tests\Unit\Inventory\Domain\ObjectMother\StockItemMother` (modified to support reservations).

**To-do actions:**

- [ ] Add the `ReservationStatus` enum and the immutable `StockReservation` entity with its creation and accessor methods.
- [ ] Add `InsufficientStockException` with an explicit constructor storing the `ProductId`, requested quantity and available quantity.
- [ ] Add `ReservationAlreadyExistsException` with an explicit constructor storing the duplicate `ReservationId`.
- [ ] Add `StockReservedDomainEvent` with its event name, payload, primitive reconstruction and aggregate identifier derived from `StockItemId`.
- [ ] Implement `StockItem::reserve()` with zero-quantity validation, `ReservationId` uniqueness inside the aggregate, availability checks and event recording.
- [ ] Implement the `reservations()` accessor with the `list<StockReservation>` annotation and a copy of the internal list.
- [ ] Implement `ReserveStockCommand` and `ReserveStockCommandHandler` using the repository port and `UuidGenerator`.
- [ ] Add the reservation entity tests, extend the StockItem tests, add the StockReserved event test and the ReserveStock handler test. Keep negative-quantity coverage in `QuantityTest` and command-input tests; aggregate tests cover zero.
- [ ] Add the object mothers required by reservations and extend the StockItem mother.
- [ ] Run `vendor/bin/phpunit --testsuite=Unit` and fix any failures.
- [ ] Run ECS and PHPStan on the new and modified Inventory paths and fix any issues introduced by this phase without adding baseline entries.
- [ ] Verify the changes in terms of typechecking, linting and tests using the project's verification command. Fix issues if any.
- [ ] STOP. Present the changes to the user for review and suggest commit messages. Do NOT proceed to the next phase until the user explicitly asks.

### Phase 4: Release reservation

**Description:** Implement one-time release of active reservations, record the release domain event and deliver the `ReleaseStock` application use case with unit tests.

**Public contracts:**

- `Manager\Inventory\Domain\StockReservation`:
  - `public function release(): self`
  - Returns a new reservation instance with `ReservationStatus::Released`.
  - Throws `ReservationAlreadyReleasedException` when the reservation is already released.
  - The original instance remains unchanged.
- `Manager\Inventory\Domain\StockItem`:
  - `public function releaseReservation(ReservationId $reservationId): void`
  - Finds the reservation in the aggregate.
  - Throws `ReservationNotFoundException` when the reservation is not owned by the aggregate.
  - Throws `ReservationAlreadyReleasedException` when the reservation is already released.
  - Replaces the active reservation with its released version, decreases `reserved` by the reservation quantity and records `ReservationReleasedDomainEvent`.
  - Preserves `0 <= reserved <= onHand` and the consistency between active reservation quantities and `reserved`.
- `Manager\Inventory\Domain\Event\ReservationReleasedDomainEvent extends Manager\Shared\Domain\Bus\Event\DomainEvent`:
  - `eventName(): "inventory.reservation.released"`
  - `__construct(StockItemId $stockItemId, string $productId, string $orderId, string $reservationId, int $releasedQuantity, int $onHand, int $reserved, ?string $eventId = null, ?string $occurredOn = null)`
  - The constructor passes `$stockItemId->value()` to the base `DomainEvent`, so `aggregateId()` always equals `stockItemId->value()`.
  - The constructor stores the `StockItemId` in a readonly property so `toPrimitives()` can serialize it.
  - `fromPrimitives(string $aggregateId, array $body, string $eventId, string $occurredOn): self`
  - `fromPrimitives()` validates the payload and builds the `StockItemId` from `aggregateId`.
  - `toPrimitives(): array` with `stockItemId`, `productId`, `orderId`, `reservationId`, `releasedQuantity`, `onHand` and `reserved`.
  - `fromPrimitives()` throws `InvalidArgumentException` when `body['stockItemId']` does not match `aggregateId`.
- `Manager\Inventory\Domain\Exception\ReservationNotFoundException extends Manager\Shared\Domain\DomainError`:
  - `__construct(ReservationId $reservationId)`
  - Stores the missing `ReservationId` before calling `parent::__construct()`.
  - Exposes a stable `errorCode()` and `errorMessage()`.
- `Manager\Inventory\Domain\Exception\ReservationAlreadyReleasedException extends Manager\Shared\Domain\DomainError`:
  - `__construct(ReservationId $reservationId)`
  - Stores the already released `ReservationId` before calling `parent::__construct()`.
  - Exposes a stable `errorCode()` and `errorMessage()`.
- `Manager\Inventory\Application\ReleaseStock\ReleaseStockCommand implements Manager\Shared\Domain\Bus\Command\Command`:
  - `__construct(public string $reservationId)`
  - `reservationId(): string`
- `Manager\Inventory\Application\ReleaseStock\ReleaseStockCommandHandler implements Manager\Shared\Domain\Bus\Command\CommandHandler`:
  - `__construct(StockItemRepository $repository)`
  - `__invoke(ReleaseStockCommand $command): void`
  - Builds `ReservationId`.
  - Searches the owning aggregate with `StockItemRepository::searchByReservationId`.
  - Throws `ReservationNotFoundException` when no owning aggregate exists.
  - Invokes `StockItem::releaseReservation`.
  - Persists the aggregate with `StockItemRepository::save`.

**Test contracts:**

- `Tests\Unit\Inventory\Domain\StockReservationTest` (modified):
  - Releases an active reservation and returns a released instance.
  - Keeps the original instance unchanged.
  - Rejects a repeated release.
- `Tests\Unit\Inventory\Domain\StockItemTest` (modified):
  - Releases an active reservation.
  - Decreases `reserved` by the released quantity.
  - Rejects an unknown reservation.
  - Rejects a reservation that was already released.
  - Keeps released reservations out of the active reservation sum.
  - Preserves the aggregate invariants after release.
  - Records `ReservationReleasedDomainEvent` with the expected payload.
- `Tests\Unit\Inventory\Domain\Event\ReservationReleasedDomainEventTest`:
  - Reconstructs the event from primitives.
  - Preserves the aggregate identifier and payload.
  - Guarantees that the constructor derives `aggregateId()` from `StockItemId`.
  - Rejects a `stockItemId` that does not match `aggregateId` in `fromPrimitives()`.
- `Tests\Unit\Inventory\Application\ReleaseStock\ReleaseStockCommandHandlerTest`:
  - Releases a reservation using only its `ReservationId`.
  - Rejects an unknown reservation.
  - Rejects an already released reservation.
  - Persists the owning aggregate through the repository port.
- `Tests\Unit\Inventory\Domain\ObjectMother\StockItemMother` (modified to support released reservations).

**To-do actions:**

- [ ] Add `ReservationNotFoundException` and `ReservationAlreadyReleasedException` with explicit constructors storing the `ReservationId`.
- [ ] Implement `StockReservation::release()` returning a new released instance and rejecting repeated release.
- [ ] Add `ReservationReleasedDomainEvent` with its event name, payload, primitive reconstruction and aggregate identifier derived from `StockItemId`.
- [ ] Implement `StockItem::releaseReservation()` with ownership, status and counter updates.
- [ ] Implement `ReleaseStockCommand` and `ReleaseStockCommandHandler` using `searchByReservationId` and `save`.
- [ ] Extend the reservation and StockItem tests, add the ReservationReleased event test and the ReleaseStock handler test.
- [ ] Extend the object mothers required by released reservations.
- [ ] Run the full PHPUnit suite, including Unit, Integration and Api tests, and fix any failures.
- [ ] Run ECS and PHPStan on the new and modified Inventory paths and fix any issues introduced by this task without adding baseline entries.
- [ ] Verify the changes in terms of typechecking, linting and tests using the project's verification command. Fix issues if any.
- [ ] STOP. Present the changes to the user for review and suggest commit messages. Do NOT proceed to any further work until the user explicitly asks.

## Next step

Start Phase 3: stock reservation with `StockReservation`, the reservation operation, `StockReservedDomainEvent` and the `ReserveStock` use case.

The first inventory seeds are planted 🌱 and now the shelves start filling with boxes 📦, ready to grow with 🐢 💨 (Turbotuga™, [Codely](https://codely.com)'s mascot).
