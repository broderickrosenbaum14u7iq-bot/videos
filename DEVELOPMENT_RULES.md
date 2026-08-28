# Development Rules

This file is the canonical, durable record of every process and quality rule governing implementation of this project. It exists because sessions have no memory of prior conversations — **read this file and ARCHITECTURE.md in full before starting or resuming work on any phase.** Nothing here should ever be treated as "understood" from a prior session; if it isn't written here, it isn't a rule.

---

## 1. Phase discipline

- Implement **exactly one phase at a time**, exactly as defined in `ARCHITECTURE.md` §12 (Implementation Phases). Never skip a phase. Never implement multiple phases together, even partially.
- **The architecture is frozen (`ARCHITECTURE_FREEZE.md`). While it stays frozen, the job is implementation excellence, not architecture work.** For every phase:
  1. Implement exactly what `ARCHITECTURE.md` specifies — no more, no less.
  2. Do not redesign anything `ARCHITECTURE.md` already specifies.
  3. Do not introduce new architectural patterns.
  4. Do not introduce new abstractions beyond what `ARCHITECTURE.md` and this file already call for.
  5. Do not expand a phase's scope beyond what §12 assigns it.
  6. Production-quality code only.
  
  Do not ask whether the architecture should change while implementing a phase. Assume it's correct. The only path to changing it is §8's ADR process, and only when implementation *proves* — not merely suggests — the frozen design is objectively insufficient; that should be rare, not a routine outcome of writing code.
- Before starting a phase's new work, run the Architecture Drift Report (§6 below) against the codebase as it stands from prior phases — while the architecture is frozen this is a quick confirmation that nothing has silently drifted since the freeze, not an invitation to reopen design questions. Also check `adr/DEBT-*.md` (§10 below) for any open debt targeted at this phase for removal — paying it off is part of this phase's scope from the start, not an afterthought.
- Finish a phase completely — including its tests, its documentation, an Implementation Review (§7 below) of every commit in it, a Benchmark Report (§9 below) compared against the previous phase, and a Technical Debt Budget of zero (§10 below) unless a Debt ADR is filed in the same commit — before starting the next one.
- **Stop and wait for explicit user approval after every phase.** Do not begin the next phase on your own judgment that the previous one looks done.
- Every phase produces a `PHASE-X.md` (e.g. `PHASE-0.md`, `PHASE-1.md`) documenting what was built and the verification evidence for it — not a summary of intent, actual evidence (command output, live checks).
- If a phase needs a follow-up correction after its initial commit (e.g. an audit), document and commit that separately rather than silently amending history — see `PHASE-1-AUDIT.md` for the precedent.

## 2. Code quality

- **Production quality only.** No shortcuts, no `TODO` placeholders, no temporary or stub implementations, no "fill this in later."
- Every database table is created via a migration. Every migration must be **genuinely reversible** — a `down()` that exactly undoes its `up()` — and this must be verified live (apply, roll back, re-apply, inspect the actual schema/data), not just reviewed by reading the code.
- Every public function/method is fully documented (complete docblocks — description, `@param`, `@return`, `@throws` where relevant).
- Follow **WordPress Coding Standards (WPCS) + PSR-12** together. The two rulesets conflict in several well-identified places (tabs vs. spaces, padded vs. unpadded parens, camelCase vs. snake_case method names, several PHPCSExtra "Universal" sniffs); every such conflict and its resolution is documented with reasoning directly in `phpcs.xml` — read it, don't re-derive these from scratch.
- Every plugin must be **independently testable**: its own `composer.json`, its own PSR-4 autoloading, no plugin depending on another plugin's `composer.json`. Anything WordPress- or database-coupled that has meaningful logic worth testing gets that logic isolated behind a small interface (e.g. `SchemaVersionRepositoryInterface`, `HookBusInterface`) so it can be unit-tested with a fake, instead of requiring a full WordPress bootstrap. **An interface is only created when it clears this bar — a realistic second implementation, meaning a real competing implementation or a test fake that will actually be built and used, not vendor-swap speculation** (`ARCHITECTURE.md` §19.1). If nothing will ever realistically implement it a second way, it's a concrete class, not an interface.
- **No generic service container / no service locator.** Dependencies are constructor-injected or obtained via a plugin's own typed, hand-written accessor methods on its bootstrap class (e.g. `Plugin::migration_runner()`, `Plugin::events()`) — never a string- or class-keyed container `get()` call from arbitrary code. See `ARCHITECTURE.md` §19.2 for why this was explicitly considered and rejected, and the concrete trigger for reconsidering it.
- **Bulk writes, not loops.** Code writing multiple rows to a relationship table in response to a single event/save uses one multi-row `INSERT`, never a loop of single-row inserts (`ARCHITECTURE.md` §19.8).
- **PHP 8.3 only.** `declare(strict_types=1)` as the first statement in every PHP file. Full type declarations everywhere.
- **Every change must pass `phpcs` (exit 0), `phpunit` (all green), and static analysis before it is committed.** Not "mostly passes" — zero errors, zero warnings, unless a warning is a deliberate, documented, justified exception. Static analysis tool: PHPStan with WordPress stubs (`szepeviktor/phpstan-wordpress`), at a level chosen once it's set up (as a small dev-tooling task, not mid-phase) — this project's full type declarations and `strict_types=1` discipline should make a reasonably strict level (6+) achievable from the start rather than something to work up to.
- **Keep backward compatibility with every previous phase.** After each phase's changes, verify live (reactivate the plugin in staging, re-run the previous phase's own checks) that nothing earlier broke — don't just assume additive changes are safe.
- **Testing-architecture checkpoint.** The decision to defer a full `WP_UnitTestCase` integration suite (Phase 1) must be explicitly reconsidered before Phase 5 (import pipeline) or Phase 6 (`tube-player`), whichever comes first (`ARCHITECTURE.md` §19.9) — not left open-ended indefinitely.

