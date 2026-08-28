# Architecture Freeze

Status: **Frozen**, effective on commit. This is a certification exercise, not another improvement pass — its job is to demonstrate the architecture is stable enough to stop changing, not to find more things to fix. Two adversarial challenge passes already happened (`ARCHITECTURE-OPTIMIZATION-REVIEW.md` and the review that preceded it); nothing new is proposed here. Where this review found something genuinely worth flagging, it's recorded as a known tradeoff or a deferred decision below, not as a reason to reopen design work.

From this point, `DEVELOPMENT_RULES.md`'s change-control rule applies: architecture changes require a measurable benchmark, a production issue, or a new functional requirement — plus an ADR, migration plan, rollback plan, and impact analysis. No exceptions.

---

## Per-subsystem review

Columns 1–7 map to the review's first seven questions (stable for 12 months / expensive to change later / simplest solution for the scale target / any abstraction still unnecessary / any coupling still hidden / any optimization premature / any optimization missing). Question 8 (could a reasonable peer disagree) is answered in prose below the table wherever the answer is yes — most subsystems have a defensible alternative a competent architect could have chosen instead, and pretending otherwise would be dishonest. Questions 9–10 are the Frozen/Flexible sections further down.

| Subsystem | Stable 12mo? | Expensive later? | Simplest for scale? | Unneeded abstraction? | Hidden coupling? | Premature opt? | Missing opt? |
|---|---|---|---|---|---|---|---|
| `tube-core` | Yes | Yes (everything depends on it) | Yes | No | No | No | None found |
| `tube-cache` (Phase 3) | Yes (design) | Moderate | Yes | No | N/A — not built | No | None found |
| `tube-search` (Phase 7) | Yes (design) | Low–moderate | Yes | No | N/A — not built | No | None found |
| `tube-player` (Phase 6) | Yes (design) | Moderate | Yes | No | N/A — not built | No | None found |
| `tube-seo` (Phase 9) | Yes (design) | Low | Yes | No | N/A — not built | No | None found |
| `tube-admin` (Phase 10) | Yes (design) | Low | Yes | No | N/A — not built | No | None found |
| Theme (Phase 8) | Yes (design) | Low | Yes | No | N/A — not built | No | None found |
| Migration framework | Yes | High | Yes | No | No | No | None found |
| Event system | Yes | High | Yes | No | No | No | None found |
| Repositories (convention) | Yes | Low (by design) | Yes | No | N/A — one example built | No | None found |
| REST API boundaries | Yes (design) | Moderate | Yes | No | No | No | None found |
| Import pipeline | Yes (design) | Moderate | Yes | No | N/A — not built | No | None found |
| Cache (cross-cutting) | Yes (design) | Moderate | Yes | No | N/A — not built | No | None found |
| Search (cross-cutting) | Yes (design) | Low–moderate | Yes | No | N/A — not built | No | None found |
| Database design | Yes | High | Yes | No | No | No | None found |
| Cloudflare integration | Yes (design) | Moderate–high | Yes | No | N/A — not built | No | None found |

"Expensive later" is relative, not absolute — see Known Tradeoffs for what actually drives the cost in each high/moderate case.

### Where a reasonable peer could disagree (Question 8)

