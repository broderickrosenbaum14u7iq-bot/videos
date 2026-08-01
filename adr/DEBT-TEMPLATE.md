# DEBT-NNNN: <Short title of the debt being accepted>

Status: Open | Paid off in Phase N | Extended (see DEBT-MMMM)

Filed in: Phase N, commit <short hash, filled in after commit>

## What this is

The specific, concrete gap between what was implemented and what genuinely production-quality implementation of this same piece of `ARCHITECTURE.md` would look like. Name the file(s)/method(s) involved directly — this is not a general statement about the phase, it's about one identified shortfall.

This is **not** an architecture change — the frozen design in `ARCHITECTURE.md` is unaffected. If what you're documenting actually requires changing a frozen decision, use `adr/TEMPLATE.md` instead, not this one.

## Justification

Why this specific gap is being accepted now, concretely. Not "ran out of time" as a bare excuse — what tradeoff was actually being made, and against what? (Example of a real justification: "Correct behavior requires real production query-pattern data that doesn't exist yet; building it now would be guessing, and guessing wrong costs more to unwind than shipping the simpler version and revisiting once Phase N generates real usage data.")

## Impact

What this costs, named specifically — not hand-waved as "minor":

- **Correctness**: does this produce wrong results for any input, or just suboptimal ones?
- **Performance**: measured or reasoned impact, referencing `BENCHMARKS.md` where relevant.
- **Security**: any exposure this creates, however small.
- **Maintainability**: what does a future engineer need to know to avoid being surprised by this?

## Removal plan

The concrete fix, described precisely enough that a different engineer could execute it without re-deriving the reasoning from scratch. Not "do it properly" — the actual steps.

## Target removal phase

`Phase N` — a specific number. Not "eventually," not "when we have time."

## Outcome

Filled in when the debt is actually paid off: which commit removed it, and whether the removal plan above matched what actually happened. If the target removal phase arrived and the debt wasn't paid off, this section explains why and points to the new Debt ADR that extends it (`DEBT-MMMM`) — extensions should be rare and are themselves worth surfacing to the user, not a routine occurrence.