## 3. Environment

- **Production deployment by an AI assistant is prohibited by default. It is permitted only when the project owner explicitly authorizes a production deployment in the current conversation. Before modifying production, the assistant must create or verify a rollback point/backup, preserve persistent production data and secrets, deploy only an explicitly approved commit/tag, perform a production health check, and rollback on a severe regression.** Absent that explicit, in-conversation authorization, all implementation work happens only in the local Docker staging environment (`docker-compose.yml`, see `PHASE-0.md` for how to bring it up) against `root@139.99.96.155:/www/wwwroot/phimtoico.org`.
- **WP-Cron is never used.** Every background/scheduled task runs via Linux cron invoking WP-CLI directly (`ops/cron/staging.cron`), per `ARCHITECTURE.md` §7. `DISABLE_WP_CRON` is set to `true`.

## 4. Commit discipline

- Commit only when a phase (or an explicitly-scoped follow-up like an audit) is genuinely complete — no partial/WIP commits.
- Write commit messages that explain *why*, not just *what* — a future session with no memory of this conversation should be able to understand the reasoning from `git log` alone.
- Never force-push, never amend a commit that's already been discussed as complete with the user.

## 5. Architecture Regression Review — dormant while the architecture is frozen

**While the architecture is frozen (`ARCHITECTURE_FREEZE.md`), this review does not run as a separate step.** Its concerns are folded into the Implementation Review (§7) as "does this match what `ARCHITECTURE.md` specifies," since asking "should the architecture change" is exactly what's now out of scope per §1 and `ARCHITECTURE_FREEZE.md`. This section stays on the books, unchanged, for the (expected to be rare) event that an ADR under §8 is actually underway — at that point, and only then, run it in full as originally written:

Before committing any phase, review everything that phase introduced (re-reading the actual changed files fresh, not reusing reasoning from when they were written) and check whether it:

1. violates `ARCHITECTURE.md`
2. duplicates existing code
3. increases coupling
4. breaks dependency inversion
5. introduces hidden technical debt
6. reduces scalability (this project is designed for 500,000+ videos and millions of pageviews)
7. creates future migration problems

**If the review finds anything, fix it before committing** — never commit with a known issue deferred to "later." Include the review as a dedicated section inside that phase's `PHASE-X.md`, with an actual finding for each of the seven criteria (not a bare pass/fail) — name borderline points that were considered and accepted, with the reasoning, the same way other interpretation decisions in this project are documented rather than left implicit.

This step is not optional and is not skipped for any future phase, regardless of how small the phase seems.

## 6. Architecture Drift Report — reduced scope while the architecture is frozen

