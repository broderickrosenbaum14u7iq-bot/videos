<?php
/**
 * A read-only snapshot of one wp_tube_actors row.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Content;

/**
 * A read-only snapshot of one `wp_tube_actors` row, per ARCHITECTURE.md
 * §14 — a dedicated table, not a taxonomy term. `video_count` is the
 * table's own denormalized counter column; no application code
 * maintains it yet (the editorial UI that assigns actors to videos is
 * `tube-admin`'s, Phase 10), so callers needing an accurate count use
 * `ActorRepositoryInterface::count_videos_for_actor()` instead.
 */
final class Actor
{
    /**
     * Construct an immutable snapshot of one actor.
     *
     * @param int         $id             The actor's row ID.
     * @param string      $name           The actor's display name.
     * @param string      $slug           The actor's URL slug.
     * @param string|null $bio            The actor's biography, if any.
     * @param int|null    $photo_image_id Cloudflare Images ID for the actor's photo, if set.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly ?string $bio,
        public readonly ?int $photo_image_id
    ) {
    }
}
