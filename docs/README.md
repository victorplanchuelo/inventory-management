# StockFlow Documentation

This directory contains the product, architecture, architectural decisions and implementation guidance for StockFlow.

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

## Architecture Decision Records

Architectural Decision Records document important decisions, their context and consequences.

- `adr/001-modular-monolith.md`: modular monolith and bounded-context organization.
- `adr/002-atomic-stock-reservation.md`: atomic stock reservation and consistency requirements.
- `adr/003-outbox-rabbitmq.md`: transactional Outbox and reliable RabbitMQ publication.
- `adr/004-integration-events-and-cross-context-communication.md`: integration event contracts, versioning and cross-context communication.
- `adr/005-cross-context-projections.md`: projection ownership, eventual consistency, API read models and reconciliation.

## Tasks

Implementation specifications derived from the approved requirements, acceptance criteria and architectural decisions.

Task documents define scope, responsibilities, dependencies, expected behavior and verification criteria before implementation begins.

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
│   └── architectural-reference.md
├── adr/
│   ├── 001-modular-monolith.md
│   ├── 002-atomic-stock-reservation.md
│   ├── 003-outbox-rabbitmq.md
│   ├── 004-integration-events-and-cross-context-communication.md
│   └── 005-cross-context-projections.md
├── tasks/
└── ai/
    ├── working-agreements.md
    └── implementation-log.md
```

## Documentation Guidelines

- Keep documentation concise, actionable and consistent with the actual implementation.
- Document architectural decisions in ADRs rather than duplicating them across documents.
- Keep requirements, acceptance criteria and task specifications aligned.
- Update documentation when an approved architectural decision or requirement changes.
- Avoid documenting implementation details that are obvious from the code.
- Keep this index synchronized with the actual documentation structure.
- Create new documents when a real convention, decision, requirement or implementation task needs to be documented.
- Distinguish proposed decisions from accepted decisions and implemented behavior.
