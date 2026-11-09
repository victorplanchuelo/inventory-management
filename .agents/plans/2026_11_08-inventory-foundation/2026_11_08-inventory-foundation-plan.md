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
    name: "GLM-5.3-Flash Free"
    version: "5.3"
    reasoning_effort: "medium"

last_implementation_at: "2026-11-09T22:23:12Z"
has_completed_all_phases: "true"
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

- `Manager\Inventory\Domain\ValueObject\StockReservationStatus` enum:
  - `Active`
  - `Released`
- `Manager\Inventory\Domain\StockReservation`:
  - `public static function create(ReservationId $id, OrderId $orderId, Quantity $quantity): self`
  - `public function id(): ReservationId`
  - `public function orderId(): OrderId`
  - `public function quantity(): Quantity`
  - `public function status(): StockReservationStatus`
  - `public function isActive(): bool`
  - `public function isReleased(): bool`
  - `create()` rejects zero quantities with `QuantityMustBePositiveException`. Negative values cannot reach this method because `Quantity` rejects them during construction.
  - Every property, including `quantity`, is declared `readonly`: the entity is immutable from the caller's perspective and belongs to the `StockItem` aggregate.
- `Manager\Inventory\Domain\StockItem`:
  - `public function reserve(ReservationId $reservationId, OrderId $orderId, Quantity $quantity): void`
  - `public function reservations(): array` annotated with `/** @return list<StockReservation> */`
  - `reserve()` rejects zero quantities with `QuantityMustBePositiveException`. Negative values cannot reach this method because `Quantity` rejects them during construction.
  - `reserve()` throws `ReservationAlreadyExistsException` when a reservation with the same `ReservationId` already exists in the aggregate, regardless of its status. The comparison uses the `Uuid::equals()` method.
  - `reserve()` throws `InsufficientStockException` when the requested quantity exceeds `available()`. The comparison uses `Quantity::isBiggerThan()` with the requested quantity as receiver.
  - `reserve()` creates an active `StockReservation`, adds it to the aggregate, increases `reserved` and records `StockReservedDomainEvent`. The entity is created only after the availability check rejects duplicates and insufficiency.
  - `reservations()` returns the internal list as-is; copying adds no protection because PHP `array` assignment already copies by value on write.
  - No one-reservation-per-`OrderId` rule is introduced. Multiple reservations for the same order are not prohibited by this task.
- `Manager\Inventory\Domain\Event\StockReservedDomainEvent extends Manager\Shared\Domain\Bus\Event\DomainEvent`:
  - `eventName(): "inventory.stock.reserved"`
  - `__construct(StockItemId $stockItemId, string $productId, string $orderId, string $reservationId, int $reservedQuantity, int $onHand, int $reserved, ?string $eventId = null, ?string $occurredOn = null)`
  - The constructor is the single validation source and converts every primitive to its value object before storing it:
    - `productId` through `ProductId`, so an invalid UUID throws `InvalidArgumentException`.
    - `orderId` through `OrderId` and `reservationId` through `ReservationId`, so an invalid UUID throws `InvalidArgumentException`; both are stored as value objects.
    - `reservedQuantity` through `Quantity`, so a negative value throws `InvalidQuantityException`; zero throws `QuantityMustBePositiveException`.
    - `onHand` and `reserved` through `Quantity`, so negative values throw `InvalidQuantityException`.
  - The constructor additionally enforces payload coherence, because `fromPrimitives()` can rebuild the event from external data:
    - Throws `InvalidArgumentException` when `reserved` exceeds `onHand`.
    - Throws `InvalidArgumentException` when `reservedQuantity` exceeds `reserved`.
    - The aggregate always records coherent payloads, so these checks only reject corrupted external data on `fromPrimitives()`.
  - The constructor passes `$stockItemId->value()` to the base `DomainEvent`, so `aggregateId()` always equals `stockItemId->value()`.
  - `fromPrimitives(string $aggregateId, array $body, string $eventId, string $occurredOn): self`
  - `fromPrimitives()` validates the payload shape and primitive types, requires `body['stockItemId']` to match `aggregateId` and delegates all value validation to the constructor.
  - `toPrimitives(): array` with `stockItemId`, `productId`, `orderId`, `reservationId`, `reservedQuantity`, `onHand` and `reserved`.
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
  - Returns the internal list from `reservations()`; the test proves mutating the returned value does not leak into the aggregate.
  - Reserves exactly all available stock, leaving `available()` at zero.
  - Leaves the aggregate unchanged after a rejected reservation: counter, collection and pending events stay intact for duplicate and insufficient-stock errors.
  - Records `StockReservedDomainEvent` with the expected payload.
