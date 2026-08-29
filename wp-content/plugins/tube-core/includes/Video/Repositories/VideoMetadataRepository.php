<?php
/**
 * Data access for wp_tube_video_metadata (VideoMetadataRepositoryInterface).
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Video\Repositories;

use RuntimeException;
use Tube_Core\Video\CfStreamStatus;
use Tube_Core\Video\VideoMetadata;
use Tube_Core\Video\VideoSource;

/**
 * Data access for wp_tube_video_metadata (VideoMetadataRepositoryInterface).
 *
 * Direct $wpdb access is the same documented, intentional exception
 * every dedicated-table repository in this project uses (ARCHITECTURE.md
 * §2.5/§11) — no WP_Query/WP_Meta_Query equivalent exists for this
 * table. Every operation here is single-row, so it uses $wpdb's own
 * insert()/update()/get_var() helpers (already internally parameterized)
 * rather than hand-written SQL — the same style
 * `Tube_Core\Database\SchemaVersionStore` already established in Phase 1.
 *
 * `find()` and `find_many()` share a request-lifetime, plugin-instance-
 * scoped in-memory cache (this class's own instance is memoized for the
 * whole request by `Tube_Core\Plugin::video_metadata_repository()`, the
 * same lazy-singleton pattern every other accessor there already uses).
 * Added in Phase 11 after the audit found `tube-player`'s
 * `tube_player_get_image_html()`/`tube_player_get_embed_html()` template
 * tags — which each call `find()` once per video — being called once per
 * card inside every theme grid template's loop (homepage, every archive
 * page, search, related videos), with no batching: an N-query-per-page
 * pattern on exactly the highest-traffic pages. `tube_player_prime_video_metadata()`
 * (tube-player's new template tag) calls `find_many()` once before such a
 * loop specifically to populate this cache, so each subsequent per-card
 * `find()` call is then a free in-memory lookup instead of its own query
 * — without changing either existing template tag's signature or this
 * interface's contract at all. A cache miss still falls through to a
 * real single-row query, so correctness never depends on priming having
 * happened first; priming is purely a performance optimization.
 *
 * Every write method (`create()`/`update_status()`/`update_images()`/
 * `update_thumbnail_time()`) unsets its video's cache entry after
 * writing, so a `find()` later in the same request always re-queries
 * rather than returning what it cached before the write — including the
 * case where an earlier `find()` cached a real "no row yet" null for a
 * video that then gets `create()`'d within the same request (the import
 * pipeline's own call sequence). A missed invalidation here would be a
 * silent stale-read bug, not just a missed optimization — caught by this
 * phase's own integration tests re-running against the real request-
 * lifetime singleton the same way `Tube_Core\Plugin::video_metadata_repository()`
 * serves it.
 */
final class VideoMetadataRepository implements VideoMetadataRepositoryInterface
{
    /**
     * Request-lifetime cache: video_id => resolved result. A key's mere
     * presence (checked via array_key_exists(), not isset()) means "this
     * ID has already been resolved," since the value itself is legitimately
     * null for a video with no metadata row.
     *
     * @var array<int, VideoMetadata|null>
     */
    private array $cache = [];

    /**
     * {@inheritDoc}
     *
     * @param int            $video_id      The video post ID.
     * @param string         $cf_stream_uid The Cloudflare Stream UID.
     * @param CfStreamStatus $status        The initial encoding status.
     */
    public function create(int $video_id, string $cf_stream_uid, CfStreamStatus $status): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $now = current_time('mysql', true);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $wpdb->insert(
            $wpdb->prefix . 'tube_video_metadata',
            [
                'video_id'      => $video_id,
                'source'        => VideoSource::CloudflareStream->value,
                'cf_stream_uid' => $cf_stream_uid,
                'cf_status'     => $status->value,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s']
        );

        unset($this->cache[ $video_id ]);
    }

    /**
     * {@inheritDoc}
     *
     * @param int            $video_id      The video post ID.
     * @param string         $r2_object_key The canonical R2 object key.
     * @param CfStreamStatus $status        `Ready` or `Error` — never `Pending`/`Processing` (see interface docblock).
     */
    public function create_r2(int $video_id, string $r2_object_key, CfStreamStatus $status): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $now = current_time('mysql', true);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $wpdb->insert(
            $wpdb->prefix . 'tube_video_metadata',
            [
                'video_id'      => $video_id,
                'source'        => VideoSource::R2Mp4->value,
                'r2_object_key' => $r2_object_key,
                'cf_status'     => $status->value,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s']
        );

        unset($this->cache[ $video_id ]);
    }

