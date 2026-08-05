<?php
/**
 * `wp tube-seo sitemap:generate`.
 *
 * @package Tube_Seo
 */

declare(strict_types=1);

namespace Tube_Seo\CLI;

use Tube_Seo\Sitemap\SitemapGenerator;
use WP_CLI;

/**
 * `wp tube-seo sitemap:generate` — the hourly Linux-cron-driven job
 * ARCHITECTURE.md §7/§12 Phase 9 assigns to tube-seo's video XML sitemap.
 */
final class SitemapCommand
{
    /**
     * Construct around the generator this command runs.
     *
     * @param SitemapGenerator $generator Backs `sitemap:generate`.
     */
    public function __construct(private readonly SitemapGenerator $generator)
    {
    }

    /**
     * Regenerate the video XML sitemap, if the published-video set has changed since the last run.
     *
     * ## OPTIONS
     *
     * [--full]
     * : Regenerate even if nothing has changed since the last run.
     *
     * ## EXAMPLES
     *
     *     wp tube-seo sitemap:generate
     *     wp tube-seo sitemap:generate --full
     *
     * @when after_wp_load
     *
     * @param array<int, string>    $args       Positional arguments (unused).
     * @param array<string, string> $assoc_args Associative arguments (--full).
     */
    public function generate(array $args, array $assoc_args): void
    {
        $result = $this->generator->generate(isset($assoc_args['full']));

        if (! $result->regenerated) {
            WP_CLI::log('No changes since the last run; sitemap left unchanged.');

            return;
        }

        WP_CLI::success(
            sprintf(
                'Generated %d sitemap file(s) covering %d video(s).',
                $result->shard_count,
                $result->video_count
            )
        );
    }
}
