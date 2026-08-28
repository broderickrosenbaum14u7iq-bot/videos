<?php
/**
 * Owns email-verification state: generation, storage, checking, sending.
 *
 * @package Tube_Members
 */

declare(strict_types=1);

namespace Tube_Members\Email;

use DateTimeImmutable;
use Tube_Members\Routing\EmailVerificationRouting;
use Tube_Members\Support\Params;
use WP_User;

/**
 * Owns email-verification state — the WordPress-coupled orchestration
 * layer around {@see VerificationTokenCrypto}'s pure primitives (per
 * that class's own docblock for why the split exists). Every piece of
 * state lives in `wp_usermeta`, never a new table (2026-08-27
 * email-verification task, Phase 2).
 *
 * Storage keys:
 * - `tube_email_verified` ('1'/'0'; ABSENT means "existing account from
 *   before this feature," see {@see self::is_verified()}'s own
 *   docblock for the exact backward-compatibility rule).
 * - `tube_email_verification_token_hash` (an HMAC, never the raw token).
 * - `tube_email_verification_expires_at` (`Y-m-d H:i:s`, UTC).
 *
 * Both token fields are deleted the instant they're no longer needed
 * (verified, or replaced by a fresh resend) — Phase 36: never
 * accumulate old tokens.
 */
final class EmailVerificationService
{
    private const META_VERIFIED   = 'tube_email_verified';
    private const META_TOKEN_HASH = 'tube_email_verification_token_hash';
    private const META_EXPIRES_AT = 'tube_email_verification_expires_at';

    /**
     * This feature's own launch instant (UTC) — an account registered
     * before this moment could never have gone through a verification
     * flow that didn't exist yet, so it is grandfathered into "verified"
     * the moment its own `tube_email_verified` meta is read for the
     * first time and found absent (2026-08-26 policy: "Existing users
     * ... should NOT suddenly lose commenting access"). A BRAND NEW
     * registration always writes an explicit '0' at creation time (see
     * {@see self::mark_unverified_new_registration()}), so "meta
     * absent" can only ever mean "predates this feature," never "a new
     * unverified user this check forgot to consider" — no backfill
     * script needed, and none can be missed for an account created
     * between a future deploy and a backfill job that never ran.
     */
    private const FEATURE_LAUNCHED_AT = '2026-08-27 00:00:00';

    /**
     * Construct around the collaborator this service sends mail through.
     *
     * @param VerificationEmailSender $mailer Builds and sends the verification email.
     */
    public function __construct(private readonly VerificationEmailSender $mailer)
    {
    }

    /**
     * Whether $user may comment/reply/report right now.
     *
     * Order: a capability bypass (any role above Subscriber — Editor,
     * Administrator — the same `edit_posts` dividing line
     * `Tube_Members\Capability\MemberRoleGuard` already uses, per Phase
     * 22: "Use capabilities. Do NOT hardcode username/email/user IDs"),
     * then the explicit stored flag, then the grandfather clause above.
     *
     * @param WP_User $user The account to check.
     */
    public function is_verified(WP_User $user): bool
    {
        if (user_can($user, 'edit_posts')) {
            return true;
        }

        $raw = get_user_meta($user->ID, self::META_VERIFIED, true);

        if ('1' === $raw) {
            return true;
        }

        if ('0' === $raw) {
            return false;
        }

        // Meta genuinely absent (never written for this account) --
        // grandfather clause, see FEATURE_LAUNCHED_AT's own docblock.
        $registered = strtotime(Params::string($user->user_registered));

        return false !== $registered && $registered < strtotime(self::FEATURE_LAUNCHED_AT);
    }

    /**
     * Explicitly mark a brand-new manual registration unverified —
     * called once, right after account creation, so this account's
     * `is_verified()` check is governed by the real flag from the
     * start rather than ever falling through to the grandfather clause
     * (which only applies to accounts with NO meta at all).
     *
     * @param int $user_id The just-created account.
     */
    public function mark_unverified_new_registration(int $user_id): void
    {
        update_user_meta($user_id, self::META_VERIFIED, '0');
    }

