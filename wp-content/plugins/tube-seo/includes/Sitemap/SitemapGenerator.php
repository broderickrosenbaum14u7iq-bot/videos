<?php
/**
 * Orchestrates video XML sitemap generation.
 *
 * @package Tube_Seo
 */

declare(strict_types=1);

namespace Tube_Seo\Sitemap;

use RuntimeException;
use Tube_Core\Plugin as Tube_Core_Plugin;
use Tube_Core\Video\VideoMetadata;
use Tube_Player\Plugin as Tube_Player_Plugin;
use Tube_Player\Video\ImageSize;

/**
 * Orchestrates video XML sitemap generation: gathers every published
 * video's data, builds `VideoSitemapEntry[]`, shards it into one or more
 * `video-sitemap-*.xml` files (plus a `video-sitemap-index.xml` when more
 * than one shard exists) via `SitemapXmlBuilder`, and writes them under
 * `wp-content/uploads/tube-seo-sitemaps/`.
 *
 * Incremental regeneration: `PublishedVideoRepository::aggregate_state()`
 * (a single cheap `COUNT(*)`/`MAX(post_modified_gmt)` query) is compared
 * against the state stored from the previous run; generation is skipped
 * unless it changed (or `$force` is passed). This is deliberately a
 * poll-based check run on every invocation, not a `video.published`
 * event-driven "dirty" flag: ARCHITECTURE.md §6 lists `tube-seo (sitemap
 * flag)` as that event's downstream effect, but a publish-only flag
 * cannot detect the other two changes that must also invalidate a
 * generated sitemap — unpublishing/trashing a video (drops out of the
 * `COUNT(*)`) and editing a still-published video's content (moves
 * `MAX(post_modified_gmt)`) — neither of which fires `video.published`.
 * Since the cron-driven command already re-checks this cheap aggregate
 * on every hourly run regardless, a separate event subscriber would only
 * ever duplicate what this check already catches — real, unjustified
 * complexity for no behavioral benefit, per this phase's explicit
 * anti-over-engineering instruction.
 *
 * WordPress/tube-core/tube-player-coupled throughout, the same
 * thin-orchestrator split `Tube_Seo\Head\SeoHead` already established for
 * this plugin: pure structure-building is delegated to `SitemapXmlBuilder`
 * (unit-tested), while this class itself is exercised by integration
 * tests against real seeded data.
 */
final class SitemapGenerator
{
    /**
     * The WP option this plugin's own generation state is stored under —
     * `{count, max_modified_gmt}`, per `PublishedVideoRepository::
     * aggregate_state()`'s shape. A plain WP option, not a dedicated
     * table: ARCHITECTURE.md line 132 documents tube-seo as owning no
     * tables of its own ("may only need options"), and this is the
     * smallest persisted state that satisfies that.
     */
    private const STATE_OPTION = 'tube_seo_sitemap_state';

    /**
     * The `apply_filters()` hook used to override how many URLs one sitemap file may hold.
     */
    private const URLS_PER_SITEMAP_FILTER = 'tube_seo_sitemap_urls_per_sitemap';

    /**
     * The default URLs-per-file ceiling — comfortably under the
     * sitemaps.org protocol's hard 50,000-URL/50MB-per-file limit, chosen
     * so a single shard alone covers this project's entire target scale
     * (3,000-10,000 videos) without sharding in the common case.
     */
    private const DEFAULT_URLS_PER_SITEMAP = 40000;

    /**
     * The uploads-relative subdirectory sitemap files are written into.
     */
    private const SUBDIRECTORY = 'tube-seo-sitemaps';

    /**
     * The single-shard filename.
     */
    private const FILE_SINGLE = 'video-sitemap.xml';

    /**
     * The sitemap-index filename, written only when more than one shard exists.
     */
    private const FILE_INDEX = 'video-sitemap-index.xml';

