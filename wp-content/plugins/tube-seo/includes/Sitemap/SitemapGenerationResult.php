<?php
/**
 * The outcome of one SitemapGenerator::generate() call.
 *
 * @package Tube_Seo
 */

declare(strict_types=1);

namespace Tube_Seo\Sitemap;

/**
 * The outcome of one SitemapGenerator::generate() call — what
 * SitemapCommand reports back to the operator/cron log.
 */
final class SitemapGenerationResult
{
    /**
     * Construct an immutable generation outcome.
     *
     * @param bool $regenerated Whether files were actually (re)written, or the run was skipped as unchanged.
     * @param int  $shard_count How many `video-sitemap-N.xml` files were written (0 if skipped).
     * @param int  $video_count How many videos were included (0 if skipped).
     */
    public function __construct(
        public readonly bool $regenerated,
        public readonly int $shard_count,
        public readonly int $video_count
    ) {
    }
}
