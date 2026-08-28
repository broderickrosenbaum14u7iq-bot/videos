<?php
/**
 * Unit tests for VerificationTokenCrypto.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

namespace Tube_Members\Tests\Unit\Email;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Tube_Members\Email\VerificationTokenCrypto;

/**
 * Exercises VerificationTokenCrypto's pure token generation/hashing/
 * expiry logic — no WordPress, per that class's own docblock. This is
 * the actual security-critical surface of the 2026-08-27 email-
 * verification feature (Phase 23: expired/incorrect/wrong-user/replay/
 * malformed token handling); the WordPress-coupled storage/orchestration
 * one layer up ({@see \Tube_Members\Email\EmailVerificationService}) is
 * verified instead via live QA, the same split every other WP-coupled
 * class in this project already uses.
 */
final class VerificationTokenCryptoTest extends TestCase
{
    private const SECRET = 'test-secret-do-not-use-in-production';

    /**
     * Two generated tokens are never equal, and each is 64 hex characters
     * (32 bytes of entropy) — never derived from anything guessable.
     */
    public function test_generate_raw_token_is_unique_and_high_entropy(): void
    {
        $first  = VerificationTokenCrypto::generate_raw_token();
        $second = VerificationTokenCrypto::generate_raw_token();

        self::assertNotSame($first, $second);
        self::assertSame(64, strlen($first));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
    }

    /**
     * The exact raw token that produced a hash matches it; any other
     * value (a single bit flipped, an empty string, a token for a
     * "different user" in the sense of "just some other random value")
     * does not (Phase 23: "incorrect token", "token for wrong user").
     */
    public function test_matches_is_true_only_for_the_exact_raw_token(): void
    {
        $raw_token = VerificationTokenCrypto::generate_raw_token();
        $hash      = VerificationTokenCrypto::hash_token($raw_token, self::SECRET);

        self::assertTrue(VerificationTokenCrypto::matches($hash, $raw_token, self::SECRET));

        $other_token = VerificationTokenCrypto::generate_raw_token();
        self::assertFalse(VerificationTokenCrypto::matches($hash, $other_token, self::SECRET));

        $flipped = substr($raw_token, 0, -1) . ('0' === substr($raw_token, -1, 1) ? '1' : '0');
        self::assertFalse(VerificationTokenCrypto::matches($hash, $flipped, self::SECRET));
    }

    /**
     * A token hashed under a different secret never matches — the same
     * raw token replayed against a different site/install (or a
     * different HMAC key) must not verify (Phase 23: "token replay").
     */
    public function test_matches_is_false_for_a_different_secret(): void
    {
        $raw_token = VerificationTokenCrypto::generate_raw_token();
        $hash      = VerificationTokenCrypto::hash_token($raw_token, self::SECRET);

        self::assertFalse(VerificationTokenCrypto::matches($hash, $raw_token, 'a-different-secret'));
    }

    /**
     * Empty inputs (a missing token, a missing stored hash) never match
     * — Phase 23: "missing token" must be rejected, never silently
     * treated as "no token required."
     */
    public function test_matches_is_false_for_empty_inputs(): void
    {
        $raw_token = VerificationTokenCrypto::generate_raw_token();
        $hash      = VerificationTokenCrypto::hash_token($raw_token, self::SECRET);

        self::assertFalse(VerificationTokenCrypto::matches('', $raw_token, self::SECRET));
        self::assertFalse(VerificationTokenCrypto::matches($hash, '', self::SECRET));
        self::assertFalse(VerificationTokenCrypto::matches('', '', self::SECRET));
    }

    /**
     * A token expires exactly 24 hours after it was generated — not
     * before, not indefinitely after.
     */
    public function test_expiry_is_24_hours_out(): void
    {
        $now        = new DateTimeImmutable('2026-08-27 10:00:00', new DateTimeZone('UTC'));
        $expires_at = VerificationTokenCrypto::expires_at($now);

        self::assertSame('2026-08-28 10:00:00', $expires_at->format('Y-m-d H:i:s'));

        self::assertFalse(
            VerificationTokenCrypto::is_expired('2026-08-28 09:59:59', $now->modify('+23 hours 59 minutes 58 seconds'))
        );
        self::assertTrue(
            VerificationTokenCrypto::is_expired('2026-08-28 10:00:00', $now->modify('+24 hours 1 second'))
        );
    }

    /**
     * A malformed/empty stored expiry fails CLOSED (treated as already
     * expired) — the opposite of this project's usual fail-open
     * posture, since the failure mode here is "a token verifies past
     * its intended life," not "a visitor is inconvenienced."
     */
    public function test_malformed_expiry_is_treated_as_expired(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        self::assertTrue(VerificationTokenCrypto::is_expired('', $now));
        self::assertTrue(VerificationTokenCrypto::is_expired('not-a-date', $now));
    }
}
