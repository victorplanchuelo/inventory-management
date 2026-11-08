

# AI Working Agreements

## Objective

Use AI assistants to accelerate research, planning, implementation, and review without delegating understanding or architectural decisions.

The person responsible for the repository must be able to explain, modify, and defend every change.

## Mandatory Workflow: Research → Documentation → Plan → Explicit Approval → Implementation → Verify → Review

### Research

The AI must:

- Read the files involved and their dependencies.
- Describe the current behavior.
- Identify existing conventions, contracts, and tests.
- Check versions and compatibility.
- Separate verified facts from hypotheses.
- Not modify any files.

### Plan

The AI must propose:

- Objective and scope.
- Files to be changed.
- Design and responsibilities.
- Tests, including negative test cases.
- Acceptance criteria.
- Risks and alternatives.

Implementation must not begin until explicit approval is received.

### Implement

The AI must:

- Change only the approved scope.
- Work in small increments.
- Add relevant tests.
- Avoid unrequested refactoring and dependencies.
- Run the available checks.
- Show the diff and explain decisions.

## Rules for the Person Responsible

1. Do not accept code that cannot be explained.
2. Review the complete diff before integrating changes.
3. Run tests locally; do not rely solely on the agent's summary.
4. Pay special attention to negative and concurrency tests.
5. Keep commits small and descriptive.
6. Record architectural decisions in ADRs.
7. Close the chat after a task and check whether the design can be explained without assistance.
8. Do not allow the AI to change major versions, contracts, or global structure without approval.

## Review Questions for Every Change

- What problem does it solve?
- Why does this responsibility belong in this layer?
- Which alternative was rejected and why?
- Which invariants does it protect?
- What happens if it fails halfway through an operation?
- What happens if the message is delivered twice?
- Which tests demonstrate the behavior?
- Which existing files or modules may be affected?

## Limits

- Do not generate the entire project in a single request.
- Do not invent Shared classes or methods.
- Do not propagate secrets or sensitive data in events.
- Do not introduce microservices, Redis, complex CQRS, or event sourcing without an explicit decision.
- Do not hide analysis failures by disabling rules or reducing coverage.
- Do not claim that a test was executed if it was not actually run.

## Definition of Done

A task is complete when:

- It meets the acceptance criteria.
- It has appropriate tests.
- It passes the applicable checks or clearly documents any blockers.
- The diff contains no unrelated changes.
- The person responsible understands the code and can explain its trade-offs.

