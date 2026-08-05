<?php
/**
 * A read-only snapshot of one wp_tube_studios row.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Content;

/**
 * A read-only snapshot of one `wp_tube_studios` row, per ARCHITECTURE.md
 * §14 — a dedicated table, not a taxonomy term. `parent_id` preserves
 * the hierarchical relationship studio had as a taxonomy, but Phase 8
 * doesn't build any hierarchy-aware presentation for it — no current
 * caller needs sub-studio browsing yet, and building it now would be
 * speculative. `video_count` is the table's own denormalized counter;
 * see `Actor`'s docblock for why it isn't relied on yet.
 */
final class Studio
{
    /**
     * Construct an immutable snapshot of one studio.
     *
     * @param int         $id            The studio's row ID.
     * @param string      $name          The studio's display name.
     * @param string      $slug          The studio's URL slug.
     * @param string|null $description   The studio's description, if any.
     * @param int|null    $logo_image_id Cloudflare Images ID for the studio's logo, if set.
     * @param string|null $website_url   The studio's external website, if set.
     * @param int|null    $parent_id     The parent studio's row ID, if this is a sub-studio.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly ?string $description,
        public readonly ?int $logo_image_id,
        public readonly ?string $website_url,
        public readonly ?int $parent_id
    ) {
    }
}
