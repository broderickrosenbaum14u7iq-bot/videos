<?php
/**
 * The site-wide custom script placement's stored configuration.
 *
 * @package Tube_Ads
 */

declare(strict_types=1);

namespace Tube_Ads\Placement;

/**
 * The site-wide custom script placement's stored configuration
 * (`Placement::GlobalCustomScript`) — deliberately just enabled + raw
 * code, no device/schedule targeting: this placement exists for network
 * code that legitimately needs to load on every page (e.g. a popunder/
 * social-bar provider's site verification script), not a positioned
 * creative. Never auto-injected when `$code` is empty (2026-08-26 §4
 * requirement).
 */
final class GlobalScriptConfig
{
    /**
     * Construct the global custom script configuration.
     *
     * @param bool   $enabled Whether the script is active at all.
     * @param string $code    The raw HTML/JS to output. Empty means nothing renders.
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly string $code
    ) {
    }

    /**
     * Whether this should render at all: enabled AND non-empty.
     */
    public function is_active(): bool
    {
        return $this->enabled && '' !== trim($this->code);
    }

    /**
     * A safe, inert default.
     */
    public static function disabled(): self
    {
        return new self(false, '');
    }
}
