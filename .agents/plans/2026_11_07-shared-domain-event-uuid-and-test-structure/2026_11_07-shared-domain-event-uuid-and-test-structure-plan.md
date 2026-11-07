---
name: "shared-domain-event-uuid-and-test-structure"
description: "Use UUID aggregate identifiers in shared domain events and establish reliable Unit, Integration and Api test suites."
created_at: "2026-11-07T18:44:27Z"

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

last_implementation_at: "2026-11-07T18:58:18Z"
has_completed_all_phases: "false"
---

# Shared Domain Event UUID Compatibility and Test Structure

## Goal

Make shared domain events use UUID aggregate identifiers while keeping event identity independent, and reorganize PHPUnit suites so Unit, Integration and Api tests are discovered and reliable.

Preserve the existing User model and its internal identity. Only change the aggregate identifier exposed by its domain event. Do not implement Outbox, Inbox, integration-event envelopes or projections.

## Context

- [AGENTS.md](../../../AGENTS.md): project workflow, architecture constraints and verification expectations.
- [Task 02 specification](../../../docs/tasks/002-shared-domain-event-compatibility-and-test-structure.md): scope, target contract, test structure and acceptance criteria.
- [Architecture](../../../docs/architecture/architecture.md): modular monolith, bounded contexts and event responsibilities.
- [Architectural reference](../../../docs/architecture/architectural-reference.md): domain-event and integration-event guidance.
- [ADR 004](../../../docs/adr/004-integration-events-and-cross-context-communication.md): keeps domain events separate from future integration events.
- [DomainEvent](../../../src/Shared/Domain/Bus/Event/DomainEvent.php): shared event contract using an integer aggregate identifier.
- [DomainEventJsonSerializer](../../../src/Shared/Infrastructure/Bus/Event/DomainEventJsonSerializer.php): serializes event identity and aggregate identity.
- [DomainEventJsonDeserializer](../../../src/Shared/Infrastructure/Bus/Event/DomainEventJsonDeserializer.php): reconstructs events from serialized data.
- [DomainEventMapping](../../../src/Shared/Infrastructure/Bus/Event/DomainEventMapping.php): resolves event names to event classes.
- [UserCreatedDomainEvent](../../../src/Api/User/Domain/UserCreatedDomainEvent.php): only existing concrete domain event.
- [User](../../../src/Api/User/Domain/User.php): records the User-created event.
- [MySqlEloquentEventBus](../../../src/Shared/Infrastructure/Bus/Event/MySql/MySqlEloquentEventBus.php): persists events through the shared event bus.
- [MySqlEloquentDomainEventsConsumer](../../../src/Shared/Infrastructure/Bus/Event/MySql/MySqlEloquentDomainEventsConsumer.php): reconstructs and consumes persisted events.
- [RabbitMqEventBus](../../../src/Shared/Infrastructure/Bus/Event/RabbitMq/RabbitMqEventBus.php): publishes serialized events.
- [RabbitMqDomainEventsConsumer](../../../src/Shared/Infrastructure/Bus/Event/RabbitMq/RabbitMqDomainEventsConsumer.php): consumes serialized events.
- [Domain events migration](../../../database/migrations/0001_01_01_000003_create_domain_events_table.php): already defines `aggregate_id` as UUID.
- [phpunit.xml](../../../phpunit.xml): initially discovered only `tests/Api`; Phase 1 adds the `unit` suite.
- [Shared test support](../../../tests/Shared): mothers, comparators and base test cases used by the suites.
- [User event mother](../../../tests/Api/User/Domain/ObjectMother/UserCreatedDomainEventMother.php): builds the existing User event for tests.
- [User creation test](../../../tests/Api/User/Application/Creator/CreateUserCommandHandlerTest.php): verifies the event published after user creation.

The project had not been installed or executed in the local environment before this work. There are no historical `domain_events` records to preserve, and no historical compatibility migration or backfill is required for this project.

The existing numeric internal User identifier remains unchanged. The UUID becomes the aggregate identifier in `UserCreatedDomainEvent`.

## Phases

### Phase 1: UUID aggregate identity in shared domain events

**Description:** Change the shared domain-event contract to use UUID aggregate identifiers and adapt the existing User event flow without changing the User model's internal identity. Validate aggregate UUIDs at the contract boundary. Add unit tests for event identity, serialization and deserialization, and make the Unit suite discoverable.

