<?php
/**
 * Gets or creates a guest's watch-history cookie token.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\WatchHistory;

/**
 * Gets or creates a guest's watch-history cookie token, per
 * ARCHITECTURE.md §12 Phase 5 ("support guest users").
 *
 * WordPress/HTTP-coupled by nature (reads `$_COOKIE`, calls `setcookie()`)
 * — not unit-tested, verified live instead, the same split this
 * project's other thin real adapters use (`WordPressHookBus`,
 * `RedisCache`, `RedisViewCounter`).
 */
final class VisitorToken
{
    /**
     * The cookie name guest visitor tokens are stored under.
     */
    private const COOKIE_NAME = 'tube_visitor';

    /**
     * One year — long enough that a returning guest's watch history
     * survives, short enough that an abandoned browser profile's cookie
     * doesn't linger indefinitely.
     */
    private const COOKIE_LIFETIME_SECONDS = 31536000;

    /**
     * Return the current request's visitor token, creating and setting
     * a fresh cookie if none is present or the existing one is malformed.
     */
    public function get_or_create(): string
    {
        $existing = $_COOKIE[ self::COOKIE_NAME ] ?? null;

        if (is_string($existing) && $this->is_valid_token($existing)) {
            return $existing;
        }

        $token = wp_generate_uuid4();

        setcookie(
            self::COOKIE_NAME,
            $token,
            [
                'expires'  => time() + self::COOKIE_LIFETIME_SECONDS,
                'path'     => '/',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );

        return $token;
    }

    /**
     * The current request's visitor token, without creating one —
     * `null` if the visitor has no (or an invalid) `tube_visitor`
     * cookie yet. For read-only "does this guest already have state"
     * checks at page-render time (e.g. `tube_core_has_liked()`) that must
     * never set a cookie on what is otherwise a plain, cacheable GET
     * response — {@see self::get_or_create()} remains the only path that
     * establishes a guest's identity, called only from the POST
     * toggle/record endpoints that actually need one to exist.
     */
    public function current(): ?string
    {
        $existing = $_COOKIE[ self::COOKIE_NAME ] ?? null;

        return is_string($existing) && $this->is_valid_token($existing) ? $existing : null;
    }

    /**
     * Whether a cookie value looks like a genuine UUID this class issued
     * — not a full validation of provenance, just a sanity check against
     * garbage/tampered cookie values before trusting it as a database key.
     *
     * @param string $token The cookie value to check.
     */
    private function is_valid_token(string $token): bool
    {
        return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $token);
    }
}