- **`tube-core` owning all data tables.** A peer could split actor/studio (and later, views/stats/import/watch-history) into their own plugin(s) rather than concentrating all schema in the foundation plugin. Chosen anyway because: it avoids cross-plugin data-ownership disputes, matches the common "core + satellite" shape large WordPress plugin suites already use, and the actual risk to watch (`tube-core` absorbing presentation or query-building logic that belongs to a satellite) is independent of how many tables it owns.
- **Import pipeline as a DB-table queue, not a message broker.** A peer building for "500,000+ items" might reflexively reach for Redis Streams or RabbitMQ. Chosen anyway because: the workload is bursty/batch (a bulk load, then periodic trickle), not continuous/real-time — the profile a broker is built for — and a DB-table queue needs no new infrastructure to operate or monitor. Reconsider only if the import cadence changes from batch to genuinely continuous.
- **Search: MySQL FULLTEXT first, not Elasticsearch/OpenSearch from day one.** A peer building "search" for a large catalog might default to a dedicated search engine immediately. Chosen anyway because: there is no real query-pattern or relevance-ranking data yet to justify standing up and operating a search cluster, and the cost of being wrong is bounded — an interface decision is explicitly left open for Phase 7 to make once there's a concrete case either way.
- **No generic service container.** A peer coming from a Symfony/Laravel background might reach for one reflexively as a matter of habit. Chosen anyway because: the actual number of services per plugin (single digits) is far below where a container earns its cost, and it would partially reintroduce the service-locator pattern this project explicitly avoids. A concrete reconsideration trigger is written down rather than left to habit.
- **Hand-rolled SEO instead of Yoast/RankMath.** A peer would reasonably ask why not use a mature, widely-used plugin. Chosen anyway because: this project's rebuild was motivated in part by the security risk of third-party/nulled theme and plugin code found in the original site audit, and this project needs precise control over `VideoObject` schema and Core Web Vitals that a general-purpose SEO plugin doesn't specifically optimize for.
- **Cloudflare concentration (Stream + Images + CDN, one vendor for three roles).** A peer would flag vendor lock-in risk for a system expected to run for years. Acknowledged, not dismissed — see Known Tradeoffs. Mitigated, not eliminated, by `VideoProviderInterface` on the video side; the Images/CDN roles do not have the same interface treatment yet (see Deferred Decisions).

---

## Frozen decisions

Changing any of these after this point requires the full post-freeze process (ADR, migration plan, rollback plan, impact analysis) in `DEVELOPMENT_RULES.md`.

1. Six independent plugins (`tube-core`, `tube-cache`, `tube-search`, `tube-player`, `tube-seo`, `tube-admin`) plus a presentation-only theme — no plugin depends on another's internals or database tables directly; only `tube-core` has no plugin dependency.
2. `video` is a Custom Post Type, never the native `post`.
3. `actor` and `studio` are dedicated tables, never taxonomies.
4. `video_category` and `video_tag` remain native WordPress taxonomies.
5. No video/image bytes are ever stored on the WordPress server; only Cloudflare Stream/Images identifiers are persisted, never playback URLs. **Partially reversed by ADR-0001 (2026-08-24), further revised by its 2026-08-25 addendum**: the image half of this item — the poster/OG-image is now sourced exclusively from the WordPress Media Library (a WordPress attachment ID, not a Cloudflare Images ID); there is no Cloudflare Stream thumbnail-extraction default/fallback anymore for a video with no attachment set. The video half (no video bytes ever on the WordPress server, playback still constructed from a stored Stream UID at render time) is unchanged. See `adr/0001-media-library-poster-images.md`.
6. `wp_postmeta` is never used for video data; `wp_tube_video_metadata` and every other dedicated table are the only stores.
7. Every schema change is a migration with a genuinely reversible `down()`; migrations self-register per plugin rather than being filesystem-discovered.
8. WP-Cron is never used; every scheduled task runs via Linux cron invoking WP-CLI.
9. The event system (`Dispatcher`/`EventCatalog`/`HookBusInterface`) is the only sanctioned cross-plugin reaction mechanism; event handlers stay cheap and synchronous, expensive work goes to cron.
10. The import pipeline is a DB-table queue (`wp_tube_import_queue`) processed by a WP-CLI batch worker, not a message broker.
11. Search starts on MySQL FULLTEXT; no search-engine infrastructure is stood up without a concrete, data-backed case.
12. No generic service container; dependencies are constructor-injected or obtained via a plugin's own typed bootstrap accessors.
13. An interface is created only when it has a realistic second implementation (a real competing implementation or a genuine test fake) — never for hypothetical vendor-swap flexibility alone.
14. `/tube/v1` REST namespace is additive-only; any breaking change requires `/tube/v2` served alongside it through a deprecation window, never an in-place breaking change.
15. Bulk writes to relationship tables use one multi-row `INSERT`, never a loop of single-row inserts.
16. PHP 8.3 only, `declare(strict_types=1)` everywhere, PSR-12 + WPCS per the reconciliation already documented in `phpcs.xml`.
17. Production is never modified directly; all implementation happens in the local Docker staging environment.