**While the architecture is frozen, run this only as a quick confirmation-of-no-drift check before a phase begins** (per §1) — the 8 criteria below are still worth a fast pass to catch an accidental slip (e.g. a stray cross-plugin reference, a static property that shouldn't exist), but finding nothing is the expected, normal outcome, not a prompt to look harder for something to redesign. If it does find something, that's implementation drift to fix, not a reason to reconsider the frozen decision itself, unless it genuinely meets an §8 ADR trigger.

Where §5 checks what a phase is about to *introduce*, right before committing it, this check runs at the opposite end: **before a phase's new work starts**, against the codebase exactly as prior phases left it. Its job is to catch structural drift that accumulates gradually across phases — the kind of thing no single phase's own regression review would flag, because each phase in isolation looked fine.

Verify, against the actual current code (re-read it fresh, don't reuse a prior session's assessment):

1. **No circular dependencies.** No plugin may depend on another plugin that (directly or transitively) depends back on it — `tube-core` in particular must never depend on `tube-player`/`tube-search`/`tube-seo`/`tube-admin`/`tube-cache`. Within a plugin, no two classes may depend on each other.
2. **No service locator pattern.** Classes receive their dependencies through their constructor. `Plugin::instance()->migration_runner()` / `->events()` are the *composition root* — the one place concrete wiring is expected — calling them from `tube-core.php` or from `Plugin` itself is correct. A different class reaching into `Plugin::instance()->something()` from inside its own unrelated logic, instead of having that dependency injected, is the anti-pattern this rule exists to catch.
3. **No hidden singleton growth.** `Plugin::$instance` is the one deliberate, reviewed singleton (the composition root). Any *other* class quietly growing static state (a static cache, a static registry, a `private static` property outside `Plugin`) without that being an explicit, documented architectural decision is a violation.
4. **No God classes.** No single class accumulating unrelated responsibilities. `Plugin.php` is the known watch point (flagged in the Phase 2 regression review) — each phase that adds another accessor to it should keep those accessors thin and symmetric (construct-or-return-cached, nothing more); if `Plugin.php` starts containing real logic instead of wiring, that's the signal to extract.
5. **No duplicated abstractions.** Two different mechanisms solving the same problem (e.g. two different "validate against a known list and throw" implementations) should be one. A small amount of structurally-similar code at a handful of call sites is not automatically a duplicated abstraction — judge whether extracting a shared helper would reduce real complexity or just add a layer of indirection over three lines of code.
6. **No unnecessary interfaces.** Every interface in the codebase (`MigrationInterface`, `SchemaVersionRepositoryInterface`, `HookBusInterface`, ...) must have a real payoff: either more than one real implementation, or a concrete test-fake that's actually used to unit-test something WordPress/database-coupled without WordPress loaded. An interface with exactly one implementation and no test benefit is speculative and should be inlined back into a concrete class.
7. **No premature optimization.** No caching layer, index, denormalization, or complex data structure that isn't justified by an actual requirement already written in `ARCHITECTURE.md` or a concrete, present need. (The Phase 1 audit's `name_idx` addition is the model for what *is* justified: it was required by an explicit, already-approved architecture requirement, not spec work done "just in case.")
8. **No violation of plugin boundaries.** No plugin queries another plugin's database tables directly, calls another plugin's internal (non-public) classes, or reaches past another plugin's documented public API. Every plugin's `composer.json` remains independently installable — no plugin's `composer.json` requires another plugin's package.

**If this report finds anything, refactor it before continuing with the phase's new work** — the phase does not proceed on top of known drift. Include the report as a dedicated section in that phase's `PHASE-X.md`, with an actual finding for each of the eight criteria (not a bare pass/fail), the same way §5's report works.

This step is not optional and is not skipped for any future phase, regardless of how small the phase seems.

## 7. Implementation Review — run before every commit

While the architecture is frozen, this is the primary pre-commit gate — it supersedes §5 for routine work (folding "does this match `ARCHITECTURE.md`" into Correctness below) and narrows §6 to a quick drift check, per §1. **This review does not ask whether the architecture should change.** It asks whether the code sitting in front of you is genuinely production-ready implementation of the architecture as already specified.

**Before every commit** (not just phase-completion commits — every commit, including small documentation or fix-up ones), review the actual diff as if it were written by another engineer you have no reason to trust, and whose commit you are inclined to reject. Do not soften findings to be encouraging. Do not stop at the first pass if a meaningful improvement is still available — keep going until there genuinely isn't one.

Review these dimensions:

