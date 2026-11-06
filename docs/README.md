# StockFlow Documentation

## Project
Product overview, requirements and acceptance criteria.

## Architecture
System architecture, domain model and module boundaries.

## ADRs
Architectural Decision Records documenting important architectural decisions.

## AI
Working agreements and implementation history.

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
│   └── domain-model.md
├── adr/
│   ├── 001-modular-monolith.md
│   ├── 002-atomic-stock-reservation.md
│   └── 003-outbox-rabbitmq.md
└── ai/
    ├── working-agreements.md
    └── implementation-log.md
```

## Documentation Guidelines

- Keep documentation concise and actionable.
- Document decisions, not implementation details that are obvious from code.
- Update documentation when an architectural decision changes.
- Avoid duplicating information between documents.
- Keep this index synchronized with the actual documentation structure.
- Create new documents when a real convention, decision or requirement needs to be documented.