**Public contracts:**

- `Manager\Shared\Domain\Bus\Event\DomainEvent`:
  - `__construct(string $aggregateId, ?string $eventId = null, ?string $occurredOn = null)`
  - `abstract public static function fromPrimitives(string $aggregateId, array $body, string $eventId, string $occurredOn): self`
  - `final public function aggregateId(): string`
  - `eventId()` and `occurredOn()` keep their current signatures.
  - The aggregate identifier must be a valid UUID.
- `Manager\Api\User\Domain\UserCreatedDomainEvent`:
  - `__construct(string $uuid, string $name, string $email, string $password, ?string $eventId = null, ?string $occurredOn = null)`
  - The integer User ID is removed from the event constructor.
  - The User UUID becomes the aggregate identifier.
  - `fromPrimitives()` accepts the string aggregate identifier and reconstructs the event with the same UUID.
  - `toPrimitives()` keeps the existing `uuid`, `name`, `email` and `password` attributes.
  - The User model's internal numeric ID and persistence remain unchanged.
- Test suite configuration:
  - New `unit` PHPUnit suite pointing to `tests/Unit`.
  - Existing `api` suite remains unchanged.

**To-do actions:**

- [x] Change `DomainEvent` so the constructor, abstract `fromPrimitives()` and `aggregateId()` use `string`.
- [x] Validate that the aggregate identifier is a valid UUID when a domain event is created. Use the project's existing UUID conventions.
- [x] Update `UserCreatedDomainEvent` to receive the User UUID as its aggregate identifier and remove the integer ID argument.
- [x] Update `UserCreatedDomainEvent::fromPrimitives()` to use the received aggregate identifier as the User UUID and preserve event-specific attributes.
- [x] Update `User::pushDomainEvent()` to pass the User UUID instead of the integer identifier. Do not modify the User model's internal numeric ID.
- [x] Update `UserCreatedDomainEventMother` to remove the `UserId` argument and build the event from the User UUID.
- [x] Update `CreateUserCommandHandlerTest` and any other test constructing `UserCreatedDomainEvent`.
- [x] Keep the serializer payload shape (`data.attributes.id` as the aggregate identifier) and ensure UUID values are not converted.
- [x] Add a minimal fake domain event fixture and subscriber for shared event tests under `tests/Unit/Shared`.
- [x] Add `tests/Unit/Shared/Domain/Bus/Event/DomainEventTest.php` covering:
  - [x] Event creation with a UUID aggregate identifier.
  - [x] Rejection of an invalid aggregate identifier.
  - [x] Independent UUID generation for `eventId`.
  - [x] Preservation of an explicitly supplied `eventId`.
  - [x] Preservation of an explicitly supplied `occurredOn`.
  - [x] Correct return values from `aggregateId()` and `eventId()`.
- [x] Add `tests/Unit/Shared/Infrastructure/Bus/Event/DomainEventJsonSerializerTest.php` covering:
  - [x] Serialization includes the event identifier.
  - [x] Serialization includes the aggregate identifier.
  - [x] Event identifier and aggregate identifier remain distinct.
- [x] Add `tests/Unit/Shared/Infrastructure/Bus/Event/DomainEventJsonDeserializerTest.php` covering:
  - [x] Deserialization reconstructs the correct event.
  - [x] UUID aggregate identifiers survive a complete serialization and deserialization round trip.
  - [x] Event-specific attributes remain intact.
  - [x] `eventId` and `aggregateId` are not confused.
- [x] Add the `unit` suite to `phpunit.xml` pointing to `tests/Unit`.
- [x] Run the Unit and Api suites and fix any failures.
- [x] Verify the changes in terms of typechecking, linting and tests using the project's verification command (look it up in the AGENTS.md file or the project configuration). Fix issues if any.
- [x] STOP. Present the changes to the user for review and suggest commit messages. Do NOT proceed to the next phase until the user explicitly asks.

### Phase 2: Test suite structure and integration regression

**Description:** Move the real Shared tests into Unit and Integration suites, keep shared support code in `tests/Shared`, and verify that UUID aggregate identifiers work through MySQL and RabbitMQ.

**Public contracts:**

- PHPUnit suites:
  - `unit`: `tests/Unit`
  - `integration`: `tests/Integration`
  - `api`: `tests/Api`