## Flexible decisions

Left open deliberately — deciding these now would be speculating ahead of the phase that actually needs the answer, which the freeze process exists to prevent, not to force.

1. Whether `tube-search`'s query layer sits behind an interface — Phase 7's call, under the frozen interface-justification rule (#13 above).
2. Exact repository method names/shapes for tables not yet consumed by any code (`wp_tube_video_metadata`, `wp_tube_actors`, `wp_tube_studios`) — follow the frozen `{Noun}Repository` convention, but the specific public API is decided when a real consumer exists.
3. Whether `tube-cache` and `tube-player`'s test fakes cover every method from their first commit or grow incrementally — the interfaces themselves are frozen (#13's justification already applies), their exact surface area is not.
4. Cron cadences in `ops/cron/staging.cron` — tunable based on real operational experience once there's real traffic to observe.
5. Internal method/class organization within a plugin's `includes/` tree, as long as plugin boundaries (#1) and the repository convention are respected.
6. Whether `Plugin.php`'s in-class memoization gets a small private helper or stays as individually hand-written accessors — either is fine below the 6–8-accessor trigger already documented.

## Deferred decisions

Not decided now; each has an explicit trigger, not an open-ended "someday."

| Decision | Trigger |
|---|---|
| Read/write MySQL replica routing | "Once traffic requires it" (`ARCHITECTURE.md` §10) — no benchmark yet exists to define this precisely; the first ADR proposing it must include one. |
| `SchemaVersionStore`/`AbstractMigration` `db()` consolidation | Implemented on the next commit that touches a repository — approved, not yet coded. |
| Full `WP_UnitTestCase` integration-test suite | Before Phase 5 (import pipeline) or Phase 6 (`tube-player`), whichever comes first. |
| Elasticsearch/OpenSearch for search | Only if MySQL FULLTEXT is measured as inadequate against real query patterns — no assumption either way stands in for that measurement. |
| Image/CDN provider abstraction (beyond `VideoProviderInterface`) | Only if a concrete testability or vendor-risk need materializes when `tube-player`/`tube-cache` are built — not created speculatively now. |
| `Plugin.php` per-plugin container/memoization helper | Only once a bootstrap class exceeds ~6–8 accessor methods or starts containing real logic. |

## Explicit non-goals

Stated so a future contributor doesn't mistake silence for an oversight.

