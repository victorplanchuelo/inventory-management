# Open Decisions Register

This document tracks unresolved architectural and technical decisions that may affect future StockFlow implementation tasks.

Its purpose is to prevent decisions, risks and dependencies identified during research or implementation from being lost between tasks or conversations.

This register complements the Architecture Decision Records (ADRs). It does not replace them.

## 1. Status

Each entry must use one of the following statuses:

- **Open:** the decision has been identified but has not been evaluated or resolved.
- **Under Review:** the decision is being actively evaluated as part of a task.
- **Decided:** the decision has been resolved and its outcome has been documented in the relevant architecture document or ADR.
- **Deferred:** the decision has deliberately been postponed, with a reason and a future review trigger.

A decision must not be marked as Decided merely because a preferred option has been discussed. The outcome must be explicitly agreed and documented.

## 2. Open Decisions

### OD-001 — Atomicity of Order Confirmation Across Bounded Contexts

**Status:** Open

**Context:**

The initial transaction model described in ADR 002 was revised to preserve bounded-context ownership.

The current architecture establishes that:

- Orders owns Order persistence and its business lifecycle.
- Inventory owns StockItem persistence, reservations and its own Outbox.
- Inventory reservation is atomic within an Inventory-owned transaction.
- Orders confirmation and its Outbox records belong to an Orders-owned transaction.
- Neither context should access or modify the other's internal persistence.

The business requirement remains that an order must never be confirmed unless all required stock has been successfully reserved.

The coordination protocol required to guarantee this rule across the two contexts has not yet been decided.

**Decision required:**

Determine how order confirmation can guarantee that an order is not confirmed without all required stock being reserved, without silently introducing cross-context persistence coupling or assuming a distributed transaction.

Alternatives to evaluate include synchronous reservation with compensation, a pending-confirmation workflow, and any explicitly justified shared-transaction approach.

**Related documents:**

- `docs/adr/002-atomic-stock-reservation.md`
- `docs/architecture/architectural-reference.md`
- `docs/project/requirements.md`

**Resolution trigger:**

Before implementing atomic order confirmation and cross-context coordination.

---

### OD-002 — Aggregate Versioning and Event Ordering Guarantees

**Status:** Open

**Context:**

Consumers and projections may need aggregate versions to identify stale, duplicated or out-of-order integration events.

The event infrastructure must not assume that RabbitMQ delivery ordering alone guarantees correct projection state.

**Decision required:**

Define:

- Whether aggregate versions are required.
- Which component owns version generation.
- How versions are incremented and persisted.
- Whether version changes must be committed atomically with business state and Outbox records.
- How consumers detect missing versions and recover safely.

**Related documents:**

- `docs/tasks/003-transactional-outbox-and-integration-infrastructure.md`
- `docs/tasks/004-inbox-idempotent-consumers-and-event-ordering.md`
- `docs/tasks/005-orders-inventory-availability-projection.md`
- `docs/adr/003-outbox-rabbitmq.md`
- `docs/adr/004-inbox-idempotent-consumers.md`

**Resolution trigger:**

Before relying on aggregate versions for event publication or projection correctness.

---

### OD-003 — Inventory Domain Events to Integration Event Mapping

**Status:** Open

**Context:**

Inventory domain events represent internal business facts. Integration events are external contracts intended for communication between bounded contexts.

The exact mapping and contract have not yet been fully defined.

**Decision required:**

Determine:

- Which Inventory domain events require external publication.
- Whether initial stock registration, stock addition, reservation and release require separate integration events.
- Exact event names and payloads.
- Event contract versioning.
- Which resulting stock quantities and identifiers must be included.
- How integration contracts remain independent of internal domain classes.

**Related documents:**

- `docs/tasks/003-transactional-outbox-and-integration-infrastructure.md`
- `docs/tasks/006-inventory-persistence-and-stock-reservation.md` (if retained as a future task specification)
- `docs/adr/003-outbox-rabbitmq.md`

**Resolution trigger:**

Before implementing Inventory integration-event publication.

---

### OD-004 — Projection Initialization and Reconciliation

**Status:** Open

**Context:**

Cross-context projections are eventually consistent and need a reliable initial state.

Processing only events published after a consumer starts may leave a projection incomplete.

**Decision required:**

Define:

- How a projection is initialized from existing source data.
- How initialization is coordinated with event consumption.
- How missed events or gaps are detected.
- How projections are reconciled with their source context.
- Whether a controlled snapshot or synchronization mechanism is required.

**Related documents:**

- `docs/tasks/004-inbox-idempotent-consumers-and-event-ordering.md`
- `docs/tasks/005-orders-inventory-availability-projection.md`
- `docs/project/requirements.md`

**Resolution trigger:**

Before implementing the first production-relevant cross-context projection.

---

### OD-005 — Initial Catalog Scope

**Status:** Deferred

**Context:**

Orders and Inventory use ProductId as an external UUID reference. Neither context owns product descriptive information.

Catalog is desirable, but it is not required for the initial Inventory foundation.

**Decision required:**

Determine whether Catalog belongs in the initial delivery scope or should be implemented later.

If deferred, define how ProductIds are supplied and managed during the period in which Catalog does not exist.

**Related documents:**

- `docs/project/requirements.md`
- `docs/architecture/domain-model.md`

**Review trigger:**

Before defining or implementing the Catalog foundation, or when product creation and lifecycle requirements become necessary.

## 3. Review Process

The register must be reviewed at the beginning of every task's Research phase.

For each entry:

1. Determine whether it affects the task being researched.
2. If it does, decide whether it must be resolved before the task plan can be approved.
3. If it must be resolved, evaluate the alternatives and document the agreed outcome.
4. If it does not need to be resolved yet, leave it Open or explicitly mark it Deferred with a review trigger.
5. When a decision is approved, update the relevant architecture document or create/update an ADR.
6. Mark the entry Decided only after the decision has been documented.

A task plan must identify any relevant unresolved decisions and explain whether they block implementation.

## 4. Maintenance Rules

- Assign a stable identifier to every new decision.
- Do not delete resolved entries; retain them as historical records.
- Link each entry to the relevant task, requirement, architecture document or ADR.
- Do not duplicate complete ADR content in this register.
- Do not record already settled decisions as open.
- Add newly discovered architectural conflicts or significant technical decisions during Research and Review.
- Update the register whenever a decision is resolved, deferred or materially changed.

The register is a living project document and must remain synchronized with the actual implementation and approved architecture.