- Moved test classes and namespaces:
  - `Tests\Unit\Shared\Infrastructure\Bus\Command\InMemorySymfonyCommandBusTest`
  - `Tests\Unit\Shared\Infrastructure\Bus\Query\InMemorySymfonyQueryBusTest`
  - `Tests\Integration\Shared\Infrastructure\Bus\Event\MySql\MySqlEloquentEventBusTest`
  - `Tests\Integration\Shared\Infrastructure\Bus\Event\RabbitMq\RabbitMqEventBusTest`
  - `Tests\Integration\Shared\Infrastructure\Bus\Event\RabbitMq\TestAllWorksOnRabbitMqEventsPublished`
- `tests/Shared` remains the namespace and location for shared test support: mothers, comparators, base test cases and helpers.

**To-do actions:**

- [ ] Move `InMemorySymfonyCommandBusTest` to `tests/Unit/Shared/Infrastructure/Bus/Command/` and update its namespace.
- [ ] Move `InMemorySymfonyQueryBusTest` to `tests/Unit/Shared/Infrastructure/Bus/Query/` and update its namespace.
- [ ] Move `MySqlEloquentEventBusTest` to `tests/Integration/Shared/Infrastructure/Bus/Event/MySql/` and update its namespace.
- [ ] Move `RabbitMqEventBusTest` and `TestAllWorksOnRabbitMqEventsPublished` to `tests/Integration/Shared/Infrastructure/Bus/Event/RabbitMq/` and update their namespaces.
- [ ] Update imports affected by the moves while keeping `Tests\Shared\...` support imports unchanged.
- [ ] Add `unit`, `integration` and `api` suites to `phpunit.xml`.
- [ ] Extend `MySqlEloquentEventBusTest` to assert that the persisted `domain_events.aggregate_id` is the event UUID and that the consumed event preserves `eventId` and `aggregateId`.
- [ ] Extend `RabbitMqEventBusTest` to assert that the consumed event preserves the UUID `aggregateId` and the independent `eventId`.
- [ ] Verify that the Unit, Integration and Api suites are discovered and runnable independently.
- [ ] Run the complete configured test suite and fix any failures.
- [ ] Verify the changes in terms of typechecking, linting and tests using the project's verification command (look it up in the AGENTS.md file or the project configuration). Fix issues if any.
- [ ] STOP. Present the changes to the user for review and suggest commit messages. Do NOT proceed to the next phase until the user explicitly asks.

### Phase 3: Historical compatibility policy and final verification

**Description:** Document the historical compatibility policy for this project, explicitly state that no historical backfill is required, and close the task with full verification.

**Public contracts:**

- `DomainEventJsonDeserializer` rejects non-string or non-UUID aggregate identifiers with a clear exception.
- No numeric-to-UUID conversion and no invented UUID values.
- No database schema change. The existing `domain_events.aggregate_id` UUID column remains unchanged.
- No backfill migration, because the project had not been installed or executed before this work and has no historical events to preserve.
- Implementation record in `docs/ai/implementation-log.md` following the existing template.

**To-do actions:**

- [ ] Confirm that deserialization explicitly rejects invalid aggregate identifiers and does not convert them.
- [ ] Add a regression test proving that a legacy numeric aggregate identifier is rejected explicitly and not converted.
- [ ] Document the historical compatibility policy in the implementation log: this project has no historical events to preserve, and unsupported identifiers fail explicitly.
- [ ] Record the task implementation in `docs/ai/implementation-log.md`.
- [ ] Run the complete PHPUnit suite, including Unit, Integration and Api tests.
- [ ] Run ECS and PHPStan and fix any issues introduced by this task without adding baseline entries.
- [ ] Verify the changes in terms of typechecking, linting and tests using the project's verification command (look it up in the AGENTS.md file or the project configuration). Fix issues if any.
- [ ] STOP. Present the changes to the user for review and suggest commit messages. Do NOT proceed to any further work until the user explicitly asks.

## Next step

Start Phase 2: move the real Shared tests into Unit and Integration suites, keep shared support code in `tests/Shared`, and verify UUID aggregate identifiers through MySQL and RabbitMQ.

UUIDs validated and tests green thanks to [Codely](https://codely.com) AI tooling. 🆔 ✅ < 🐢 💨