- **Not building literal microservices.** "Future microservice compatibility" means clean boundaries enabling easier extraction later, not a mandate to build network/service boundaries now (`ARCHITECTURE.md` §19.7).
- **Not self-hosting video or image transcoding/storage**, ever, at any phase, regardless of Cloudflare cost changes, unless a new functional requirement makes it necessary (the freeze's own exception clause) — this is a considered, not a default, position.
- **Not building a generic service container or dependency-injection framework.**
- **Not adopting a general-purpose message broker** for import or any other current workload.
- **Not depending on a third-party SEO, movie/tube, or premium theme/plugin** anywhere in this codebase.
- **Not supporting PHP versions below 8.3** or WordPress versions below 6.5.
- **Not building admin or public features not named in `ARCHITECTURE.md`'s phases** — no speculative feature work ahead of its phase.

## Known tradeoffs

Accepted, not hidden.

- **Cloudflare vendor concentration** (Stream, Images, CDN all from one vendor) is a real, multi-year lock-in risk. Mitigated on the video path by `VideoProviderInterface`; not mitigated on the Images/CDN paths, which remain a deferred decision, not a frozen abstraction, because no concrete second-implementation case exists for them yet.
- **DB-table import queue** trades away broker-grade throughput/durability guarantees for zero new infrastructure — correct for a batch workload, would need real reconsideration (not just tuning) if the import pattern ever became continuous/real-time.
- **MySQL FULLTEXT first** trades away out-of-the-box relevance ranking and fuzzy matching for zero new infrastructure and zero premature investment — the interface seam exists specifically to make the eventual cost of being wrong bounded, not zero.
- **`tube-core`'s data concentration** trades some single-plugin size for avoiding cross-plugin schema-ownership disputes — bounded by the "no presentation/query logic in `tube-core`" rule, which is the thing that actually needs enforcing, not the table count itself.
- **No full WordPress integration test suite yet** trades comprehensive automated coverage of WordPress-hook-wired code for faster iteration through Phases 0–2, with a hard checkpoint (not an open promise) before it becomes riskier.

## Performance assumptions

Stated explicitly so a future contributor can tell when reality has diverged from what the architecture assumed, rather than discovering it by surprise.

- MySQL FULLTEXT is assumed adequate through roughly the 500k-video mark under **moderate** relevance-ranking requirements (no fuzzy/typo-tolerant matching, no complex multi-field weighted ranking). If real usage demands materially more than that, the assumption has broken, not just degraded, and the search deferred-decision trigger applies.
- Redis is assumed to comfortably handle view-counter buffering, object cache, and rate-limiting simultaneously at the pageview volumes in `ARCHITECTURE.md`'s stated target (millions/month). This assumes Redis memory sizing is operationally managed as traffic grows — not something this architecture automates.
- The DB-table import queue is assumed adequate for batch imports up to and including the full 500k-video initial catalog load, processed over hours/days via WP-CLI, not required to complete in minutes.
- Event dispatch overhead (a 9-item `in_array` check plus one `do_action` call per event) is assumed negligible against WordPress core's own unconditional `do_action('save_post', ...)` overhead, which already happens on every save regardless of this project's code.

## Scalability assumptions

- A single MySQL primary is assumed sufficient until read replica routing's own deferred trigger fires — this architecture does not assume replicas are needed at 500k videos by default, only that the seam to add them exists cheaply when they are.
- The application/PHP layer is assumed horizontally scalable as designed (no server-local state) without further architectural change — confirmed, not merely assumed, by the absence of PHP sessions, in-process-only caching, or local file writes anywhere in the current design.
- Cloudflare (Stream, Images, CDN) is assumed to absorb effectively all media-serving load regardless of catalog size — the WordPress origin server is assumed to never serve video/image bytes directly at any scale this project targets.

## Future migration paths

For every deferred/flexible item above, the concrete path if/when its trigger fires:

- **Read replicas**: implemented at the single consolidated `db()` accessor (once built); repositories built afterward don't need to change, since they already go through that seam rather than `global $wpdb` directly.
- **Search backend swap**: implemented behind whatever interface Phase 7 adds (if it adds one) or, if Phase 7 chose not to add one and a swap later becomes necessary, that swap itself is the first legitimate case for adding the interface — either path is bounded, not open-ended.
- **Video/image provider swap**: implement a new class against `VideoProviderInterface` (video) or add the not-yet-built equivalent for images if that deferred decision is ever triggered; no caller changes required for the video path, by design.
- **Import pipeline swap to a broker**: the queue table's row shape (source, payload, status, attempts) maps reasonably onto a broker message if this is ever needed; the WP-CLI batch worker is the piece that would be replaced, not the table's conceptual role.
- **`tube-core` data-ownership split**: if ever needed, the migration path is moving specific tables' migrations and repositories to a new satellite plugin — feasible specifically because `tube-core`'s tables were never queried directly by other plugins, only through documented APIs and events.

---

This document is the record of Question 9 (frozen) and Question 10 (flexible) for every subsystem reviewed. Nothing here proposes a change — see `ARCHITECTURE-OPTIMIZATION-REVIEW.md` and `ARCHITECTURE-CHANGELOG.md` for the changes that were made before this freeze took effect.
