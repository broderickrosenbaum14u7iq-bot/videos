<?php
/**
 * Safely narrows REST/superglobal request parameters (typed `mixed`) to scalars.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

namespace Tube_Members\Support;

/**
 * Safely narrows REST/superglobal request parameters (typed `mixed` by
 * `WP_REST_Request::get_param()`/`$_GET`/`$_POST`) to scalars, per the
 * same "narrow before cast" posture `Tube_Core\Likes\LikeController`/
 * `Tube_Core\WatchHistory\WatchHistoryController` already establish via
 * an inline `is_numeric()` guard before `(int)` — centralized here
 * rather than repeated inline at every one of this plugin's own request-
 * reading call sites, since almost all of them need exactly this.
 */
final class Params
{
    /**
     * $value as a string if it's a genuine scalar, else ''.
     *
     * @param mixed $value A raw request parameter.
     */
    public static function string($value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * $value as an int if it's numeric, else 0.
     *
     * @param mixed $value A raw request parameter.
     */
    public static function int($value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * $value as a bool — truthy per PHP's own casting rules for a genuine scalar, else false.
     *
     * @param mixed $value A raw request parameter.
     */
    public static function bool($value): bool
    {
        return is_scalar($value) && (bool) $value;
    }
}
