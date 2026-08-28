<?php
/**
 * Unit tests for SpamPolicy.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

namespace Tube_Comments\Tests\Unit\Comments\AntiSpam;

use PHPUnit\Framework\TestCase;
use Tube_Comments\Comments\AntiSpam\SpamPolicy;

/**
 * Guards the relationships between SpamPolicy's constants that must
 * always hold, regardless of what the actual numbers are tuned to —
 * catches an accidental future edit that makes the "new account" limits
 * looser than the normal ones, which would defeat their purpose.
 */
final class SpamPolicyTest extends TestCase
{
    /**
     * A brand-new account's total-actions cap is never looser than a normal account's.
     */
    public function test_new_account_daily_action_cap_is_not_looser_than_normal(): void
    {
        self::assertLessThanOrEqual(
            SpamPolicy::MAX_TOTAL_ACTIONS_PER_DAY,
            SpamPolicy::NEW_ACCOUNT_MAX_TOTAL_ACTIONS_PER_DAY
        );
    }

    /**
     * A brand-new account's link-spam threshold is never looser than a normal account's.
     */
    public function test_new_account_link_threshold_is_not_looser_than_normal(): void
    {
        self::assertLessThanOrEqual(SpamPolicy::LINK_THRESHOLD_NORMAL, SpamPolicy::NEW_ACCOUNT_LINK_THRESHOLD);
    }

    /**
     * Every threshold is a positive number of seconds/items -- a zero or
     * negative value would disable the rule entirely.
     */
    public function test_every_window_and_cap_is_positive(): void
    {
        self::assertGreaterThan(0, SpamPolicy::ROOT_COMMENT_WINDOW_SECONDS);
        self::assertGreaterThan(0, SpamPolicy::MAX_REPLIES_PER_VIDEO_PER_DAY);
        self::assertGreaterThan(0, SpamPolicy::MAX_REPLIES_PER_BURST);
        self::assertGreaterThan(0, SpamPolicy::REPLY_BURST_WINDOW_SECONDS);
        self::assertGreaterThan(0, SpamPolicy::MAX_TOTAL_ACTIONS_PER_DAY);
        self::assertGreaterThan(0, SpamPolicy::ROLLING_WINDOW_SECONDS);
        self::assertGreaterThan(0, SpamPolicy::NEW_ACCOUNT_AGE_SECONDS);
        self::assertGreaterThan(0, SpamPolicy::NEW_ACCOUNT_MAX_REPLIES_PER_DAY);
        self::assertGreaterThan(0, SpamPolicy::NEW_ACCOUNT_MAX_TOTAL_ACTIONS_PER_DAY);
        self::assertGreaterThan(0, SpamPolicy::LINK_THRESHOLD_NORMAL);
        self::assertGreaterThan(0, SpamPolicy::NEW_ACCOUNT_LINK_THRESHOLD);
        self::assertGreaterThan(0, SpamPolicy::MIN_MEANINGFUL_CHARS);
        self::assertGreaterThan(0, SpamPolicy::DUPLICATE_CHECK_LOOKBACK);
        self::assertGreaterThan(0, SpamPolicy::FLOOD_WINDOW_SECONDS);
    }
}
