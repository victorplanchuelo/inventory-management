# StockFlow - Agent Instructions

## 1. Project Overview
StockFlow is an inventory and order management API.
It is built as a modular monolith using PHP 8.3 and Laravel 11.

## 2. Technology Stack
- PHP 8.3
- Laravel 11
- MySQL
- RabbitMQ
- Symfony Messenger
- PHPUnit 11
- ECS
- Larastan / PHPStan

## 3. Architecture
- Modular Monolith
- Domain-Driven Design
- Hexagonal Architecture
- CQRS where it provides clear value
- Explicit module boundaries
- Domain logic must not depend on Laravel or infrastructure
- Application layer orchestrates use cases
- Infrastructure implements external dependencies

## 4. Existing Codebase
- Composer PSR-4 mapping: `Manager\\` -> `src/`.
- Existing Shared components must be inspected before creating alternatives.
- Do not assume existing abstractions are correct or reusable without reviewing them.
- Avoid unrelated refactors.

## 5. Development Workflow
Follow this sequence:
1. Research
2. Plan
3. Wait for approval
4. Implement
5. Test
6. Review the diff

Never implement an architectural change without an approved plan.

## 6. Testing
- Unit tests for domain behavior.
- Application tests for use cases.
- Integration tests for persistence and messaging.
- Feature tests for HTTP endpoints.
- Include negative cases and failure scenarios.
- Run the relevant test suite after each implementation phase.
- Existing tests must continue passing unless an intentional behavior change is approved.

## 7. Code Quality
- Follow existing ECS conventions.
- New code must pass ECS.
- Use Larastan for static analysis.
- Do not fix unrelated legacy issues.

## 8. Security
- Never expose credentials or secrets.
- Never include passwords in domain events.
- Validate external input.
- Preserve transaction boundaries and stock consistency.

## 9.Static Analysis
- PHPStan/Larastan is configured with a baseline.
- Never add new errors to the baseline without explicit approval.
- Do not modify or regenerate the baseline automatically.
- New code must pass PHPStan without introducing new baseline entries.
- Existing baseline entries must not be removed or modified without approval.
- Never use the baseline to suppress errors introduced by new code.

## 10. Documentation
Read docs/README.md first.
Read only the documentation relevant to the task.

## 11. Restrictions
- Do not introduce microservices.
- Do not introduce a frontend.
- Do not introduce Redis as a new dependency.
- Do not introduce Event Sourcing without explicit architectural approval.
- Do not update major framework versions without approval.
- Do not commit, push or create PRs without explicit approval.
