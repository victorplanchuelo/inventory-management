# Implementation Tasks

This directory contains the implementation specifications for StockFlow.

Each task describes a bounded piece of work derived from the project requirements, acceptance criteria and architectural decisions. A task specification does not imply that its implementation has started or been approved.

## Existing Task Specifications

| Task | Document | Purpose | Status |
|---|---|---|---|
| 001 | `001-inventory-foundation.md` | Inventory domain and application foundation. | Proposed |
| 002 | `002-shared-domain-event-compatibility-and-test-structure.md` | UUID aggregate identifiers, shared domain-event compatibility and test structure. | Verified |
| 003 | `003-transactional-outbox-and-integration-infrastructure.md` | Transactional Outbox and reliable RabbitMQ publication. | Proposed |
| 004 | `004-inbox-idempotent-consumers-and-event-ordering.md` | Idempotent message consumption, Inbox and event ordering. | Proposed |
| 005 | `005-orders-inventory-availability-projection.md` | Orders-owned Inventory availability projection and advisory queries. | Proposed |

The table describes the documents currently present. It is not a commitment to execute tasks in numerical order. Execution order must respect the actual dependencies and the implementation plan approved for each task.

**Current implementation focus:** Task 001 — Inventory Domain and Application Foundation.

Task 002 has been implemented and verified. Task 001 is the next task to research, plan and implement.

Future capabilities must not be treated as current implementation scope merely because they appear in requirements, ADRs or proposed task documents.

## Task Specification Structure

Every task specification should define the following:

1. **Objective:** the behavior or capability to implement.
2. **Scope:** included behavior, exclusions and bounded-context ownership.
3. **Architectural Context:** relevant requirements, acceptance criteria, architecture documents, ADRs and existing contracts.
4. **Research Findings:** inspected files, existing behavior, reusable components, verified facts, assumptions and risks.
5. **Design and Responsibilities:** Domain, Application and Infrastructure responsibilities, including relevant ports and adapters.
6. **Files to Create or Modify:** proposed files and their responsibilities, subject to validation during planning.
7. **Implementation Steps:** small, ordered and reviewable increments.
8. **Testing Strategy:** tests needed to verify behavior and failure cases.
9. **Acceptance Criteria:** observable and verifiable completion conditions.
10. **Risks and Open Decisions:** unresolved questions and alternatives requiring evaluation.
11. **Out of Scope:** functionality and refactoring excluded from the task.

## Workflow

Every task follows this workflow:

**Research → Documentation → Plan → Explicit Approval → Implement → Verify → Review**

### Research

- Inspect the relevant repository files and dependencies.
- Verify existing contracts, conventions and compatibility.
- Separate verified facts from assumptions.
- Identify risks and unresolved decisions.
- Do not modify files.

### Documentation

- Update only documentation needed to reflect verified facts or approved decisions.
- Keep requirements, architecture, ADRs and task specifications consistent.
- Do not turn future ideas into current implementation requirements.
- Do not duplicate complete ADR content in task specifications.

### Plan

- Define the approved scope.
- Identify files and responsibilities.
- Describe implementation steps.
- Define tests and acceptance criteria.
- Present unresolved decisions and alternatives.

Implementation must not begin before the plan has been explicitly approved.

### Implement

- Implement only the approved scope.
- Work in small increments.
- Add relevant tests.
- Avoid unrelated refactoring and dependencies.
- Do not change global contracts or architecture without approval.

### Verify and Review

- Report commands actually executed and their results.
- Review the complete diff.
- Explain relevant design decisions and trade-offs.
- Identify remaining risks and technical debt.
- Confirm that the implementation meets the acceptance criteria.

## Task Status

- **Proposed:** documented but not approved for implementation.
- **Approved:** scope and implementation plan explicitly approved.
- **In Progress:** implementation has started within the approved scope.
- **Implemented:** implementation changes have been completed.
- **Verified:** required checks and acceptance criteria have been verified.
- **Blocked:** progress requires an unresolved dependency or decision.

A task must not be marked as approved merely because its specification exists. A task must not be marked as verified without actual verification.

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
- Avoid unrelated refactoring.

## Documentation Maintenance

- Keep task specifications aligned with requirements and accepted architectural decisions.
- Update affected documentation when an approved decision changes.
- Record completed implementation work in `docs/ai/implementation-log.md`.
- Keep task scope small enough for meaningful review.
- Keep this index synchronized with the actual task documents and their status.

## Architectural decisions

Before starting the Research phase of a task, review the [Open Decisions Register](../architecture/open-decisions.md).

For each task:

- Identify the open decisions relevant to its scope.
- Resolve any decision that blocks the task before proceeding to implementation planning.
- If a decision is not required yet, leave it open or deferred and preserve its resolution trigger in the register.
- Update the register when a decision is resolved, linking to the ADR or architecture document that records the outcome.

The register is the central reference for pending architectural decisions. Task documents should reference relevant decision IDs rather than duplicating their full content.
