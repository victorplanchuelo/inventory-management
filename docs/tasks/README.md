# Implementation Tasks

This directory contains implementation specifications for StockFlow.

Each document describes a bounded implementation task derived from the approved requirements, acceptance criteria and architectural decisions.

Task specifications are intended to make implementation incremental, reviewable and testable.

## Task Organization

Tasks are organized around business capabilities and architectural responsibilities rather than individual classes or files.

The initial proposed task sequence is:

1. Inventory domain and application foundation.
2. Transactional Outbox and integration event infrastructure.
3. Inventory persistence and stock reservation.
4. Orders domain and application foundation.
5. Atomic order confirmation and inventory coordination.
6. Cross-context integration event consumers.
7. Cross-context projections and API read models.
8. Catalog foundation, if included in the approved scope.

This sequence is provisional. Dependencies discovered during research may require adjustments.

Each task must have its own specification document.

## Task Specification Structure

Every task specification should contain the following sections.

### 1. Objective

Describe the behavior or capability to implement and the problem it solves.

### 2. Scope

Define:

- Included behavior.
- Explicit exclusions.
- Bounded context ownership.
- Relevant use cases.

Avoid combining unrelated capabilities in one task.

### 3. Architectural Context

Identify the applicable:

- Requirements.
- Acceptance criteria.
- Architecture documents.
- ADRs.
- Existing contracts and conventions.

Explain how the task fits into the existing architecture.

### 4. Research Findings

Before planning implementation, document:

- Files and dependencies inspected.
- Existing behavior and conventions.
- Reusable components and contracts.
- Compatibility constraints.
- Verified facts.
- Hypotheses, risks and unresolved questions.

Do not present assumptions as verified facts.

### 5. Design and Responsibilities

Describe:

- Domain responsibilities.
- Application use cases and ports.
- Infrastructure adapters.
- API responsibilities, when applicable.
- Persistence and transaction boundaries.
- Integration event interactions, when applicable.

Identify which bounded context owns each responsibility.

### 6. Files to Create or Modify

List the expected files and their responsibilities.

Distinguish between:

- New files.
- Existing files to modify.
- Existing files to reuse without modification.

The list is a planning proposal and must be validated before implementation.

### 7. Implementation Steps

Break the work into small, ordered and independently reviewable increments.

Each step should have a clear expected outcome.

### 8. Testing Strategy

Define the tests required to demonstrate the behavior.

Include, where relevant:

- Unit tests for domain invariants.
- Application tests for use cases and orchestration.
- Integration tests for persistence and messaging adapters.
- API tests for HTTP behavior.
- Negative tests for invalid operations.
- Concurrency and transaction tests.
- Duplicate delivery and failure recovery tests.

Do not claim that tests have passed until they have actually been executed.

### 9. Acceptance Criteria

List the acceptance criteria that the implementation must satisfy.

Criteria must be observable and verifiable.

### 10. Risks and Open Decisions

Record:

- Technical risks.
- Compatibility concerns.
- Unresolved architectural questions.
- Alternatives that require evaluation.
- Decisions that require explicit approval.

Do not silently resolve architectural decisions outside the approved scope.

### 11. Out of Scope

Explicitly identify functionality and refactoring that must not be included.

## Workflow

Every task follows the project workflow:

**Research → Plan → Explicit Approval → Implement → Verify → Review**

### Research

- Inspect the relevant repository files and dependencies.
- Verify existing contracts and conventions.
- Identify risks and missing information.
- Do not modify files.

### Plan

- Define the implementation scope.
- Identify files and responsibilities.
- Describe implementation steps.
- Define tests and acceptance criteria.
- Present alternatives and unresolved decisions.

Implementation must not begin before explicit approval.

### Implement

- Implement only the approved scope.
- Work incrementally.
- Add relevant tests.
- Avoid unrelated refactoring and dependencies.
- Run applicable quality checks.

### Verify and Review

- Report executed commands and actual results.
- Show the complete diff.
- Explain relevant design decisions.
- Identify remaining risks and technical debt.
- Ensure the person responsible can explain the implementation.

## Status

Each task specification must identify its current status:

- **Proposed:** Documented but not approved for implementation.
- **Approved:** Scope and implementation plan explicitly approved.
- **In Progress:** Implementation has started within the approved scope.
- **Implemented:** Changes have been completed.
- **Verified:** Required checks and acceptance criteria have been verified.
- **Blocked:** Progress requires an unresolved dependency or decision.

A task must not be marked as approved merely because its specification has been written.

## General Constraints

All tasks must respect the following project constraints:

- Modular monolith architecture.
- Hexagonal architecture within bounded contexts.
- Domain independence from frameworks and infrastructure.
- No direct cross-context repository or database access.
- No microservices.
- No Redis.
- No Event Sourcing.
- No unnecessary generic CQRS or projection frameworks.
- No unapproved dependency or major-version changes.
- Preserve existing quality-tool baselines unless explicitly approved otherwise.

## Documentation Maintenance

- Keep task specifications aligned with requirements and acceptance criteria.
- Update affected documentation when an approved architectural decision changes.
- Record completed implementation work in `docs/ai/implementation-log.md`.
- Keep task scope small enough for meaningful review.
- Do not duplicate complete ADR content; reference the relevant decisions instead.
