<?php
/**
 * Builds Cloudflare Images delivery URLs for custom poster/OG-image overrides.
 *
 * @package Tube_Player
 */

declare(strict_types=1);

namespace Tube_Player\Video\Cloudflare;

/**
 * Builds Cloudflare Images delivery URLs, per ARCHITECTURE.md §8's
 * custom-poster-override path (`poster_image_id`/`og_image_id`).
 *
 * Deliberately **not** behind an interface: `ARCHITECTURE_FREEZE.md`'s
 * Deferred Decisions table is explicit that an image/CDN provider
 * abstraction beyond `VideoProviderInterface` is "only [created] if a
 * concrete testability or vendor-risk need materializes... not created
 * speculatively now" — no second implementation or test-fake consumer
 * exists for this one, so per §19.1 it stays a concrete class.
 *
 * `$variant` names a Cloudflare Images "variant" (a named delivery
 * preset configured in the Cloudflare dashboard, not built or validated
 * here) — this project reuses `ImageSize`'s own string values as variant
 * names directly, so a preset configured for `grid_card` in Cloudflare
 * Images lines up with `ImageSize::GridCard` with no separate mapping
 * table to keep in sync.
 */
final class CloudflareImagesUrlBuilder
{
    /**
     * Construct around the Cloudflare Images account hash.
     *
     * @param string $account_hash The Cloudflare Images account hash (from `imagedelivery.net/{account_hash}/...`).
     */
    public function __construct(private readonly string $account_hash)
    {
    }

    /**
     * Build a delivery URL for one image/variant.
     *
     * @param int    $image_id The Cloudflare Images ID.
     * @param string $variant  The configured Cloudflare Images variant name.
     */
    public function url(int $image_id, string $variant): string
    {
        return "https://imagedelivery.net/{$this->account_hash}/{$image_id}/{$variant}";
    }
}
