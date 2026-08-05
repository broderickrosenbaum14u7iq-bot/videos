<?php
/**
 * One video's entry in the video sitemap.
 *
 * @package Tube_Seo
 */

declare(strict_types=1);

namespace Tube_Seo\Sitemap;

/**
 * One video's entry in the video sitemap — the shape
 * `SitemapXmlBuilder` renders into one `<url>`/`<video:video>` block,
 * per the Google video sitemap protocol
 * (https://developers.google.com/search/docs/crawling-indexing/sitemaps/video-sitemaps).
 */
final class VideoSitemapEntry
{
    /**
     * Construct an immutable snapshot of one sitemap entry.
     *
     * @param int      $video_id         The video post ID (not emitted, kept for logging/debugging only).
     * @param string   $loc              The video's canonical page URL (`/watch/{slug}/`).
     * @param string   $lastmod          W3C datetime — the video's last real content modification.
     * @param string   $title            The video's title.
     * @param string   $description      The video's description.
     * @param string   $thumbnail_loc    The video's thumbnail image URL.
     * @param string   $player_loc       The video's embeddable player URL.
     * @param string   $publication_date W3C datetime — when the video was first published.
     * @param int|null $duration_seconds The video's duration, if known — Google's `<video:duration>` is optional.
     */
    public function __construct(
        public readonly int $video_id,
        public readonly string $loc,
        public readonly string $lastmod,
        public readonly string $title,
        public readonly string $description,
        public readonly string $thumbnail_loc,
        public readonly string $player_loc,
        public readonly string $publication_date,
        public readonly ?int $duration_seconds
    ) {
    }
}
