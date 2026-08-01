# Tube Core — Event Catalog

This is the human-readable counterpart to `Tube_Core\Events\EventCatalog` — the same stable, documented contract described in ARCHITECTURE.md §6, kept alongside the code rather than only in the architecture document. Every event a plugin dispatches or listens for through `Tube_Core\Events\Dispatcher` must be one of the names below; `Dispatcher` enforces this at runtime and throws on anything else.

Event name strings are prefixed `tube_core.` (e.g. the constant `EventCatalog::VIDEO_PUBLISHED` has the value `tube_core.video.published`) so the underlying WordPress hook can never collide with an unrelated plugin's own hook of a similar name — the short `video.published`-style names in ARCHITECTURE.md's catalog table are the conceptual name; the prefixed string is the literal one used in code.

## Status legend

- **Active** — a real WordPress hook is wired to dispatch this event today.
- **Reserved** — the name is valid and listenable today (registering a listener never errors), but nothing dispatches it yet. It depends on a trigger that doesn't exist until a later phase.

## Events

| Constant | Event name | Status | Fires when | Payload | Typical subscribers (from ARCHITECTURE.md §6) |
|---|---|---|---|---|---|
| `VIDEO_CREATED` | `tube_core.video.created` | **Active** (Phase 2) | A new `video` post is first inserted (not `auto-draft`, not a revision/autosave). | `['video_id' => int]` | tube-search (index insert) |
| `VIDEO_UPDATED` | `tube_core.video.updated` | **Active** (Phase 2) | An existing `video` post is saved — including a trash/untrash, since that's a status change on an existing post. | `['video_id' => int]` | tube-search (index update), tube-cache (purge) |
| `VIDEO_PUBLISHED` | `tube_core.video.published` | **Active** (Phase 2) | A video transitions to `publish` from any other status. Fires in addition to `VIDEO_UPDATED` on the same save — subscribers to both should be idempotent, the same way WordPress's own overlapping hooks work. | `['video_id' => int]` | tube-search (index update), tube-cache (purge), tube-seo (sitemap flag) |
| `VIDEO_DELETED` | `tube_core.video.deleted` | **Active** (Phase 2) | Immediately before a video post is **permanently** deleted. Trashing alone fires `VIDEO_UPDATED`, not this. | `['video_id' => int]` | tube-search (index delete), tube-cache (purge) |
| `VIDEO_STREAM_STATUS_CHANGED` | `tube_core.video.stream_status_changed` | Reserved (Phase 5) | The Cloudflare Stream webhook handler receives an encoding status update. | `['video_id' => int, 'status' => string]` | tube-admin (dashboard update) |
| `VIDEO_VIEW_RECORDED` | `tube_core.video.view_recorded` | Reserved (Phase 4) | A view is recorded. Internal only per §6 — the stats rollup job reads `wp_tube_video_views` directly rather than subscribing. | `['video_id' => int]` | (none — internal) |
| `VIDEO_STATS_ROLLED_UP` | `tube_core.video.stats_rolled_up` | Reserved (Phase 4) | The end of the `stats:rollup` WP-CLI job, per video updated. | `['video_id' => int, 'views_total' => int]` | tube-search (refresh `views_total` in the index) |
| `IMPORT_ITEM_COMPLETED` | `tube_core.import.item_completed` | Reserved (Phase 5) | An `import_queue` item finishes successfully. | `['queue_id' => int, 'video_id' => int]` | tube-admin (dashboard), tube-search (index insert) |
| `IMPORT_ITEM_FAILED` | `tube_core.import.item_failed` | Reserved (Phase 5) | An `import_queue` item exhausts its retry attempts. | `['queue_id' => int, 'error_message' => string]` | tube-admin (alert/dashboard) |

## Using the dispatcher

```php
$events = \Tube_Core\Plugin::instance()->events();

// Listening (any tube-* plugin, once it exists):
$events->listen(\Tube_Core\Events\EventCatalog::VIDEO_PUBLISHED, function (array $payload): void {
    $video_id = $payload['video_id'];
    // ...
});

// Dispatching (tube-core only, today — see "Active" above):
$events->dispatch(\Tube_Core\Events\EventCatalog::VIDEO_PUBLISHED, ['video_id' => $video_id]);
```

Both `dispatch()` and `listen()` throw `InvalidArgumentException` for any event name not in this catalog — add the constant here and in `EventCatalog` first, in the same change, before dispatching or listening for a new event.

## Design notes

- **Handlers should stay fast.** The dispatcher is for decoupling *what* reacts to *what* — a listener that needs to do real work (a full search-index rebuild, a bulk cache purge across thousands of pages) should queue that work for the Linux-cron/WP-CLI background job system (ARCHITECTURE.md §7), not do it synchronously inside the event handler on the request that triggered it.
- **Payloads are intentionally thin** — just IDs, not full objects. A listener that needs more than a video's ID fetches it itself. This avoids coupling every listener to a specific object shape and avoids staleness if a payload were ever cached or queued.
- **No transactionality.** Dispatching is a direct WordPress action call — if one listener throws, later listeners for the same event still run (WordPress's normal `do_action()` behavior), but nothing here rolls back a partially-applied side effect. Listeners are expected to be idempotent, the same assumption `VIDEO_UPDATED`/`VIDEO_PUBLISHED` co-firing already relies on.
