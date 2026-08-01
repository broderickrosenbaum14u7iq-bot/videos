# ADR-NNNN: <Short title of the decision>

Status: Proposed | Accepted | Rejected | Superseded by ADR-NNNN

Date: YYYY-MM-DD

## Frozen decision being changed

Quote or reference the exact item from `ARCHITECTURE_FREEZE.md`'s Frozen Decisions list this ADR proposes to change. If this isn't changing a frozen decision — e.g. it's resolving a Flexible or Deferred item instead — say so explicitly and note that the four-part requirement (this template) still applies by choice/precedent, not because the freeze rule strictly required it for that item.

## Trigger

Exactly one of the three conditions `DEVELOPMENT_RULES.md` §8 permits. State which one, and back it with the actual evidence — not a restatement of preference:

- [ ] **Measurable benchmark** proves the current design insufficient — attach the benchmark: what was measured, how, under what load, and the actual numbers.
- [ ] **Production issue** requires it — link the incident, describe what broke and why the frozen design was the cause, not a symptom of something else.
- [ ] **New functional requirement** makes it necessary — cite the requirement and why the frozen design cannot satisfy it, not just why a different design would be nicer.

## Context

What is the current (frozen) design, and why was it chosen originally? Link back to `ARCHITECTURE_FREEZE.md` / `ARCHITECTURE-OPTIMIZATION-REVIEW.md` / `ARCHITECTURE-CHANGELOG.md` rather than re-explaining it from scratch.

## Decision

What is the new design? Be as concrete as the original architecture documents were — this is a spec, not a proposal sketch.

## Alternatives considered

What else was considered besides the proposed change (including "do nothing")? Why was each rejected? A change proposal with no alternatives considered has not been thought through as rigorously as the original frozen decision was.

## Migration plan

How does the system get from the current state to the new one? Specify the order of operations and confirm there is no point in the middle where the system is in an inconsistent state. If this touches a database table, follow the same expand/contract pattern already required of ordinary migrations (`ARCHITECTURE.md` §18.1).

## Rollback plan

How is this undone if it doesn't work? Must be as concrete as a migration's `down()` — not "we would figure it out."

## Impact analysis

Every phase, plugin, and already-shipped feature this change touches, checked explicitly — not assumed safe by default:

- Which plugins' code changes?
- Which already-built phases (`PHASE-X.md`) does this affect, and how was backward compatibility verified?
- Which frozen decisions in `ARCHITECTURE_FREEZE.md` does this have knock-on effects on, even if they aren't the one directly being changed?
- Performance/scalability impact, measured or estimated, against the assumptions stated in `ARCHITECTURE_FREEZE.md`'s Performance/Scalability Assumptions sections.

## Outcome

Filled in after the change ships: did it work as predicted? Log a summary entry in `ARCHITECTURE-CHANGELOG.md` pointing back to this ADR.
