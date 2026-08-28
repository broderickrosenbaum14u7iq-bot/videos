<?php
/**
 * Configurable anti-spam thresholds — the single source of truth for
 * every number SpamGuard enforces.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

namespace Tube_Comments\Comments\AntiSpam;

/**
 * Every anti-spam threshold `SpamGuard` enforces, gathered in one place
 * rather than scattered as magic numbers across the guard/repository
 * classes — the same "one class, one job" posture
 * `Tube_Comments\Http\CommentCreateController`'s own per-minute/per-hour
 * constants already follow, just promoted here since these numbers are
 * now read from three different classes (`SpamGuard`,
 * `CommentRepository`, `ContentNormalizer`) instead of one controller.
 *
 * Plain integer literals throughout — deliberately never WordPress core
 * constants like `DAY_IN_SECONDS` (those are undefined in the Unit test
 * suite, which never bootstraps WordPress; this class is required by
 * pure-logic classes `ContentNormalizer` depends on, so it must stay
 * loadable with zero WordPress runtime, the same constraint
 * `Tube_Comments\Support\RedisRateLimiter`'s own literal `60`/`3600`
 * constants already satisfy).
 */
final class SpamPolicy
{
    /**
     * A normal member may create at most one ROOT comment (parent_id is
     * null) per video within this rolling window, measured from the
     * moment their last root comment on that video was created —
     * enforced atomically by `CommentRootLockRepository`, never by a
     * plain SELECT-then-INSERT.
     */
    public const ROOT_COMMENT_WINDOW_SECONDS = 86400;

    /**
     * A normal member's maximum replies on one video within a rolling
     * 24-hour window (video-scoped; see NEW_ACCOUNT_MAX_REPLIES_PER_DAY
     * for the stricter, account-wide new-account variant).
     */
    public const MAX_REPLIES_PER_VIDEO_PER_DAY = 10;

    /**
     * A member's maximum replies (any video) within REPLY_BURST_WINDOW_SECONDS.
     */
    public const MAX_REPLIES_PER_BURST = 5;

    /**
     * The rolling window MAX_REPLIES_PER_BURST is measured over, in seconds (10 minutes).
     */
    public const REPLY_BURST_WINDOW_SECONDS = 600;

    /**
     * A normal member's maximum total comment-creation actions (root
     * comments + replies, any video) within a rolling 24-hour window —
     * the account-wide ceiling that stops someone from spreading spam
     * across many different videos instead of one.
     */
    public const MAX_TOTAL_ACTIONS_PER_DAY = 30;

    /**
     * The rolling window every 24-hour rule in this class shares
     * (the global daily cap, the duplicate-content lookback, and the
     * per-video reply cap all measure "the last 24 hours", just scoped
     * differently) — one named constant so all three stay in lockstep.
     */
    public const ROLLING_WINDOW_SECONDS = 86400;

    /**
     * An account younger than this is held to the stricter
     * NEW_ACCOUNT_* limits below instead of the normal ones. Age is
     * measured from `WP_User::$user_registered`.
     */
    public const NEW_ACCOUNT_AGE_SECONDS = 86400;

    /**
     * A brand-new account's maximum replies TOTAL (account-wide, not
     * per-video — deliberately a different shape than
     * MAX_REPLIES_PER_VIDEO_PER_DAY) within a rolling 24-hour window.
     */
    public const NEW_ACCOUNT_MAX_REPLIES_PER_DAY = 5;

    /**
     * A brand-new account's maximum total comment-creation actions
     * (root + replies) within a rolling 24-hour window.
     */
    public const NEW_ACCOUNT_MAX_TOTAL_ACTIONS_PER_DAY = 10;

    /**
     * A comment containing this many external links or more is held for
     * moderation (`status = 'pending'`) instead of publishing
     * immediately — a normal account's threshold.
     */
    public const LINK_THRESHOLD_NORMAL = 2;

    /**
     * A brand-new account's link threshold — stricter: even a single
     * external link goes to moderation.
     */
    public const NEW_ACCOUNT_LINK_THRESHOLD = 1;

    /**
     * A comment must contain at least this many meaningful Unicode
     * letters/digits (punctuation, whitespace, and emoji do not count)
     * to be accepted — see `ContentNormalizer::has_minimum_quality()`
     * for the exact rule and its rationale.
     */
    public const MIN_MEANINGFUL_CHARS = 2;

    /**
     * How many of a user's own most-recent comments/replies (any video,
     * any status) are fetched to check for an exact duplicate — a small,
     * fixed number, not a full history scan (see
     * `CommentRepository::recent_content_since()`'s own docblock for why
     * this stays index-friendly regardless of table size).
     */
    public const DUPLICATE_CHECK_LOOKBACK = 20;

    /**
     * Two near-identical comments (see
     * `ContentNormalizer::for_flood_comparison()`) from the same account
     * posted less than this many seconds apart are treated as a flood
     * attempt, not two genuine thoughts — the "hay quá" / "hay quá!" /
     * "hay quá!!" case from a fast typist or a bot, not a coincidence.
     */
    public const FLOOD_WINDOW_SECONDS = 20;

    /**
     * No instances — every member is a named constant.
     */
    private function __construct()
    {
    }
}
