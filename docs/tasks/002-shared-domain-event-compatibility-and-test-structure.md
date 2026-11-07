# Task 02 — Shared Domain Event Compatibility and Test Structure

**Status:** Proposed  
**Scope:** Shared  
**Phase:** Shared Infrastructure Foundation

## 1. Objective

Make the existing shared domain-event contract compatible with UUID aggregate identifiers and establish a reliable test-suite structure for the project.

This task addresses a compatibility issue identified during the Inventory domain design:

- The project uses UUIDs for aggregate identities.
- The current `DomainEvent` contract requires an integer aggregate identifier.
- The existing `domain_events` database migration already defines `aggregate_id` as a UUID.
- The current PHPUnit configuration does not include the existing Shared infrastructure tests in its declared test suite.

The objective is to correct these inconsistencies before new bounded contexts depend on the shared event infrastructure.

## 2. Scope

### In scope

- Review and evolve the shared `DomainEvent` contract.
- Use UUID-compatible string values for aggregate identifiers.
- Preserve the independent identity of each domain event.
- Adapt existing domain-event implementations to the updated contract.
- Review event serialization and deserialization.
- Review event mapping and consumers for UUID compatibility.
- Add regression tests for UUID aggregate identifiers.
- Review compatibility with existing persisted domain events.
- Establish a consistent test directory convention.
- Configure PHPUnit to discover and execute the intended test suites.
- Preserve existing User functionality and avoid unrelated refactoring.

### Out of scope

- Transactional Outbox implementation.
- Integration-event envelope and contract design.
- RabbitMQ transport redesign.
- Inbox and consumer idempotency implementation.
- Cross-context projections.
- Event Sourcing.
- General redesign of the User bounded context.
- Changes to historical database migrations unless a separately justified compatibility requirement is identified.

## 3. Current Findings

### 3.1 DomainEvent identity

The existing `DomainEvent` class currently maintains two different identifiers:

- `eventId`: A string generated as a UUID when one is not supplied.
- `aggregateId`: An integer identifying the aggregate that generated the event.

These identifiers have different responsibilities and must remain separate.

The current constructor is conceptually:

```php
public function __construct(
    private readonly int $aggregateId,
    string $eventId = null,
    string $occurredOn = null,
)
```

The aggregate identifier is also declared as an integer in:

- `aggregateId()`.
- The abstract `fromPrimitives()` contract.
- Existing concrete domain-event implementations.

### 3.2 Existing User event

`UserCreatedDomainEvent` currently receives:

- An integer user ID.
- A UUID representing the user.
- Other user attributes.

The integer user ID is passed to the parent `DomainEvent` constructor as the aggregate identifier.

The updated contract must use the UUID identifying the User aggregate.

This change must not be interpreted as a general redesign of the User context. Only the changes required to satisfy the shared event contract and its tests belong to this task.

### 3.3 Serialization

The existing serializer places:

- The event identifier in `data.id`.
- The event name in `data.type`.
- The occurrence timestamp in `data.occurred_on`.
- The aggregate identifier in `data.attributes.id`.

The deserializer reads these values and passes the aggregate identifier to `fromPrimitives()`.

The serialization contract must preserve the distinction between event identity and aggregate identity.

The existing `domain_events` migration defines `aggregate_id` as a UUID. This is consistent with the intended aggregate identity, but the serialized event payload and any previously persisted records must also be reviewed.

### 3.4 Test discovery

The current `phpunit.xml` declares a single test suite pointing to:

```text
tests/Api
```

Existing Shared infrastructure event tests are located under:

```text
tests/Shared/Infrastructure/Bus/Event/
```

They are not included in the currently declared PHPUnit suite.

The existing event bus tests use `UserCreatedDomainEvent`, whose aggregate identifier is an integer. Consequently, they do not verify the UUID aggregate-identity scenario required by the new contexts.

## 4. Target Domain Event Contract

The shared domain-event contract must represent two independent identities:

| Property | Responsibility | Target type |
|---|---|---|
| `eventId` | Identifies one specific domain event | UUID string |
| `aggregateId` | Identifies the aggregate that generated the event | UUID string |
| `occurredOn` | Records when the event occurred | Existing timestamp representation |

The following methods must accept and return a UUID-compatible string for the aggregate identifier:

- Constructor.
- `aggregateId()`.
- `fromPrimitives()`.

The event identifier must remain independent and must not be replaced by the aggregate identifier.

The contract should continue to allow an event identifier and occurrence timestamp to be supplied when reconstructing an event from persisted data.

UUID generation and validation should remain consistent with the project's existing shared UUID value-object conventions.

## 5. Existing Event Compatibility

Changing the aggregate identifier type requires reviewing existing event implementations and persisted event data.

In particular:

- Concrete domain events must pass the aggregate UUID to the parent constructor.
- Their `fromPrimitives()` methods must accept the updated aggregate identifier type.
- The serializer must preserve the aggregate UUID without converting it into an integer.
- The deserializer must reconstruct events using the same aggregate UUID.
- Existing event mappings and consumers must continue to work.

### Historical event data

Existing serialized events may contain numeric aggregate identifiers because the previous contract used integers.

These values cannot automatically be interpreted as UUIDs.

Before changing deserialization behaviour, determine whether historical records exist and whether they need to remain consumable.

If historical records must remain supported, define an explicit compatibility strategy. Do not silently convert numeric identifiers into UUIDs or invent UUID values for historical aggregates.

