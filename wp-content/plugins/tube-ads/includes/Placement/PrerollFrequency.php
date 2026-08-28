<?php
/**
 * How often the pre-roll ad is eligible to show.
 *
 * @package Tube_Ads
 */

declare(strict_types=1);

namespace Tube_Ads\Placement;

/**
 * How often the pre-roll ad is eligible to show (2026-08-26 §18
 * frequency-cap requirement). Enforced client-side (`assets/js/
 * tube-ads-preroll.js`) against `sessionStorage`, since pre-roll
 * eligibility is decided at the moment of a real play click, not at
 * page-render time — there is deliberately no server-side visitor
 * identity/cross-device tracking here (explicitly out of scope). When
 * storage is unavailable (private browsing, blocked), the JS fails open
 * to "allow" — the same fail-open posture this whole system uses for
 * every other failure mode, applied to the frequency cap itself.
 */
enum PrerollFrequency: string
{
    case EveryPlay      = 'every_play';
    case OncePerSession = 'once_per_session';
    case EveryNMinutes  = 'every_n_minutes';

    /**
     * A human-readable label for the admin screen.
     */
    public function label(): string
    {
        return match ($this) {
            self::EveryPlay => __('Every play', 'tube-ads'),
            self::OncePerSession => __('Once per session', 'tube-ads'),
            self::EveryNMinutes => __('Once every N minutes', 'tube-ads'),
        };
    }
}