- `Tests\Unit\Inventory\Domain\Event\StockReservedDomainEventTest`:
  - Guarantees that the constructor derives `aggregateId()` from `StockItemId`.
  - Reconstructs the event from primitives preserving the aggregate identifier, payload, event ID and occurrence date.
  - Rejects a `stockItemId` that does not match `aggregateId` in `fromPrimitives()`.
  - Rejects invalid `productId`, `orderId` and `reservationId` in the constructor and when reconstructing from primitives.
  - Rejects zero and negative `reservedQuantity` in the constructor and when reconstructing from primitives, preserving the `QuantityMustBePositiveException` / `InvalidQuantityException` distinction.
  - Rejects negative `onHand` and `reserved` values in the constructor and when reconstructing from primitives.
  - Rejects incoherent payloads in the constructor and when reconstructing from primitives:
    - `reserved` exceeding `onHand`.
    - `reservedQuantity` exceeding `reserved`.
  - Rejects every payload field missing through a data provider.
  - Rejects wrongly typed payload fields through a data provider covering both the `Uuid` fields (sent as integers) and the `Quantity` fields (sent as strings).
- `Tests\Unit\Inventory\Application\ReserveStock\ReserveStockCommandHandlerTest`:
  - Reserves stock for an existing `StockItem` and persists the updated aggregate.
  - Rejects an unknown `ProductId` with `StockItemNotFoundException` without saving.
  - Rejects insufficient stock through the domain behavior without saving.
  - Propagates quantity errors from the integer command input:
    - Zero reaches `StockItem::reserve()` and throws `QuantityMustBePositiveException` without saving.
    - A negative value fails `Quantity` construction with `InvalidQuantityException` before searching or generating the reservation identifier.
  - Generates a `ReservationId` through `UuidGenerator`.
  - Persists the aggregate containing the generated `ReservationId` and the `OrderId` received from the command.
- `Tests\Unit\Inventory\Domain\ObjectMother\ReservationIdMother`
- `Tests\Unit\Inventory\Domain\ObjectMother\OrderIdMother`
- `Tests\Unit\Inventory\Domain\ObjectMother\StockReservationMother`
- `Tests\Unit\Inventory\Domain\ObjectMother\StockItemMother` needs no change because reservations are recorded through `StockItem::reserve()` on an already registered item.
- `Tests\Unit\Inventory\InventoryUnitTestCase` reuses the generic typed Mockery helpers, backed by `Tests\Unit\Inventory\MockeryExpectation`, for the `ReserveStockCommandHandler` test.

**To-do actions:**

