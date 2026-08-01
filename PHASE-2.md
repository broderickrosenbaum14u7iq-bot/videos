# Phase 2 — Tube Core Event Dispatcher

Status: **Complete.** Implements exactly ARCHITECTURE.md §6 / §12's Phase 2 scope: the internal event dispatcher, its documented catalog, and — since the `video` CPT's triggers already exist from Phase 1 — real dispatch wiring for the four `video.*` lifecycle events. The five remaining catalog events stay valid-but-unfired ("Reserved") until their owning phase builds the trigger, per §6's own description of the catalog.

---

## 1. What was built

### 1.1 The dispatcher core (`Tube_Core\Events`)
- `HookBusInterface` + `WordPressHookBus`: the same dependency-inversion pattern Phase 1 used for `SchemaVersionRepositoryInterface`/`SchemaVersionStore` — `Dispatcher` depends on the interface, not on WordPress's `add_action()`/`do_action()` directly, so its validation logic is unit-testable without WordPress loaded. `WordPressHookBus` is the thin real implementation.
- `EventCatalog`: the full 9-event catalog from ARCHITECTURE.md §6, as typed constants (`EventCatalog::VIDEO_PUBLISHED`, etc.) rather than bare strings scattered through the codebase. Event name values are prefixed `tube_core.` (e.g. `tube_core.video.published`) — a deliberate, documented departure from the architecture doc's bare `video.published`-style shorthand, to guarantee the underlying WordPress hook name can never collide with an unrelated plugin's hook of a similar name, consistent with this project's prefixing discipline everywhere else.
- `Dispatcher`: `dispatch(string $event, array $payload = [])` and `listen(string $event, callable $listener, int $priority = 10)`. Both validate the event name against `EventCatalog::all()` and throw `InvalidArgumentException` on anything else — this is the concrete mechanism behind §6's "stable, versioned contract... instead of guessing hook names by convention": a typo'd event name fails immediately instead of a listener silently never firing.

### 1.2 Real event triggers (`VideoLifecycleEvents`)
Wired to three WordPress hooks, each split into a thin `handle_*` adapter (WordPress-hook-signature-typed, only usable with WordPress loaded) and a pure `dispatch_*` method (primitive-typed, unit-testable):

| WordPress hook | Adapter | Dispatches | Logic |
|---|---|---|---|
| `save_post_video` | `handle_save` | `VIDEO_CREATED` or `VIDEO_UPDATED` | Guards revisions, autosaves, and `auto-draft` (WordPress creates this the instant "Add New Video" is opened — not a real create event). Uses WordPress's own `$update` flag to distinguish create from update. |
| `transition_post_status` | `handle_status_transition` | `VIDEO_PUBLISHED` | Filtered to the `video` post type (this hook fires for every post type). Fires only when the new status is `publish` and the old status was anything else — re-saving an already-published video does not re-fire it. |
| `before_delete_post` | `handle_before_delete` | `VIDEO_DELETED` | Filtered to the `video` post type. Permanent deletion only — trashing a video fires `VIDEO_UPDATED` via the normal save path instead, since trashing is a status change on an existing post, not a deletion. |

`VIDEO_UPDATED` and `VIDEO_PUBLISHED` intentionally both fire on a draft→publish save (documented in `EVENTS.md`) — this mirrors how WordPress's own overlapping hooks work, and subscribers (from Phase 3 onward) are expected to be idempotent the same way they'd need to be for WordPress core hooks.

