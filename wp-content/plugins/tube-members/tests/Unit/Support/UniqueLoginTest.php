<?php
/**
 * Unit tests for UniqueLogin.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

namespace Tube_Members\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Tube_Members\Support\UniqueLogin;

/**
 * Exercises UniqueLogin::generate() against the bootstrap's
 * username_exists() stub (controlled here via FakeUsernameRegistry) —
 * the safe-internal-username derivation both RegistrationService (manual
 * sign-up) and GoogleOAuthController (a brand-new Google account) share.
 */
final class UniqueLoginTest extends TestCase
{
    /**
     * Reset the fake username registry so tests don't leak state into each other.
     */
    protected function tearDown(): void
    {
        FakeUsernameRegistry::$taken = [];
    }

    /**
     * The email's local part becomes the username when it's free.
     */
    public function test_derives_the_local_part_of_the_email(): void
    {
        self::assertSame('minhtu', UniqueLogin::generate('minhtu@example.com'));
    }

    /**
     * The local part is lowercased.
     */
    public function test_lowercases_the_local_part(): void
    {
        self::assertSame('minhtu', UniqueLogin::generate('MinhTu@example.com'));
    }

    /**
     * A taken base username gets a random numeric suffix appended.
     */
    public function test_appends_a_random_suffix_when_the_base_is_taken(): void
    {
        FakeUsernameRegistry::$taken = ['minhtu'];

        $login = UniqueLogin::generate('minhtu@example.com');

        self::assertNotSame('minhtu', $login);
        self::assertStringStartsWith('minhtu', $login);
    }

    /**
     * An email whose local part sanitizes away to nothing falls back to the display name.
     */
    public function test_falls_back_to_the_display_name_when_the_local_part_sanitizes_away(): void
    {
        // A local part that is entirely non-username-safe characters
        // (sanitize_user() strips it to '') must fall back to the
        // display name, never register a blank username. Deliberately an
        // ASCII display name here: real WordPress's sanitize_title()
        // transliterates Vietnamese diacritics via remove_accents()
        // before slugifying, which this Unit suite's lightweight stub
        // (see tests/bootstrap.php) does not reproduce — that behavior is
        // WordPress core's own, not this class's, and is covered instead
        // by this session's live registration/OAuth verification.
        self::assertSame('new-member', UniqueLogin::generate('@@@@@@@.com', 'New Member'));
    }

    /**
     * When both the email and the display name sanitize away, the hardcoded 'member' base is used.
     */
    public function test_falls_back_to_member_when_both_email_and_display_name_sanitize_away(): void
    {
        self::assertSame('member', UniqueLogin::generate('@@@@@@@.com', '@@@'));
    }

    /**
     * The generated login is never one already reported as taken.
     */
    public function test_never_returns_an_already_taken_username(): void
    {
        FakeUsernameRegistry::$taken = ['minhtu'];

        $login = UniqueLogin::generate('minhtu@example.com');

        self::assertNotContains($login, FakeUsernameRegistry::$taken);
    }
}
