<?php
/**
 * Safely narrows REST/superglobal request parameters (typed `mixed`) to scalars.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

namespace Tube_Comments\Support;

/**
 * Safely narrows REST/superglobal request parameters (typed `mixed` by
 * `WP_REST_Request::get_param()`) to scalars — this plugin's own copy of
 * `Tube_Members\Support\Params`, same reasoning, kept separate since
 * neither plugin's Composer autoloader can see the other's classes.
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
     * Narrows `$wpdb->prepare()`'s `string|null` return to a real string,
     * for the every repository call site that immediately hands it to
     * `$wpdb->query()`/`get_col()`/`get_var()`/`get_row()` — those only
     * accept a plain `string`. `$wpdb->prepare()` only ever returns null
     * for a malformed query template (a bug in the calling code, never a
     * runtime/user-input condition), so this is the same
     * "should never happen, but narrow and fail loudly if it somehow
     * does" posture `Tube_Core\Likes\Repositories\LikeRepository::add()`
     * already documents inline for its own `$wpdb->prepare()` calls.
     *
     * @param string|null $sql The result of a `$wpdb->prepare()` call.
     *
     * @throws \RuntimeException If $sql is null.
     */
    public static function required_sql(?string $sql): string
    {
        if (null === $sql) {
            throw new \RuntimeException('wpdb::prepare() returned null.');
        }

        return $sql;
    }
}
