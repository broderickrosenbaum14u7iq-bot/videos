# Development Rules

This file is the canonical, durable record of every process and quality rule governing implementation of this project. It exists because sessions have no memory of prior conversations — **read this file and ARCHITECTURE.md in full before starting or resuming work on any phase.** Nothing here should ever be treated as "understood" from a prior session; if it isn't written here, it isn't a rule.

---

## 1. Phase discipline

- Implement **exactly one phase at a time**, exactly as defined in `ARCHITECTURE.md` §12 (Implementation Phases). Never skip a phase. Never implement multiple phases together, even partially.
- Finish a phase completely — including its tests, its documentation, and its Architecture Regression Review (§5 below) — before starting the next one.
- **Stop and wait for explicit user approval after every phase.** Do not begin the next phase on your own judgment that the previous one looks done.
- Every phase produces a `PHASE-X.md` (e.g. `PHASE-0.md`, `PHASE-1.md`) documenting what was built and the verification evidence for it — not a summary of intent, actual evidence (command output, live checks).
- If a phase needs a follow-up correction after its initial commit (e.g. an audit), document and commit that separately rather than silently amending history — see `PHASE-1-AUDIT.md` for the precedent.

## 2. Code quality

- **Production quality only.** No shortcuts, no `TODO` placeholders, no temporary or stub implementations, no "fill this in later."
- Every database table is created via a migration. Every migration must be **genuinely reversible** — a `down()` that exactly undoes its `up()` — and this must be verified live (apply, roll back, re-apply, inspect the actual schema/data), not just reviewed by reading the code.
- Every public function/method is fully documented (complete docblocks — description, `@param`, `@return`, `@throws` where relevant).
- Follow **WordPress Coding Standards (WPCS) + PSR-12** together. The two rulesets conflict in several well-identified places (tabs vs. spaces, padded vs. unpadded parens, camelCase vs. snake_case method names, several PHPCSExtra "Universal" sniffs); every such conflict and its resolution is documented with reasoning directly in `phpcs.xml` — read it, don't re-derive these from scratch.
- Every plugin must be **independently testable**: its own `composer.json`, its own PSR-4 autoloading, no plugin depending on another plugin's `composer.json`. Anything WordPress- or database-coupled that has meaningful logic worth testing gets that logic isolated behind a small interface (e.g. `SchemaVersionRepositoryInterface`, `HookBusInterface`) so it can be unit-tested with a fake, instead of requiring a full WordPress bootstrap.
- **PHP 8.3 only.** `declare(strict_types=1)` as the first statement in every PHP file. Full type declarations everywhere.
- **Every change must pass `phpcs` (exit 0) and `phpunit` (all green) before it is committed.** Not "mostly passes" — zero errors, zero warnings, unless a warning is a deliberate, documented, justified exception.
- **Keep backward compatibility with every previous phase.** After each phase's changes, verify live (reactivate the plugin in staging, re-run the previous phase's own checks) that nothing earlier broke — don't just assume additive changes are safe.

## 3. Environment

- **Never modify production** (`root@139.99.96.155:/www/wwwroot/phimtoico.org`). All implementation work happens only in the local Docker staging environment (`docker-compose.yml`, see `PHASE-0.md` for how to bring it up).
- **WP-Cron is never used.** Every background/scheduled task runs via Linux cron invoking WP-CLI directly (`ops/cron/staging.cron`), per `ARCHITECTURE.md` §7. `DISABLE_WP_CRON` is set to `true`.

## 4. Commit discipline

- Commit only when a phase (or an explicitly-scoped follow-up like an audit) is genuinely complete — no partial/WIP commits.
- Write commit messages that explain *why*, not just *what* — a future session with no memory of this conversation should be able to understand the reasoning from `git log` alone.
- Never force-push, never amend a commit that's already been discussed as complete with the user.

## 5. Architecture Regression Review — run before every phase's commit

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

## 6. Session start checklist

Every session, before writing or changing any code:

1. Read `ARCHITECTURE.md` in full (the approved architecture — currently Revision 4, Final).
2. Read this file, `DEVELOPMENT_RULES.md`, in full.
3. Read the most recent `PHASE-X.md` (and any `PHASE-X-AUDIT.md`) to know what's already built and verified.
4. Run `git log --oneline` and compare against what the phase docs claim — the committed state is the source of truth, not this conversation's memory of it.

Do not rely on conversational memory, session continuity, or any Claude memory feature for project rules or project state. If a rule matters beyond the current message, it belongs in this file or in `ARCHITECTURE.md` — not anywhere else.
