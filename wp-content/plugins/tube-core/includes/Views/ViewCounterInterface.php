<?php
/**
 * Contract for tube-core's Redis-buffered view counter.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Views;

/**
 * Contract for tube-core's Redis-buffered view counter, per
 * ARCHITECTURE.md §12 Phase 4 ("Redis-buffered view recording").
 *
 * Tube-core cannot depend on `tube-cache`'s `CacheInterface` for this —
 * `ARCHITECTURE_FREEZE.md`'s frozen decision #1 is explicit that
 * tube-core is the one plugin with no plugin dependency at all, since
 * every other plugin depends on it, not the reverse. This interface is
 * tube-core's own, narrowly scoped to exactly the two operations view
 * recording needs (record, flush) rather than a general-purpose cache
 * contract — there is no caching concern here, only buffering.
 *
 * Adopted per the interface-justification rule (ARCHITECTURE.md §19.1):
 * `RedisViewCounter` is the real implementation; a test fake is what
 * makes `ViewsFlusher` (and the `views:flush` WP-CLI command it backs)
 * unit-testable without a live Redis connection, the same pattern already
 * proven by `HookBusInterface` and `SchemaVersionRepositoryInterface`.
 */
interface ViewCounterInterface
{
    /**
     * Record one view for a video, buffered — not written to MySQL
     * directly. `ViewsFlusher::flush()`, driven by the `views:flush`
     * WP-CLI job, is what actually persists buffered counts.
     *
     * @param int $video_id The video that was viewed.
     */
    public function record(int $video_id): void;

    /**
     * Atomically take every currently-buffered count and clear the
     * buffer. A view recorded by a concurrent call to record() while
     * flush() is running is never lost — it either lands in this flush's
     * result or is buffered fresh for the next one, never both and never
     * neither.
     *
     * @return array<int, int> Video ID => buffered view count, for every
     *                         video with at least one buffered view.
     *                         Empty if nothing was buffered.
     */
    public function flush(): array;
}