- [x] Add the `StockReservationStatus` enum and the immutable `StockReservation` entity with its creation and accessor methods.
- [x] Add `InsufficientStockException` with an explicit constructor storing the `ProductId`, requested quantity and available quantity.
- [x] Add `ReservationAlreadyExistsException` with an explicit constructor storing the duplicate `ReservationId`.
- [x] Add `StockReservedDomainEvent` with its event name, payload, primitive reconstruction and aggregate identifier derived from `StockItemId`.
- [x] Implement `StockItem::reserve()` with zero-quantity validation, `ReservationId` uniqueness inside the aggregate, availability checks and event recording.
- [x] Implement the `reservations()` accessor with the `list<StockReservation>` annotation, returning the internal list directly and relying on PHP copy-on-write array semantics.
- [x] Implement `ReserveStockCommand` and `ReserveStockCommandHandler` using the repository port and `UuidGenerator`.
- [x] Add the reservation entity tests, extend the StockItem tests, add the StockReserved event test and the ReserveStock handler test. Keep negative-quantity coverage in `QuantityTest` and command-input tests; aggregate tests cover zero.
- [x] Add the object mothers required by reservations; `StockItemMother` needed no change because reservations are recorded through `StockItem::reserve()` on an already registered item, which the plan states below.
- [x] Run `vendor/bin/phpunit --testsuite=Unit` and fix any failures.
- [x] Run ECS and PHPStan on the new and modified Inventory paths and fix any issues introduced by this phase without adding baseline entries.
- [x] Verify the changes in terms of typechecking, linting and tests using the project's verification command. Fix issues if any.
- [x] STOP. Present the changes to the user for review and suggest commit messages. Do NOT proceed to the next phase until the user explicitly asks.

### Phase 4: Release reservation

**Description:** Implement one-time release of active reservations, record the release domain event and deliver the `ReleaseStock` application use case with unit tests.

**Public contracts:**

- `Manager\Inventory\Domain\StockReservation`:
  - `public function release(): self`
  - Returns a new reservation instance with `StockReservationStatus::Released`.
  - Throws `ReservationAlreadyReleasedException` when the reservation is already released.
  - The entity stays immutable: `release()` never mutates the original instance and the aggregate must replace the active reservation with the returned instance instead of changing the released one in place.
- `Manager\Inventory\Domain\StockItem`:
  - `public function releaseReservation(ReservationId $reservationId): void`
  - Finds the reservation in the aggregate.
  - Throws `ReservationNotFoundException` when the reservation is not owned by the aggregate.
  - Throws `ReservationAlreadyReleasedException` when the reservation is already released.
  - Keeps the release atomic: it locates the reservation, calls `release()`, computes the released counter and constructs the `ReservationReleasedDomainEvent` before modifying any state; only after every fallible step succeeds does it replace the reservation, update `reserved` and record the event, so a validation failure leaves the aggregate untouched.
  - Preserves `0 <= reserved <= onHand` and the consistency between active reservation quantities and `reserved`.
- `Manager\Inventory\Domain\Event\ReservationReleasedDomainEvent extends Manager\Shared\Domain\Bus\Event\DomainEvent`:
  - `eventName(): "inventory.reservation.released"`
  - `__construct(StockItemId $stockItemId, string $productId, string $orderId, string $reservationId, int $releasedQuantity, int $onHand, int $reserved, ?string $eventId = null, ?string $occurredOn = null)`
  - The constructor is the single validation source and converts every primitive to its value object before storing it:
    - `productId` through `ProductId`, `orderId` through `OrderId` and `reservationId` through `ReservationId`, so an invalid UUID throws `InvalidArgumentException`; all of them are stored as value objects.
    - `releasedQuantity` through `Quantity`, so a negative value throws `InvalidQuantityException`; zero throws `QuantityMustBePositiveException`.
    - `onHand` and `reserved` through `Quantity`, so negative values throw `InvalidQuantityException`.
  - The constructor additionally enforces payload coherence, because `fromPrimitives()` can rebuild the event from external data (the same pattern as `StockReservedDomainEvent`):
    - Throws `InvalidArgumentException` when `reserved` exceeds `onHand`.
    - Throws `InvalidArgumentException` when `releasedQuantity` exceeds `onHand` minus `reserved`, i.e. the released quantity cannot exceed the available stock after the release.
    - The aggregate always records coherent payloads, so these checks only reject corrupted external data on `fromPrimitives()`.
  - The constructor passes `$stockItemId->value()` to the base `DomainEvent`, so `aggregateId()` always equals `stockItemId->value()`.
  - The constructor stores the `StockItemId` in a readonly property so `toPrimitives()` can serialize it.
  - `fromPrimitives(string $aggregateId, array $body, string $eventId, string $occurredOn): self`
  - `fromPrimitives()` validates the payload shape and primitive types (`InvalidArgumentException`), builds the `StockItemId` from `aggregateId` and delegates every remaining validation to the constructor.
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
  - Reconstructs the event from primitives, preserving the aggregate identifier, payload, event ID and occurrence date.
  - Guarantees that the constructor derives `aggregateId()` from `StockItemId`.
  - Exposes the `inventory.reservation.released` event name.
  - Rejects a `stockItemId` that does not match `aggregateId` in `fromPrimitives()`.
  - Rejects invalid `productId`, `orderId` and `reservationId` in the constructor and when reconstructing from primitives.
  - Rejects zero and negative `releasedQuantity` in the constructor and when reconstructing from primitives, preserving the `QuantityMustBePositiveException` / `InvalidQuantityException` distinction.
  - Rejects negative `onHand` and `reserved` values in the constructor and when reconstructing from primitives.
  - Rejects incoherent payloads in the constructor and when reconstructing from primitives:
    - `reserved` exceeding `onHand`.
    - `releasedQuantity` exceeding the available stock.
  - Rejects every payload field missing through a data provider.
  - Rejects wrongly typed payload fields through a data provider covering both the `Uuid` fields (sent as integers) and the `Quantity` fields (sent as strings).
