# StockFlow Documentation

This directory contains the product definition, architecture, architectural decisions and implementation specifications for StockFlow.

The documentation is the reference for understanding the project scope, architectural constraints and planned implementation work.

## Project

Product definition and functional scope:

- `project/product-overview.md`: product purpose, scope and main capabilities.
- `project/requirements.md`: functional and non-functional requirements.
- `project/acceptance-criteria.md`: verifiable acceptance criteria.

## Architecture

System structure, domain model and architectural conventions:

- `architecture/architecture.md`: overall architecture and bounded-context relationships.
- `architecture/domain-model.md`: domain concepts, aggregates, responsibilities and invariants.
- `architecture/architectural-reference.md`: implementation conventions and architectural patterns.
- `architecture/outbox-operations.md`: operational runbook for the Transactional Outbox, publisher supervision, monitoring, retries, retention and purge.

## Architecture Decision Records

Architectural Decision Records document important decisions, their context and consequences.

- `adr/001-modular-monolith.md`: modular monolith and bounded-context organization.
- `adr/002-atomic-stock-reservation.md`: atomic stock reservation and consistency requirements.
- `adr/003-outbox-rabbitmq.md`: transactional Outbox and reliable RabbitMQ publication.
- `adr/004-integration-events-and-cross-context-communication.md`: integration event contracts, versioning and cross-context communication.
- `adr/005-cross-context-projections.md`: projection ownership, eventual consistency, API read models and reconciliation.

- **[Open Decisions Register](architecture/open-decisions.md)**: registro centralizado de decisiones arquitectónicas pendientes, aplazadas y en revisión. Consulta este documento para conocer los asuntos que deben resolverse antes de abordar determinadas funcionalidades o cambios.

## Tasks

Implementation specifications derived from the requirements, acceptance criteria and architectural decisions.

Start with `tasks/README.md` for the task index, dependencies and implementation workflow.

### Task specifications

| Task | Document | Purpose |
|---|---|---|
| 01 | `tasks/001-inventory-foundation.md` | Inventory domain and application foundation: StockItem, quantities and reservations. |
| 02 | `tasks/002-shared-domain-event-compatibility-and-test-structure.md` | UUID aggregate identifiers, domain-event compatibility and PHPUnit test structure. |
| 03 | `tasks/003-transactional-outbox-and-integration-infrastructure.md` | Atomic Outbox persistence and reliable RabbitMQ publication. |
| 04 | `tasks/004-inbox-idempotent-consumers-and-event-ordering.md` | Idempotent event consumption, Inbox persistence and aggregate-version handling. |
| 05 | `tasks/005-orders-inventory-availability-projection.md` | Orders-owned Inventory availability projection and advisory API query. |

Task documents define scope, responsibilities, dependencies, expected behaviour and verification criteria before implementation begins.

A task marked `Proposed` is a specification awaiting review and approval. It does not mean that the implementation exists or has been approved.

## AI and Development Workflow

Project collaboration agreements and implementation history:

- `ai/working-agreements.md`: development workflow, constraints and collaboration rules.
- `ai/implementation-log.md`: implementation progress and relevant technical findings.

## Documentation Structure

```text
docs/
├── README.md
├── project/
│   ├── product-overview.md
│   ├── requirements.md
│   └── acceptance-criteria.md
├── architecture/
│   ├── architecture.md
│   ├── domain-model.md
│   ├── architectural-reference.md
│   └── outbox-operations.md
├── adr/
│   ├── 001-modular-monolith.md
│   ├── 002-atomic-stock-reservation.md
│   ├── 003-outbox-rabbitmq.md
│   ├── 004-integration-events-and-cross-context-communication.md
│   └── 005-cross-context-projections.md
├── tasks/
│   ├── README.md
│   ├── 001-inventory-foundation.md
│   ├── 002-shared-domain-event-compatibility-and-test-structure.md
│   ├── 003-transactional-outbox-and-integration-infrastructure.md
│   ├── 004-inbox-idempotent-consumers-and-event-ordering.md
│   └── 005-orders-inventory-availability-projection.md
└── ai/
    ├── working-agreements.md
    └── implementation-log.md
```

## Documentation Guidelines

- Keep documentation concise, actionable and consistent with the actual implementation.
- Document architectural decisions in ADRs rather than duplicating them across documents.
- Keep requirements, acceptance criteria, ADRs and task specifications aligned.
- Update documentation when an approved architectural decision or requirement changes.
- Distinguish proposed decisions from accepted decisions and implemented behaviour.
- Do not document proposed implementation details as if they were already implemented.
- Avoid unrelated refactoring or scope expansion when updating existing documents.
- Keep this index synchronized with the actual documentation structure.
- Create new documents only when a real convention, decision, requirement or implementation task needs to be documented.