The existing database migration must not be modified merely to accommodate the new contract. Any schema change must be justified by verified incompatibility.

## 6. Serialization and Deserialization Requirements

The event serialization contract must preserve the following properties:

- `eventId` remains the identity of the event.
- `aggregateId` remains the identity of the aggregate.
- Both identifiers survive serialization and deserialization unchanged.
- The event name remains resolvable through `DomainEventMapping`.
- The occurrence timestamp remains unchanged.
- Event-specific primitive attributes remain available to the reconstructed event.

Tests must explicitly verify that `eventId` and `aggregateId` are not confused.

Domain events and integration events remain separate concepts. This task only addresses the existing shared domain-event mechanism; it does not define the future integration-event envelope.

## 7. Test Structure

The project will use separate test roots for different test types while preserving the internal structure of `src/`.

The structure should follow this convention:

```text
tests/
├── Unit/
│   ├── Shared/
│   │   └── Domain/
│   │       └── Bus/
│   │           └── Event/
│   ├── Inventory/
│   └── Orders/
│
├── Integration/
│   ├── Shared/
│   │   └── Infrastructure/
│   │       └── Bus/
│   │           └── Event/
│   ├── Inventory/
│   └── Orders/
│
└── Api/
    └── User/
```

The correspondence between source and test paths must remain straightforward.

Examples:

```text
src/Shared/Domain/Bus/Event/DomainEvent.php
tests/Unit/Shared/Domain/Bus/Event/DomainEventTest.php
```

```text
src/Shared/Infrastructure/Bus/Event/DomainEventJsonSerializer.php
tests/Unit/Shared/Infrastructure/Bus/Event/DomainEventJsonSerializerTest.php
```

```text
src/Shared/Infrastructure/Bus/Event/RabbitMq/RabbitMqEventBus.php
tests/Integration/Shared/Infrastructure/Bus/Event/RabbitMq/RabbitMqEventBusTest.php
```

The exact placement of a test depends on whether it verifies isolated behaviour or interaction with external infrastructure.

Existing API tests must continue to be discoverable. Existing tests must not be deleted or weakened merely to fit the new directory convention.

## 8. PHPUnit Configuration

Review `phpunit.xml` and configure explicit test suites for:

- Unit tests.
- Integration tests.
- Existing API tests.

The configuration must make it possible to run each suite independently and all intended suites together.

Integration tests that require MySQL or RabbitMQ may retain their existing infrastructure requirements. The configuration must not imply that these services are optional when a test actually requires them.

Do not introduce unnecessary changes to environment variables, database configuration, or test bootstrap behaviour.

The existing `.env.testing` decision must be preserved.

## 9. Required Tests

### Domain event unit tests

Add tests verifying:

- Event creation with a UUID aggregate identifier.
- Independent UUID generation for `eventId`.
- Preservation of an explicitly supplied event identifier.
- Preservation of the aggregate identifier.
- Preservation of the occurrence timestamp.
- Correct behaviour of `aggregateId()` and `eventId()`.

### Serialization tests

Add tests verifying:

- Serialization includes the event identifier.
- Serialization includes the aggregate identifier.
- Serialization preserves the distinction between both identifiers.
- Deserialization reconstructs the correct event.
- UUID aggregate identifiers survive a complete serialization/deserialization round trip.
- Event-specific attributes remain intact.

### Existing infrastructure regression tests

Adapt or extend the existing MySQL and RabbitMQ event tests to verify that events with UUID aggregate identifiers can be published and consumed correctly.

The tests must not rely exclusively on an integer aggregate identifier.

### Test discovery verification

Verify that:

- Unit tests are discovered by the Unit suite.
- Integration tests are discovered by the Integration suite.
- Existing API tests remain discoverable.
- The complete configured test run includes the intended suites.

## 10. Security and Existing Payloads

This task must not silently expand the scope of event payloads.

During implementation, review the existing `UserCreatedDomainEvent` payload because it currently includes a password field.

Do not propagate sensitive credentials into new integration-event contracts or projections.

Any change to the historical User event payload must be assessed separately, with consideration for persisted events and existing consumers.

## 11. Acceptance Criteria

- [ ] `DomainEvent` supports UUID aggregate identifiers.
- [ ] `eventId` remains a separate UUID identifying the event itself.
- [ ] `aggregateId` identifies the aggregate and is not confused with `eventId`.
- [ ] Existing concrete domain events comply with the updated contract.
- [ ] Serialization and deserialization preserve both identifiers.
- [ ] Existing event mappings and consumers remain compatible.
- [ ] Historical event compatibility has been investigated and an explicit policy documented.
- [ ] Unit tests cover UUID aggregate identifiers.
- [ ] Serialization and deserialization round-trip tests cover UUID aggregate identifiers.
- [ ] Existing MySQL and RabbitMQ event tests cover the updated contract.
- [ ] PHPUnit discovers Unit, Integration, and existing API tests.
- [ ] Test paths preserve the internal structure of `src/`.
- [ ] Existing API behaviour is not unintentionally broken.
- [ ] No Outbox, Inbox, integration-event, or projection implementation is introduced by this task.

## 12. Dependencies and Follow-up

This task should be completed before Inventory or Orders depend on the shared domain-event contract.

The subsequent Outbox and integration-event work must use explicit integration-event contracts rather than treating domain events as automatically publishable integration events.

The existing domain-event mechanism may continue to serve internal domain-event responsibilities, but cross-context communication will be designed separately.
