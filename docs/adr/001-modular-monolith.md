

# ADR 001 — Modular Monolith

- **Status:** Accepted as the initial direction
- **Date:** 2026-11-05

## Context

The project must demonstrate domain boundaries and hexagonal architecture without introducing unnecessary operational complexity. The repository is already a Laravel application with shared infrastructure.

## Decision

Maintain a single Laravel deployment and organize the code into `Orders` and `Inventory` modules, with Domain and Application layers. Infrastructure and HTTP entry adapters remain outside the domain.

## Consequences

**Positive:**
- Lower deployment and development costs.
- Local MySQL transactions for orders and inventory.
- Testable internal boundaries.
- The possibility of extracting services in the future if a real need arises.

**Negative:**
- Modular boundaries depend on discipline and code review.
- Modules share the same process, database, and deployment lifecycle.

## Rejected Alternatives

- **Independent microservices:** They introduce distributed coordination and complexity that provide no value within the initial scope.
- **Laravel application without internal boundaries:** It makes coupling between HTTP, persistence, and business rules easier.

