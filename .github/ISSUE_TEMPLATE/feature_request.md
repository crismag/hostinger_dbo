---
name: Feature request
about: Suggest an enhancement
labels: enhancement
---

### Problem
What are you trying to do that the gateway doesn't support today?

### Proposed solution
What you'd like to see.

### Scope check
The gateway is intentionally a narrow, allowlisted single-entity layer. Complex,
multi-table, or transactional logic is expected to live in a **service
operation** rather than the generic gateway. Does this belong in core, or as a
service operation? (See `docs/service-authoring.md`.)

### Alternatives considered