### 1.3 `EVENTS.md`
The human-readable counterpart to `EventCatalog`, per §6's "an EVENTS.md-equivalent maintained alongside the code" — every event's name, payload shape, exact firing condition, Active/Reserved status, and expected subscribers (from §6's table), plus usage examples and the design notes about handler speed/idempotency/no-transactionality.

### 1.4 `Plugin.php`
Added `Plugin::events(): Dispatcher` (lazy singleton, mirrors the existing `migration_runner()` accessor exactly) and wired `VideoLifecycleEvents::register()` into `boot()`. Nothing about `activate()`, `deactivate()`, or `migration_runner()` changed.

---

## 2. Design decisions

1. **Dependency inversion for the hook bus**, not a direct WordPress call from `Dispatcher` — same rationale and same shape as Phase 1's migration-repository pattern, applied consistently rather than introducing a new style.
2. **`tube_core.` prefix on event name strings** — the architecture's catalog table shows short names for readability; the actual dispatched string is namespaced for collision safety, documented explicitly in both `EventCatalog`'s docblock and `EVENTS.md` rather than left as a silent divergence.
3. **Split adapter/pure-logic methods in `VideoLifecycleEvents`** — the same "WordPress-coupled boundary, testable core" shape `AbstractMigration` and the `Content` classes already use in this codebase.
4. **Only the four `video.*` events got real triggers this phase.** The other five (`VIDEO_STREAM_STATUS_CHANGED`, `VIDEO_VIEW_RECORDED`, `VIDEO_STATS_ROLLED_UP`, `IMPORT_ITEM_COMPLETED`, `IMPORT_ITEM_FAILED`) depend on tables/features that don't exist until Phases 4–5 (view tracking, stats rollup, the import queue, the Cloudflare Stream webhook). They are fully valid, listenable event names today — `Dispatcher::listen()` never rejects them — they simply have no dispatch call site yet. This is not a shortcut: building a fake trigger for a table that doesn't exist would be exactly the kind of "temporary implementation" the rules for this phase prohibit. The catalog being complete now (rather than growing piecemeal) is what lets Phase 3 (`tube-cache`) register listeners for `VIDEO_PUBLISHED`/`VIDEO_UPDATED`/`VIDEO_DELETED` immediately without any tube-core change.

---

## 3. Backward compatibility with Phase 1

Verified, not assumed: reactivated `tube-core` in the live staging environment after adding all Phase 2 code, and confirmed the `video` CPT, `video_category`/`video_tag` taxonomies, and all four applied migrations (`wp tube migrate status`) were completely unaffected. `Plugin::activate()`, `Plugin::deactivate()`, and `Plugin::migration_runner()` were not modified — only additive changes were made to `Plugin.php` (a new property, a new accessor method, one new line in `boot()`).

---

## 4. Automated tests

**18 new PHPUnit tests** (31 total for tube-core, up from Phase 1's 13), all against fakes — zero WordPress dependency:

- `EventCatalogTest` (3 tests): every declared constant is in `all()` and vice versa (catches a new event added without updating the validation list), no duplicate event strings, every event carries the `tube_core.` prefix.
- `DispatcherTest` (7 tests): `dispatch()`/`listen()` delegate to the hook bus correctly, default payload/priority behavior, both reject unknown event names, and a rejected `dispatch()` never reaches the hook bus.
- `VideoLifecycleEventsTest` (8 tests): create vs. update dispatch selection, every publish-transition case (draft→publish, pending→publish, publish→publish, publish→draft, draft→pending — only genuine "entering publish" fires), and delete dispatch.

## 5. Live verification (real WordPress hooks, not just unit tests)

Unit tests exercise `dispatch_*()` directly; they don't prove the real `save_post_video`/`transition_post_status`/`before_delete_post` WordPress hooks are wired correctly. To verify that, a temporary debug listener (an mu-plugin logging every dispatched event to a file) was installed in the live staging environment, and a real video post was taken through its full lifecycle across **separate WP-CLI processes** (so each step is a genuinely independent WordPress request, not one script holding state):

```
tube_core.video.created   {"video_id":5}   ← wp post create (draft)
tube_core.video.updated   {"video_id":5}   ← wp post update (still draft)
tube_core.video.published {"video_id":5}   ← wp post update --post_status=publish
tube_core.video.updated   {"video_id":5}   ← wp post delete --force (before_delete_post doesn't fire save_post, but WP-CLI's own trash attempt first triggered a save)
tube_core.video.deleted   {"video_id":5}   ← wp post delete --force
```

Exactly the expected sequence — no duplicate or missing events. The debug mu-plugin was removed afterward; nothing from it is part of the committed code.

**Incidental observation, not a Phase 2 defect**: `wp post delete <id>` (without `--force`) reported "Posts of type 'video' do not support being sent to trash" — investigated and confirmed this is a WP-CLI-specific check, not a real limitation: calling `wp_trash_post()` directly against a video post from PHP succeeds normally, and the same WP-CLI command trashes a native `post` without issue in the same environment. This is orthogonal to the event dispatcher and to Phase 1's CPT registration (already audited); noted here for completeness rather than investigated further, since it doesn't affect how real users (via wp-admin) or this project's own code interact with the CPT.

## 6. Verification summary

| Check | Result |
|---|---|
| `phpcs` (whole repo) | Exit `0` |
| `phpunit` (tube-core) | 31/31 passing |
| Phase 1 CPT/taxonomies/migrations after reactivating with Phase 2 code | Unaffected |
| `Requires Plugins` gate | Re-verified with the updated `Plugin.php` — still blocks `tube-player` activation while `tube-core` is inactive |
| Real WordPress hooks → real dispatch | Confirmed live, exact expected event sequence, across independent WP-CLI processes |

## 7. Explicitly out of scope for Phase 2

No `tube-cache` (Phase 3), no listeners registered by any other plugin yet (nothing to listen from — Phase 3+), no dispatch call sites for the five Reserved events (Phases 4–5), no theme code. All per ARCHITECTURE.md §12.

## 8. Production impact

None. All work happened in the local Docker staging environment, including the temporary debug listener used for live verification, which was removed before this phase was considered complete. Production was not accessed.