- **Correctness** — does this do what `ARCHITECTURE.md` specifies, exactly, and does it do what it claims to do for every input it will realistically see, not just the happy path?
- **Readability** — would another engineer understand this without needing you to explain it?
- **Maintainability** — code a different engineer, or the same engineer a year from now, would struggle to safely change.
- **Performance** — anything that will be slow at the scale this project targets (500,000+ videos, millions of pageviews), not just at today's near-empty data volume.
- **Security** — unescaped output, unprepared queries, missing capability/nonce checks, input trusted across a privilege boundary.
- **Testability** — is the code structured so its real logic *can* be unit-tested against a fake (per §2's interface-justification rule), not just whether a test happens to exist.
- **Memory usage** — anything materializing more data in memory at once than it needs to (e.g. loading a full result set to process one row at a time).
- **Database queries** — every query reviewed for: **N+1 queries** (a loop issuing one query per iteration where a single batched query would do), **missing indexes** (an added query's `WHERE`/`JOIN`/`ORDER BY` actually covered by an existing index, not assumed), no `SELECT *`, no unbounded result sets, no query that could be batched or cached instead.
- **Cache usage** — correct invalidation for every dimension a cache key should vary by, no stale-read risk, no cache-stampede risk on a hot key, and no unnecessary **cache misses** from an overly-specific key or a cache check placed after the expensive work it should have skipped.
- **REST API correctness** — correct nonce/capability checks, correct HTTP methods/status codes, response shape matches what's documented, respects the `/tube/v1` additive-only versioning rule (`ARCHITECTURE.md` §9, frozen per `ARCHITECTURE_FREEZE.md`).
- **WordPress Coding Standards / PSR-12** — `phpcs` exit 0 is necessary but not sufficient; read the diff for anything the linter can't catch (e.g. a WPCS-compliant but semantically wrong escaping function for the context).
- **PHPUnit** — not just "tests pass," but whether the tests added actually exercise the real risk in this change, not just the trivially-true path.
- **Static analysis** — `phpstan` clean at the project's configured level once that tooling exists (§2); until then, apply the same scrutiny manually (type-safety, unreachable code, always-true/false conditions).
- **Race conditions** — any read-then-write, check-then-act, or shared-mutable-state sequence that isn't safe under concurrent requests. Matters especially for anything touching view counters, migration state, or cache.
- **Migration and rollback risk** — anything that could lock a large table longer than acceptable, or is only safe against an empty table not a populated one; does every migration's `down()` genuinely, exactly reverse its `up()`.
- **Event ordering** — any assumption that events fire in a particular order, or that one listener runs before/after another, that WordPress's hook system doesn't actually guarantee.
- **Duplicated code** — logic copy-pasted instead of reused from where it already exists.
- **Dead code** — unused methods, properties, imports, or branches that can never execute.
- **Unnecessary SQL** — a query that isn't needed at all (data already in hand, or derivable without hitting the database).
- **Unnecessary object/allocation overhead** — objects or arrays constructed and immediately discarded, or rebuilt on every call where a plugin-lifetime cache would do.
- **Unnecessary hooks** — a WordPress action/filter registered that nothing needs, or that duplicates what another already-registered hook covers.
- **Unnecessary abstractions** — an interface, wrapper, or indirection layer with no real payoff (apply §6.6's/§19.1's "realistic second implementation" test). New abstractions are already out of scope per §1's implementation-excellence rules; this is the check that one didn't sneak in anyway.

**If a meaningful improvement is available, make it before committing.** This is not a report filed for later — it's a gate the commit does not pass until nothing meaningful is left to improve. Findings and what was changed as a result belong in that phase's `PHASE-X.md`. If a finding genuinely can't be fixed within this phase (not "didn't get to it" — a real, considered tradeoff), it does not get committed silently either: file a Debt ADR per §10 in the same commit, or fix it. There is no third option.

This step is not optional and is not skipped for any future commit, regardless of how small it seems.

## 8. Architecture Freeze — change control after Phase 3 begins

The architecture was formally frozen in `ARCHITECTURE_FREEZE.md` after two adversarial challenge passes (`ARCHITECTURE-OPTIMIZATION-REVIEW.md` and its predecessor) and before any Phase 3 code was written. Read that file for exactly what's frozen, what's intentionally left flexible, and what's deferred with an explicit trigger — this section is the enforcement rule, not the content.

**After Phase 3 begins, architecture changes are prohibited unless:**

- **a measurable benchmark proves the current design insufficient**,
- **a production issue requires it**, or
- **a new functional requirement makes it necessary**.

"It seemed better" is not one of these. "A different senior architect might have chosen differently" is not one of these — `ARCHITECTURE_FREEZE.md` already documents, decision by decision, that this was anticipated and the choice was made anyway, for stated reasons. Revisiting a frozen decision requires one of the three conditions above to actually be true and demonstrated, not asserted.

**The default during implementation is to keep implementing, not to look for reasons to write an ADR.** An ADR is justified only when implementation *proves* — with an actual benchmark, an actual production incident, or an actual functional requirement that can't be met otherwise — that a frozen decision is objectively insufficient. A frozen decision feeling awkward, verbose, or not how you'd have built it today is not proof of anything and is not grounds to stop and propose a change; finish the implementation as specified.

**Architecture changes after the freeze require, with no exceptions:**

1. **An Architecture Decision Record (ADR)** — use `adr/TEMPLATE.md`, filed as `adr/NNNN-short-title.md` (sequential, zero-padded). Must state which frozen decision is changing, which of the three trigger conditions applies, and include the actual benchmark data / production incident / requirement it's responding to — not a restatement of preference.
2. **A migration plan** — how existing data/code moves to the new design, and in what order, without a period where the system is in an inconsistent state.
3. **A rollback plan** — how to undo the change if it doesn't work, mirroring the same rigor already required of every database migration's `down()`.
4. **An impact analysis** — every phase, plugin, and already-shipped feature the change touches, checked explicitly, the same way every phase's Migration Impact Report already works.

A change without all four is not a valid architecture change, regardless of how small it looks. Log the accepted ADR in `ARCHITECTURE-CHANGELOG.md` the same way every prior architecture change has been logged.

This rule applies starting with Phase 3's first commit. It does not apply retroactively to anything already decided during Phases 0–2 or the two pre-freeze review passes — that work is exactly what's now frozen.

## 9. Benchmark Report — generate after every phase, before committing

**Never assume. Measure.** After a phase's implementation is otherwise complete — code written, Implementation Review (§7) passed, tests green — and before that phase's commit, run `ops/benchmark/run.sh` against the live staging environment and record the results as a new dated section in `BENCHMARKS.md` (append-only, same pattern as `ARCHITECTURE-CHANGELOG.md` — never edit a prior phase's numbers, only add a new section). Compare every metric against the immediately preceding phase's section in the same file.

**If a metric regressed, fix it before committing that phase.** "Regressed" means measurably worse, not just a different number — a 3% timing jitter between runs is noise, not a regression; re-run before concluding either way. If a regression is real and cannot be fixed without touching frozen architecture, that itself may be grounds for an §8 ADR (a measurable benchmark proving the current design insufficient is exactly one of the three permitted triggers) — but exhaust implementation-level fixes first, since most regressions are a bug in the new code, not evidence the architecture is wrong.

Metrics and how each is actually measured (not estimated) in this project's Docker staging environment:

| Metric | How it's measured | Status as of Phase 2 |
|---|---|---|
| PHP memory usage | `memory_get_peak_usage(true)` around a defined benchmark operation, via `wp eval` | Measurable now |
| Execution time | `microtime(true)` before/after the same operation, or wall-clock via `time` around the `wp` invocation | Measurable now |
| SQL query count | `SAVEQUERIES` enabled, `$wpdb->num_queries` read after the operation | Measurable now |
| Cache hits | Redis `INFO stats` → `keyspace_hits`, or the object-cache backend's own hit counter | N/A — no cache layer exists yet (Phase 3) |
| Cache misses | Redis `INFO stats` → `keyspace_misses` | N/A — same as above |
| REST latency | `curl -w "%{time_total}"` against a real endpoint | Measurable now only against core `/wp-json/wp/v2/videos`; `tube/v1` custom endpoints don't exist yet (Phase 5+) |
| Page generation time | `curl -w "%{time_total}"` against a real rendered URL (e.g. `/watch/{slug}/`) | Measurable now against the Phase 1 fallback template; a real theme doesn't exist yet (Phase 8) |
| Import throughput | Items processed per second/minute by the WP-CLI batch worker, timed directly | N/A — import pipeline doesn't exist yet (Phase 5) |
| Event dispatch cost | Microbenchmark: dispatch a real cataloged event through the real `Dispatcher`/`WordPressHookBus` N times, average the total | Measurable now |

A metric marked N/A is reported as N/A in `BENCHMARKS.md`, explicitly, with the reason — never silently omitted, and never filled in with a guess. As each subsystem is built, its row turns from N/A into a real, comparable number starting with that phase's report.

`ops/benchmark/run.sh` lives outside every plugin's own codebase (alongside `ops/cron/` and `ops/docker/`) deliberately — it's operational tooling for measuring the system, not part of any plugin's shipped architecture, so building and extending it doesn't conflict with §1's "no new abstractions/patterns beyond what `ARCHITECTURE.md` specifies" rule for phase implementation.

This step is not optional and is not skipped for any future phase, regardless of how small the phase seems.

## 10. Technical Debt Budget — zero, by default, every phase

**Every phase begins with a debt budget of zero. New technical debt is prohibited by default.** "Technical debt" here means any known, intentional gap between what was implemented and what genuinely production-quality implementation of that same piece of `ARCHITECTURE.md` would look like — a shortcut, a deferred edge case, a "good enough for now" implementation, a correctness gap accepted rather than fixed. This is not about code that's merely simple (simple and correct is the goal, not debt) — it's about anything the person writing it already knows isn't right and is choosing to ship anyway.

The default path is: don't. Fix it before committing, the same as any other finding from the Implementation Review (§7). Most of what looks like a reason to accept debt is actually just unfinished work in the current commit, not a genuine tradeoff — exhaust that possibility first.

**If a phase must genuinely, intentionally introduce debt, it requires a Debt ADR before the phase can be committed — no exceptions, the same "no exceptions" standard as §8's architecture-change ADRs.** Use `adr/DEBT-TEMPLATE.md`, filed as `adr/DEBT-NNNN-short-title.md` (its own sequential numbering, separate from architecture ADRs, so the two kinds of record are never ambiguous from the filename alone). A Debt ADR is a smaller, different instrument than an architecture-change ADR — it isn't proposing to change a frozen decision, it's disclosing a known implementation shortfall within the current, unchanged architecture — so it does not need §8's trigger condition, migration plan, or rollback plan. It must state:

1. **Justification** — why this specific gap is being accepted now, concretely (not "ran out of time" as a bare excuse — what tradeoff was actually being made, against what).
2. **Impact** — what this costs: correctness, performance, security, maintainability — named specifically, not hand-waved as "minor."
3. **Removal plan** — the concrete fix, described precisely enough that a different engineer could execute it without re-deriving the reasoning from scratch.
4. **Target removal phase** — a specific phase number, not "eventually" or "when we have time."

A phase with a filed Debt ADR is committed with that ADR in the same commit — the debt is never left undocumented even for one commit. Log it in `ARCHITECTURE-CHANGELOG.md` the same way architecture changes are logged, tagged as debt so it's distinguishable at a glance from an actual architecture decision.

**Enforcement loop — this is what makes "target removal phase" a real commitment, not a note nobody revisits:** per §1, before a phase's new work starts, check `adr/DEBT-*.md` for any open Debt ADR whose target removal phase is the one about to begin (or earlier — nothing should ever roll past its own target). Paying it off is part of that phase's scope, not optional extra credit. A target removal phase that arrives and doesn't pay off its assigned debt does not get committed as complete until it does, or until a new Debt ADR is filed explaining why the removal is being pushed further out — which should be rare, and is itself something worth surfacing to the user rather than doing silently.

## 11. Session start checklist

Every session, before writing or changing any code:

1. Read `ARCHITECTURE.md` in full (the approved architecture — currently Revision 5, frozen per `ARCHITECTURE_FREEZE.md`).
2. Read this file, `DEVELOPMENT_RULES.md`, in full.
3. Read `ARCHITECTURE_FREEZE.md` to know what's frozen, flexible, and deferred before proposing or implementing anything that touches architecture.
4. Read the most recent `PHASE-X.md` (and any `PHASE-X-AUDIT.md`) to know what's already built and verified, and `BENCHMARKS.md`'s most recent section to know current performance baselines.
5. List `adr/DEBT-*.md` to know what technical debt is currently outstanding and which phase each is targeted for removal in.
6. Run `git log --oneline` and compare against what the phase docs claim — the committed state is the source of truth, not this conversation's memory of it.

Do not rely on conversational memory, session continuity, or any Claude memory feature for project rules or project state. If a rule matters beyond the current message, it belongs in this file or in `ARCHITECTURE.md` — not anywhere else.