    /**
     * Construct a generator against its collaborators.
     *
     * @param PublishedVideoRepository $videos  Reads the published-video set directly from `wp_posts`.
     * @param SitemapXmlBuilder        $builder Renders resolved entries into sitemap XML.
     */
    public function __construct(
        private readonly PublishedVideoRepository $videos,
        private readonly SitemapXmlBuilder $builder
    ) {
    }

    /**
     * The absolute filesystem directory sitemap files are written to/served from.
     */
    public static function directory(): string
    {
        $uploads = wp_upload_dir();

        return trailingslashit($uploads['basedir']) . self::SUBDIRECTORY;
    }

    /**
     * Generate (or skip regenerating) the video sitemap.
     *
     * @param bool $force Regenerate even if the published-video set hasn't changed since the last run.
     *
     * @throws RuntimeException If the sitemap directory can't be created, or a sitemap file can't be written.
     */
    public function generate(bool $force = false): SitemapGenerationResult
    {
        $state = $this->videos->aggregate_state();

        if (! $force && $this->matches_stored_state($state)) {
            return new SitemapGenerationResult(false, 0, 0);
        }

        $entries = $this->build_entries($this->videos->published_videos());

        $directory = self::directory();

        if (! wp_mkdir_p($directory)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception message, never rendered as HTML output; surfaced only via WP_CLI::error()/cron log text.
            throw new RuntimeException("Unable to create sitemap directory: {$directory}");
        }

        $shards      = [] === $entries ? [[]] : array_chunk($entries, $this->urls_per_sitemap());
        $shard_count = count($shards);
        $sitemaps    = [];
        $written     = [];

        foreach ($shards as $index => $shard) {
            $filename  = $this->shard_filename($index, $shard_count);
            $written[] = $filename;

            $this->write_file(
                trailingslashit($directory) . $filename,
                $this->builder->build_urlset($shard)
            );

            $sitemaps[] = [
                'loc'     => home_url('/' . $filename),
                'lastmod' => $this->shard_lastmod($shard),
            ];
        }

        if ($shard_count > 1) {
            $written[] = self::FILE_INDEX;

            $this->write_file(trailingslashit($directory) . self::FILE_INDEX, $this->builder->build_index($sitemaps));
        }

        // A prior run may have used a different filename set (e.g. it
        // sharded when this run didn't, or it had more shards than this
        // run does) — anything left over from that would otherwise keep
        // being served indefinitely with stale content.
        $this->delete_files_not_in($directory, $written);

        $this->store_state($state);

        return new SitemapGenerationResult(true, $shard_count, count($entries));
    }

    /**
     * Delete any `video-sitemap*.xml` file in the directory that isn't one of this run's own output files.
     *
     * @param string   $directory      The sitemap directory.
     * @param string[] $keep_filenames The filenames this run wrote, to keep.
     */
    private function delete_files_not_in(string $directory, array $keep_filenames): void
    {
        $existing = glob(trailingslashit($directory) . 'video-sitemap*.xml');

        foreach (false === $existing ? [] : $existing as $path) {
            if (! in_array(basename($path), $keep_filenames, true)) {
                wp_delete_file($path);
            }
        }
    }