- `Tests\Unit\Inventory\Application\ReleaseStock\ReleaseStockCommandHandlerTest`:
  - Releases a reservation using only its `ReservationId`, verifying that the persisted aggregate keeps the released reservation with an empty counter.
  - Rejects an unknown reservation.
  - Rejects an already released reservation.
  - Propagates an invalid reservation identifier from the command input.
  - Persists the owning aggregate through the repository port; `save` is not called on rejected flows.
- `Tests\Unit\Inventory\Domain\ObjectMother\StockItemMother` (modified to support released reservations):
  - `withActiveReservation()` registers an aggregate with exactly one active reservation, usable as fixture for the release use case.
  - `withReleasedReservation()` registers an aggregate whose single reservation has already been released through the domain flow, keeping the released reservation and an empty reserved counter.
  - Both fixture helpers pull every event generated by their setup steps, so the returned aggregate has no pending events and tests starting from them only observe the events raised by the operation under verification.

**To-do actions:**

- [x] Add `ReservationNotFoundException` and `ReservationAlreadyReleasedException` with explicit constructors storing the `ReservationId`.
- [x] Implement `StockReservation::release()` returning a new released instance and rejecting repeated release.
- [x] Add `ReservationReleasedDomainEvent` with its event name, payload, primitive reconstruction and aggregate identifier derived from `StockItemId`.
- [x] Implement `StockItem::releaseReservation()` with ownership, status and counter updates.
- [x] Implement `ReleaseStockCommand` and `ReleaseStockCommandHandler` using `searchByReservationId` and `save`.
- [x] Extend the reservation and StockItem tests, add the ReservationReleased event test and the ReleaseStock handler test.
- [x] Extend the object mothers required by released reservations.
- [x] Run the full PHPUnit suite, including Unit, Integration and Api tests, and fix any failures.
- [x] Run ECS and PHPStan on the new and modified Inventory paths and fix any issues introduced by this task without adding baseline entries.
- [x] Verify the changes in terms of typechecking, linting and tests using the project's verification command. Fix issues if any.
- [x] STOP. Present the changes to the user for review and suggest commit messages. Do NOT proceed to any further work until the user explicitly asks.

## Next step

All phases are implemented. The Inventory foundation is complete: initial stock registration, stock addition, reservation and release, each with its domain event validation, unit tests and application use case. Persistence, Outbox publishing and cross-context integration will be addressed by follow-up tasks, not by this plan.

Every reservation finds its box, every box finds its order 📦 ➡️ 🚚, delivered with 🐢 💨 (Turbotuga™, [Codely](https://codely.com)'s mascot).
