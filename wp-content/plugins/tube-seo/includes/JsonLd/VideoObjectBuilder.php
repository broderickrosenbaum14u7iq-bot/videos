<?php
/**
 * Builds the schema.org VideoObject JSON-LD structure for one video.
 *
 * @package Tube_Seo
 */

declare(strict_types=1);

namespace Tube_Seo\JsonLd;

/**
 * Builds the schema.org `VideoObject` JSON-LD structure for one video,
 * per this phase's explicit SEO deliverable. Pure array-building logic
 * given already-resolved scalar inputs — no WordPress calls, no
 * escaping (the caller `wp_json_encode()`s the result and is
 * responsible for safe `<script>` embedding) — fully unit-tested.
 */
final class VideoObjectBuilder
{
    /**
     * Build the VideoObject structure for one video.
     *
     * @param string      $name          The video's title.
     * @param string      $description   The video's description.
     * @param string|null $thumbnail_url The video's WordPress Media Library poster/OG-image URL, or null if the
     *     editor hasn't set one yet (ADR-0001 — no Cloudflare Stream thumbnail fallback, see the 2026-08-25
     *     addendum). Not fabricated: omitted from the structure entirely rather than emitted as an empty string.
     * @param string      $upload_date   ISO 8601 upload/publish date-time.
     * @param string|null $duration      ISO 8601 duration (e.g. `PT5M30S`), or null if unknown.
     * @param string      $embed_url     The Cloudflare Stream embed URL.
     * @param int         $view_count    The video's current all-time view count.
     *
     * @return array<string, mixed>
     *
     * @phpstan-return array{
     *     '@context': string,
     *     '@type': string,
     *     name: string,
     *     description: string,
     *     uploadDate: string,
     *     embedUrl: string,
     *     interactionStatistic: array{'@type': string, interactionType: string, userInteractionCount: int},
     *     thumbnailUrl?: array{0: string},
     *     duration?: string
     * }
     */
    public static function build(
        string $name,
        string $description,
        ?string $thumbnail_url,
        string $upload_date,
        ?string $duration,
        string $embed_url,
        int $view_count
    ): array {
        $video_object = [
            '@context'             => 'https://schema.org',
            '@type'                => 'VideoObject',
            'name'                 => $name,
            'description'          => $description,
            'uploadDate'           => $upload_date,
            'embedUrl'             => $embed_url,
            'interactionStatistic' => [
                '@type'                => 'InteractionCounter',
                'interactionType'      => 'https://schema.org/WatchAction',
                'userInteractionCount' => $view_count,
            ],
        ];

        if (null !== $thumbnail_url) {
            $video_object['thumbnailUrl'] = [$thumbnail_url];
        }

        if (null !== $duration) {
            $video_object['duration'] = $duration;
        }

        return $video_object;
    }

    /**
     * Convert a duration in seconds to an ISO 8601 duration string
     * (e.g. `PT5M30S`), the format `self::build()`'s `duration` field
     * (and schema.org's `VideoObject.duration`) requires. Pure integer
     * arithmetic — no WordPress calls.
     *
     * @param int $seconds The duration, in seconds.
     */
    public static function iso8601_duration(int $seconds): string
    {
        $seconds = max(0, $seconds);

        $hours             = intdiv($seconds, 3600);
        $minutes           = intdiv($seconds % 3600, 60);
        $remaining_seconds = $seconds % 60;

        $result = 'PT';

        if ($hours > 0) {
            $result .= $hours . 'H';
        }

        if ($minutes > 0) {
            $result .= $minutes . 'M';
        }

        if ($remaining_seconds > 0 || ('PT' === $result)) {
            $result .= $remaining_seconds . 'S';
        }

        return $result;
    }
}