    /**
     * Build one sitemap entry per published video that has usable
     * Cloudflare Stream metadata AND a resolvable WordPress Media
     * Library image — og_image_id if set, else poster_image_id (see
     * self::build_entry()'s docblock) — (ADR-0001's 2026-08-25 addendum:
     * there is no Cloudflare Stream thumbnail fallback anywhere in this
     * project, and Google's video sitemap protocol requires
     * `<video:thumbnail_loc>` — a real value, not a fabricated one).
     * Either gap means there's no real thumbnail/player URL to publish
     * yet, so the video is deliberately omitted — the same "not ready"
     * gate `tube-player`'s own rendering already applies elsewhere in
     * this project.
     *
     * @param array<int, array{video_id: int, post_date_gmt: string, post_modified_gmt: string}> $rows Rows from
     *     PublishedVideoRepository::published_videos().
     *
     * @return list<VideoSitemapEntry>
     */
    private function build_entries(array $rows): array
    {
        if ([] === $rows) {
            return [];
        }

        $video_ids = [];

        foreach ($rows as $row) {
            $video_ids[] = $row['video_id'];
        }

        // Prime WordPress's own post cache in one batched query so the
        // get_the_title()/get_the_excerpt()/get_permalink() calls below
        // — the correct, frozen-URL-respecting way to get these values —
        // don't each issue their own per-video query. The same technique
        // WP_Query itself uses internally; PublishedVideoRepository's own
        // raw $wpdb read deliberately skips WP_Post hydration, so nothing
        // has primed this cache yet.
        _prime_post_caches($video_ids, false, false);

        $metadata_by_id = Tube_Core_Plugin::instance()->video_metadata_repository()->find_many($video_ids);

        $entries = [];

        foreach ($rows as $row) {
            $metadata = $metadata_by_id[ $row['video_id'] ] ?? null;

            if (! $metadata instanceof VideoMetadata) {
                continue;
            }

            $entry = $this->build_entry($row, $metadata);

            if (null !== $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * Build one video's sitemap entry, or null if it has no resolvable
     * WordPress Media Library OG-image (see self::build_entries()'s
     * docblock for why that omits the video entirely rather than
     * publishing an invalid/fabricated `thumbnail_loc`).
     *
     * @param array<string, int|string> $row      One published-video row.
     * @param VideoMetadata             $metadata The video's metadata (either source).
     *
     * @phpstan-param array{video_id: int, post_date_gmt: string, post_modified_gmt: string} $row
     */
    private function build_entry(array $row, VideoMetadata $metadata): ?VideoSitemapEntry
    {
        $video_id = $row['video_id'];

        // resolve_urls() resolves metadata->og_image_id (a WordPress
        // attachment ID, ADR-0001) — still the only image *source type*
        // (WordPress Media Library, no Cloudflare Stream thumbnail
        // fallback, 2026-08-25 addendum) — but falls back to
        // metadata->poster_image_id when no OG-image override has been
        // set: the current admin editing flow (PosterImageMetaBox) only
        // ever writes poster_image_id, never og_image_id, so requiring
        // og_image_id alone silently excluded every real published video
        // from this sitemap (2026-08-26 SEO audit finding, P0). Both IDs
        // are still WordPress Media Library attachments; this changes
        // which existing field is read, not how either is set or
        // rendered elsewhere. Final fallback is WordPress's own native
        // Featured Image (`_thumbnail_id`) — a real attachment some
        // videos already have set even though neither tube-core image
        // field was ever populated for them (2026-08-26 SEO audit P2
        // finding, investigated per-video, not assumed).
        $image_url = Tube_Player_Plugin::instance()->image_renderer()->resolve_urls(
            $metadata->og_image_id ?? $metadata->poster_image_id ?? self::native_featured_image_id($video_id),
            ImageSize::OgImage
        )['src'];

        if (null === $image_url) {
            return null;
        }

        $loc = get_permalink($video_id);
        $loc = false === $loc ? home_url('/') : $loc;

        $title = get_the_title($video_id);

        $description = get_the_excerpt($video_id);
        $description = '' === $description ? $title : $description;

        return new VideoSitemapEntry(
            $video_id,
            $loc,
            self::to_w3c_datetime($row['post_modified_gmt']),
            $title,
            $description,
            $image_url,
            tube_player_get_source_url($video_id),
            self::to_w3c_datetime($row['post_date_gmt']),
            $metadata->duration_seconds
        );
    }

    /**
     * This video's WordPress native Featured Image attachment ID, if
     * one is set — the final image fallback (see self::build_entry()'s
     * docblock comment for why).
     *
     * @param int $video_id The video post ID.
     */
    private static function native_featured_image_id(int $video_id): ?int
    {
        $thumbnail_id = get_post_thumbnail_id($video_id);

        return false === $thumbnail_id || 0 === $thumbnail_id ? null : (int) $thumbnail_id;
    }

    /**
     * Convert a MySQL GMT datetime string (`Y-m-d H:i:s`) into a W3C datetime, in GMT.
     *
     * @param string $mysql_gmt_datetime A GMT datetime, as read from a `*_gmt` column.
     */
    private static function to_w3c_datetime(string $mysql_gmt_datetime): string
    {
        $formatted = mysql2date(DATE_ATOM, $mysql_gmt_datetime, false);

        return false === $formatted ? gmdate(DATE_ATOM) : $formatted;
    }

    /**
     * The most recent lastmod among a shard's entries, for its `<sitemap>` index row.
     *
     * @param VideoSitemapEntry[] $shard One shard's entries.
     */
    private function shard_lastmod(array $shard): string
    {
        if ([] === $shard) {
            return gmdate(DATE_ATOM);
        }

        $lastmods = [];

        foreach ($shard as $entry) {
            $lastmods[] = $entry->lastmod;
        }

        // ISO 8601/W3C datetimes in a single fixed offset (GMT, produced
        // by to_w3c_datetime() above) sort correctly as plain strings.
        sort($lastmods);

        return (string) end($lastmods);
    }

    /**
     * The filename for one shard.
     *
     * @param int $index       The shard's zero-based position.
     * @param int $shard_count How many shards this run produced.
     */
    private function shard_filename(int $index, int $shard_count): string
    {
        return $shard_count > 1 ? sprintf('video-sitemap-%d.xml', $index + 1) : self::FILE_SINGLE;
    }

    /**
     * The filterable URLs-per-file ceiling.
     *
     * @return positive-int
     */
    private function urls_per_sitemap(): int
    {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- self::URLS_PER_SITEMAP_FILTER is the literal, tube_seo_-prefixed constant defined a few lines above in this same class.
        $value = apply_filters(self::URLS_PER_SITEMAP_FILTER, self::DEFAULT_URLS_PER_SITEMAP);
        $value = is_int($value) ? $value : self::DEFAULT_URLS_PER_SITEMAP;

        return $value > 0 ? $value : self::DEFAULT_URLS_PER_SITEMAP;
    }

    /**
     * Whether the current aggregate state matches what the previous run stored.
     *
     * @param array{count: int, max_modified_gmt: string|null} $state The current aggregate state.
     */
    private function matches_stored_state(array $state): bool
    {
        $stored = get_option(self::STATE_OPTION, false);

        if (! is_array($stored)) {
            return false;
        }

        return ($stored['count'] ?? null) === $state['count']
            && ($stored['max_modified_gmt'] ?? null) === $state['max_modified_gmt'];
    }

    /**
     * Persist the aggregate state this run generated against.
     *
     * @param array{count: int, max_modified_gmt: string|null} $state The state to store.
     */
    private function store_state(array $state): void
    {
        update_option(self::STATE_OPTION, $state, false);
    }

    /**
     * Write one file's contents, failing loudly if the write itself fails.
     *
     * @param string $path     The absolute file path to write.
     * @param string $contents The file's contents.
     *
     * @throws RuntimeException If the write itself fails.
     */
    private function write_file(string $path, string $contents): void
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a WP-CLI/cron-only file write to this plugin's own already-owned uploads subdirectory; no FTP credential scenario applies (that's what WP_Filesystem exists for), matching plain-PHP file writes already used elsewhere for local, non-admin-UI output.
        if (false === file_put_contents($path, $contents)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception message, never rendered as HTML output; surfaced only via WP_CLI::error()/cron log text.
            throw new RuntimeException("Unable to write sitemap file: {$path}");
        }
    }
}