    /**
     * {@inheritDoc}
     *
     * @param int $video_id The video post ID.
     */
    public function find(int $video_id): ?VideoMetadata
    {
        if (array_key_exists($video_id, $this->cache)) {
            return $this->cache[ $video_id ];
        }

        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT video_id, source, cf_stream_uid, r2_object_key, cf_status, duration_seconds,'
                . ' thumbnail_time_seconds, poster_image_id, og_image_id FROM %i WHERE video_id = %d',
                $wpdb->prefix . 'tube_video_metadata',
                $video_id
            ),
            ARRAY_A
        );

        if (! is_array($row)) {
            $this->cache[ $video_id ] = null;

            return null;
        }

        /** @var array{video_id: string, source: string, cf_stream_uid: string|null, r2_object_key: string|null, cf_status: string, duration_seconds: string|null, thumbnail_time_seconds: string, poster_image_id: string|null, og_image_id: string|null} $row */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $metadata                 = self::hydrate($row);
        $this->cache[ $video_id ] = $metadata;

        return $metadata;
    }

    /**
     * {@inheritDoc}
     *
     * @param int[] $video_ids The video post IDs to fetch.
     *
     * @return array<int, VideoMetadata>
     *
     * @throws RuntimeException If the query template is malformed (a bug in this method, not in any argument).
     */
    public function find_many(array $video_ids): array
    {
        $video_ids = array_values(array_unique($video_ids));

        // Skip IDs this instance already resolved (whether found or not) —
        // find_many() is also how tube_player_prime_video_metadata() warms
        // the cache before a theme grid loop, so a second call for a
        // partially-overlapping ID list (e.g. related videos sharing an ID
        // with a homepage row already rendered) never re-queries them.
        $uncached_ids = array_values(
            array_filter($video_ids, fn (int $video_id): bool => ! array_key_exists($video_id, $this->cache))
        );

        if ([] !== $uncached_ids) {
            global $wpdb;
            /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

            $placeholders = implode(', ', array_fill(0, count($uncached_ids), '%d'));

            $sql = $wpdb->prepare(
                'SELECT video_id, source, cf_stream_uid, r2_object_key, cf_status, duration_seconds,'
                    . ' thumbnail_time_seconds, poster_image_id, og_image_id'
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $placeholders is a fixed-shape string of literal "%d" tokens (one per element of $uncached_ids), never external input, and every actual value is still a %i/%d-bound argument below.
                    . " FROM %i WHERE video_id IN ({$placeholders})",
                array_merge([$wpdb->prefix . 'tube_video_metadata'], $uncached_ids)
            );

            if (null === $sql) {
                throw new RuntimeException(
                    'wpdb::prepare() returned null for the find_many() query in ' . self::class . '.'
                );
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); $sql *is* $wpdb->prepare()'d above.
            $rows = $wpdb->get_results($sql, ARRAY_A);

            /** @var array<int, array{video_id: string, source: string, cf_stream_uid: string|null, r2_object_key: string|null, cf_status: string, duration_seconds: string|null, thumbnail_time_seconds: string, poster_image_id: string|null, og_image_id: string|null}> $rows */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
            $rows = (array) $rows;

            $found_ids = [];

            foreach ($rows as $row) {
                $metadata                           = self::hydrate($row);
                $this->cache[ $metadata->video_id ] = $metadata;
                $found_ids[ $metadata->video_id ]   = true;
            }

            // Every requested-but-not-returned ID genuinely has no metadata
            // row -- cache that too, so a later find() for it is also free
            // instead of re-querying for a row that doesn't exist.
            foreach ($uncached_ids as $uncached_id) {
                if (! isset($found_ids[ $uncached_id ])) {
                    $this->cache[ $uncached_id ] = null;
                }
            }
        }

        $result = [];

        foreach ($video_ids as $video_id) {
            $metadata = $this->cache[ $video_id ];

            if (null !== $metadata) {
                $result[ $video_id ] = $metadata;
            }
        }

        return $result;
    }

    /**
     * Turn one raw $wpdb row into a VideoMetadata.
     *
     * @param array<string, string|null> $row One raw $wpdb row.
     *
     * @phpstan-param array{
     *     video_id: string,
     *     source: string,
     *     cf_stream_uid: string|null,
     *     r2_object_key: string|null,
     *     cf_status: string,
     *     duration_seconds: string|null,
     *     thumbnail_time_seconds: string,
     *     poster_image_id: string|null,
     *     og_image_id: string|null
     * } $row
     */
    private static function hydrate(array $row): VideoMetadata
    {
        return new VideoMetadata(
            (int) $row['video_id'],
            VideoSource::from($row['source']),
            $row['cf_stream_uid'],
            $row['r2_object_key'],
            CfStreamStatus::from($row['cf_status']),
            null === $row['duration_seconds'] ? null : (int) $row['duration_seconds'],
            (int) $row['thumbnail_time_seconds'],
            null === $row['poster_image_id'] ? null : (int) $row['poster_image_id'],
            null === $row['og_image_id'] ? null : (int) $row['og_image_id']
        );
    }

    /**
     * {@inheritDoc}
     *
     * @param string $cf_stream_uid The Cloudflare Stream UID to look up.
     */
    public function find_video_id_by_stream_uid(string $cf_stream_uid): ?int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $video_id = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT video_id FROM %i WHERE cf_stream_uid = %s',
                $wpdb->prefix . 'tube_video_metadata',
                $cf_stream_uid
            )
        );

        return null === $video_id ? null : (int) $video_id;
    }

    /**
     * {@inheritDoc}
     *
     * @param string $r2_object_key The R2 object key to look up.
     */
    public function find_video_id_by_r2_object_key(string $r2_object_key): ?int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $video_id = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT video_id FROM %i WHERE r2_object_key = %s',
                $wpdb->prefix . 'tube_video_metadata',
                $r2_object_key
            )
        );

        return null === $video_id ? null : (int) $video_id;
    }

    /**
     * {@inheritDoc}
     *
     * @param int $video_id The video post ID.
     */
    public function status_for(int $video_id): ?CfStreamStatus
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $status = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT cf_status FROM %i WHERE video_id = %d',
                $wpdb->prefix . 'tube_video_metadata',
                $video_id
            )
        );

        return null === $status ? null : CfStreamStatus::from($status);
    }

    /**
     * {@inheritDoc}
     *
     * @param int            $video_id         The video post ID.
     * @param CfStreamStatus $status           The new encoding status.
     * @param int|null       $duration_seconds The video's duration, if known; left unchanged if null.
     */
    public function update_status(int $video_id, CfStreamStatus $status, ?int $duration_seconds): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $table = $wpdb->prefix . 'tube_video_metadata';
        $now   = current_time('mysql', true);

        $data    = [
            'cf_status'  => $status->value,
            'updated_at' => $now,
        ];
        $formats = ['%s', '%s'];

        if (null !== $duration_seconds) {
            $data['duration_seconds'] = $duration_seconds;
            $formats[]                = '%d';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $wpdb->update($table, $data, ['video_id' => $video_id], $formats, ['%d']);

        unset($this->cache[ $video_id ]);
    }

    /**
     * {@inheritDoc}
     *
     * @param int      $video_id        The video post ID.
     * @param int|null $poster_image_id The WordPress attachment ID to use as the poster override, or null to clear.
     * @param int|null $og_image_id     The WordPress attachment ID to use as the OG-image override, or null to clear.
     */
    public function update_images(int $video_id, ?int $poster_image_id, ?int $og_image_id): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $wpdb->update(
            $wpdb->prefix . 'tube_video_metadata',
            [
                'poster_image_id' => $poster_image_id,
                'og_image_id'     => $og_image_id,
                'updated_at'      => current_time('mysql', true),
            ],
            ['video_id' => $video_id],
            ['%d', '%d', '%s'],
            ['%d']
        );

        unset($this->cache[ $video_id ]);
    }

    /**
     * {@inheritDoc}
     *
     * @param int    $video_id      The video post ID.
     * @param string $r2_object_key The new R2 object key.
     */
    public function update_r2_object_key(int $video_id, string $r2_object_key): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $wpdb->update(
            $wpdb->prefix . 'tube_video_metadata',
            [
                'r2_object_key' => $r2_object_key,
                'updated_at'    => current_time('mysql', true),
            ],
            ['video_id' => $video_id],
            ['%s', '%s'],
            ['%d']
        );

        unset($this->cache[ $video_id ]);
    }

    /**
     * {@inheritDoc}
     *
     * @param int $video_id               The video post ID.
     * @param int $thumbnail_time_seconds The offset, in seconds, to extract the default thumbnail from.
     */
    public function update_thumbnail_time(int $video_id, int $thumbnail_time_seconds): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $wpdb->update(
            $wpdb->prefix . 'tube_video_metadata',
            [
                'thumbnail_time_seconds' => $thumbnail_time_seconds,
                'updated_at'             => current_time('mysql', true),
            ],
            ['video_id' => $video_id],
            ['%d', '%s'],
            ['%d']
        );

        unset($this->cache[ $video_id ]);
    }

    /**
     * {@inheritDoc}
     *
     * @param int    $video_id      The video post ID.
     * @param string $cf_stream_uid The new Cloudflare Stream UID.
     */
    public function update_stream_uid(int $video_id, string $cf_stream_uid): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $wpdb->update(
            $wpdb->prefix . 'tube_video_metadata',
            [
                'cf_stream_uid' => $cf_stream_uid,
                'updated_at'    => current_time('mysql', true),
            ],
            ['video_id' => $video_id],
            ['%s', '%s'],
            ['%d']
        );

        unset($this->cache[ $video_id ]);
    }

    /**
     * {@inheritDoc}
     *
     * @param int $limit  How many rows to return.
     * @param int $offset How many rows to skip.
     *
     * @return list<array{video_id: int, cf_stream_uid: string}>
     */
    public function all_stream_uids(int $limit, int $offset): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table, no WP_Query equivalent. See ARCHITECTURE.md §2.5, §11.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT video_id, cf_stream_uid FROM %i ORDER BY video_id ASC LIMIT %d OFFSET %d',
                $wpdb->prefix . 'tube_video_metadata',
                $limit,
                $offset
            ),
            ARRAY_A
        );

        /** @var array<int, array{video_id: string, cf_stream_uid: string}> $rows */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
        $rows = (array) $rows;

        $result = [];

        foreach ($rows as $row) {
            $result[] = [
                'video_id'      => (int) $row['video_id'],
                'cf_stream_uid' => $row['cf_stream_uid'],
            ];
        }

        return $result;
    }
}
