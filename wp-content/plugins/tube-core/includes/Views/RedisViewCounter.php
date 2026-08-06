<?php
/**
 * Redis-backed ViewCounterInterface implementation.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Views;

use Predis\ClientInterface;
use Predis\PredisException;
use Predis\Response\ServerException;

/**
 * Redis-backed ViewCounterInterface implementation.
 *
 * Buffers view counts in a single Redis HASH (field = video ID, value =
 * pending count), keyed under its own `tube_core:` namespace — a
 * separate Redis connection and key namespace from `tube-cache`'s
 * `tube_cache:`-prefixed keys, per this interface's own docblock on why
 * tube-core cannot depend on tube-cache.
 *
 * `flush()` takes everything currently buffered via an atomic `RENAME`
 * to a uniquely-named key (so two overlapping `views:flush` runs, e.g. a
 * slow flush still running when the next minute's cron fires, can never
 * collide on the same intermediate key), then reads and deletes that
 * renamed copy. A view recorded concurrently with a flush either lands
 * in that flush's result (if `HINCRBY` beat the `RENAME`) or starts a
 * fresh buffer for the next flush (if it lands after) — never both, never
 * lost.
 *
 * `record()` degrades to a logged no-op on any Redis failure (catches
 * `Predis\PredisException`, the base both a connection failure and a
 * server-side error like Redis's own memory-pressure `OOM` response
 * extend — widened in Phase 11 for the same reason and alongside
 * `Tube_Cache\Cache\RedisCache`'s own widening, see that class's
 * docblock) — the same fail-open principle `RedisCache` applies, since
 * it runs on the live page-render path and one missed view count must
 * never be able to break rendering a video page. `flush()` deliberately
 * does not degrade broadly — it runs only from the `views:flush` WP-CLI
 * cron job, where a Redis failure should surface as a failed job (per
 * ARCHITECTURE.md §18.5's cron-job-exit-code monitoring), not be
 * silently swallowed; it catches only the one specific, expected
 * `ServerException` race documented below, nothing broader.
 *
 * One narrow race `flush()` does handle: busybox cron (this project's
 * staging cron, ARCHITECTURE.md §7) does not skip a run if the previous
 * one is still in progress, so two `views:flush` invocations can
 * genuinely overlap under load. If the first finishes its `RENAME`
 * between the second's `EXISTS` check and its own `RENAME`, the second's
 * `RENAME` fails with a "no such key" server error — treated the same as
 * `EXISTS` having returned false (nothing to flush this run), not as a
 * failure, since that's exactly what actually happened.
 */
final class RedisViewCounter implements ViewCounterInterface
{
    /**
     * The Redis hash every buffered view count lives in until flushed.
     */
    private const BUFFER_KEY = 'tube_core:view_buffer';

    /**
     * Construct around the Predis client used for every command.
     *
     * @param ClientInterface $client The Predis client to issue commands against.
     */
    public function __construct(private readonly ClientInterface $client)
    {
    }

    /**
     * {@inheritDoc}
     *
     * @param int $video_id The video that was viewed.
     */
    public function record(int $video_id): void
    {
        try {
            $this->client->hincrby(self::BUFFER_KEY, (string) $video_id, 1);
        } catch (PredisException $exception) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate production logging for a degraded-but-handled Redis failure, mirroring Tube_Cache\Cache\RedisCache's documented fail-open behavior.
            error_log('[tube-core] Redis view record failed, degrading gracefully: ' . $exception->getMessage());
        }
    }

    /**
     * {@inheritDoc}
     *
     * @return array<int, int> Video ID => buffered view count.
     */
    public function flush(): array
    {
        if (0 === $this->client->exists(self::BUFFER_KEY)) {
            return [];
        }

        $flushing_key = self::BUFFER_KEY . ':flushing:' . uniqid('', true);

        try {
            $this->client->rename(self::BUFFER_KEY, $flushing_key);
        } catch (ServerException) {
            // See this class's docblock: an overlapping flush already
            // renamed (and is processing) the buffer — nothing left for
            // this run.
            return [];
        }

        /** @var array<string, string> $raw */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
        $raw = $this->client->hgetall($flushing_key);

        $this->client->del([$flushing_key]);

        $result = [];

        foreach ($raw as $video_id => $count) {
            $result[ (int) $video_id ] = (int) $count;
        }

        return $result;
    }
}