    /**
     * Mark an account verified via a trusted, non-token path — Google
     * OAuth, whose own caller only ever invokes this once Google's
     * `email_verified` claim has already been checked true (Phase 21:
     * "should not require redundant verification"). Clears any pending
     * token the same way a link-click success does, so a manual
     * verification email sent earlier (e.g. before linking Google)
     * can't be used to re-verify anything after the fact.
     *
     * @param int $user_id The account Google just vouched for.
     */
    public function mark_verified_from_trusted_provider(int $user_id): void
    {
        $this->mark_verified($user_id);
    }

    /**
     * Generate a fresh token, store its hash + a new 24h expiry
     * (overwriting/invalidating whatever was there before -- Phase 17:
     * "Do not reuse old raw tokens" / Phase 36: resend must not let the
     * old link keep working), and send the verification email.
     *
     * Never throws: a `wp_mail()` failure is reported back as `false`
     * for the caller to surface a "couldn't send, try again" notice
     * (Phase 4) — the token itself is still stored either way, so a
     * later resend attempt (or, in WP_DEBUG dev QA, the logged link)
     * still works.
     *
     * @param WP_User $user The account to (re)send a verification link to.
     */
    public function send_new_verification_email(WP_User $user): bool
    {
        $raw_token  = VerificationTokenCrypto::generate_raw_token();
        $now        = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $expires_at = VerificationTokenCrypto::expires_at($now);

        $token_hash = VerificationTokenCrypto::hash_token($raw_token, $this->secret());

        update_user_meta($user->ID, self::META_TOKEN_HASH, $token_hash);
        update_user_meta($user->ID, self::META_EXPIRES_AT, $expires_at->format('Y-m-d H:i:s'));

        $url = EmailVerificationRouting::url($user->ID, $raw_token);

        return $this->mailer->send($user, $url);
    }

    /**
     * Attempt to verify $user_id with $raw_token — the one authoritative
     * check the verification link's landing page runs.
     *
     * @param int    $user_id  The `uid` query param from the verification link.
     * @param string $raw_token The `token` query param from the verification link.
     */
    public function verify(int $user_id, string $raw_token): VerificationResult
    {
        $user = get_userdata($user_id);

        if (false === $user) {
            return VerificationResult::UserNotFound;
        }

        // Checked BEFORE the token itself: a successful verification
        // deletes the token meta (Phase 36), so a repeat click on the
        // same emailed link must say "already verified," never
        // "invalid" -- Phase 7's own explicit requirement.
        if ($this->is_verified($user) && '' === $raw_token) {
            return VerificationResult::AlreadyVerified;
        }

        $stored_hash = Params::string(get_user_meta($user_id, self::META_TOKEN_HASH, true));

        if ('' === $stored_hash) {
            return $this->is_verified($user) ? VerificationResult::AlreadyVerified : VerificationResult::InvalidToken;
        }

        $expires_at = Params::string(get_user_meta($user_id, self::META_EXPIRES_AT, true));
        $now        = new DateTimeImmutable('now', new \DateTimeZone('UTC'));

        if (VerificationTokenCrypto::is_expired($expires_at, $now)) {
            return VerificationResult::ExpiredToken;
        }

        if (! VerificationTokenCrypto::matches($stored_hash, $raw_token, $this->secret())) {
            return VerificationResult::InvalidToken;
        }

        $this->mark_verified($user_id);

        return VerificationResult::Verified;
    }

    /**
     * Mark verified and delete the (now one-time-used) token — Phase 36:
     * "When verification succeeds: delete token hash, delete expiry.
     * Keep email_verified = true."
     *
     * @param int $user_id The account being marked verified.
     */
    private function mark_verified(int $user_id): void
    {
        update_user_meta($user_id, self::META_VERIFIED, '1');
        delete_user_meta($user_id, self::META_TOKEN_HASH);
        delete_user_meta($user_id, self::META_EXPIRES_AT);
    }

    /**
     * The site-specific secret every token hash is keyed with — a real
     * WordPress salt (already unique per install, already never
     * transmitted anywhere), not a value this plugin invents or stores
     * itself.
     */
    private function secret(): string
    {
        return wp_salt('auth');
    }
}
