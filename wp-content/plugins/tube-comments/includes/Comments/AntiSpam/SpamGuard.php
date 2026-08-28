<?php
/**
 * Enforces every anti-spam rule around comment/reply creation.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

namespace Tube_Comments\Comments\AntiSpam;

use Tube_Comments\Comments\Repositories\CommentRepository;
use Tube_Comments\Comments\Repositories\CommentRootLockRepository;
use Tube_Comments\Comments\ValidationException;

/**
 * Enforces every anti-spam rule around comment/reply creation — the
 * single place `Tube_Comments\Comments\CommentService::create()` asks
 * "is this allowed?" before ever inserting a row. Every threshold this
 * class checks against lives in `SpamPolicy`; every text comparison it
 * needs is delegated to the WordPress-free `ContentNormalizer`.
 *
 * Administrators and moderators (anyone holding the `moderate_comments`
 * capability — the same one `Tube_Comments\Admin\ModerationScreen`
 * already gates its whole screen on) bypass every rule in this class
 * outright, per the project's "moderators may bypass anti-spam limits
 * for QA/moderation" requirement — checked via a real WordPress
 * capability, never a hardcoded user ID/username.
 */
final class SpamGuard
{
    /**
     * Construct around the collaborators this guard reads/writes anti-spam state through.
     *
     * @param CommentRepository         $comments   Read access for count/recent-content checks.
     * @param CommentRootLockRepository $root_locks The atomic one-root-per-video-per-24h slot table.
     */
    public function __construct(
        private readonly CommentRepository $comments,
        private readonly CommentRootLockRepository $root_locks
    ) {
    }

    /**
     * Run every anti-spam check for a new comment/reply by $user_id on
     * $video_id, given its already-`ContentSanitizer`-sanitized
     * $content. Does nothing (returns normally) if every check passes;
     * the caller may then insert the row.
     *
     * Order is deliberate: the ONLY check with a side effect (acquiring
     * the root-comment slot) runs last, so a request that will be
     * rejected for any other reason (quality, duplicate, flood, daily
     * cap, reply limits) never consumes that slot first.
     *
     * @param int    $user_id  The authenticated commenter.
     * @param int    $video_id The video being commented on.
     * @param string $content  Already-sanitized comment content.
     * @param bool   $is_root  True for a new root comment; false for a reply.
     *
     * @throws ValidationException If $content fails the minimum-quality bar.
     * @throws SpamLimitException  If any rate/duplicate/flood/daily-cap rule blocks this creation.
     */
    public function guard(int $user_id, int $video_id, string $content, bool $is_root): void
    {
        if (current_user_can('moderate_comments')) {
            return;
        }

        if (! ContentNormalizer::has_minimum_quality($content)) {
            throw new ValidationException(
                'Bình luận cần có ít nhất vài ký tự có ý nghĩa, không thể chỉ là dấu câu hoặc khoảng trắng.'
            );
        }

        $is_new_account = AccountAge::is_new($user_id, SpamPolicy::NEW_ACCOUNT_AGE_SECONDS);
        $day_cutoff     = $this->cutoff(SpamPolicy::ROLLING_WINDOW_SECONDS);

        $this->assert_not_duplicate_or_flood($user_id, $content, $day_cutoff);

        $daily_cap = $is_new_account
            ? SpamPolicy::NEW_ACCOUNT_MAX_TOTAL_ACTIONS_PER_DAY
            : SpamPolicy::MAX_TOTAL_ACTIONS_PER_DAY;

        if ($this->comments->count_actions_since($user_id, $day_cutoff) >= $daily_cap) {
            throw new SpamLimitException(
                'tube_comment_daily_limit',
                'Bạn đã đạt giới hạn bình luận trong ngày hôm nay. Vui lòng quay lại sau.'
            );
        }

        if ($is_root) {
            $this->guard_root_comment($user_id, $video_id);

            return;
        }

        $this->guard_reply($user_id, $video_id, $is_new_account, $day_cutoff);
    }

    /**
     * The publish-vs-pending status a new comment should be created
     * with, per the link-spam heuristic — a normal account's threshold
     * is `SpamPolicy::LINK_THRESHOLD_NORMAL` links; a brand-new
     * account's is the stricter `SpamPolicy::NEW_ACCOUNT_LINK_THRESHOLD`.
     * Bypassed (always `published`) for `moderate_comments` holders, the
     * same exemption {@see self::guard()} grants.
     *
     * @param int    $user_id The authenticated commenter.
     * @param string $content Already-sanitized comment content.
     *
     * @return 'published'|'pending'
     */
    public function initial_status(int $user_id, string $content): string
    {
        if (current_user_can('moderate_comments')) {
            return 'published';
        }

        $threshold = AccountAge::is_new($user_id, SpamPolicy::NEW_ACCOUNT_AGE_SECONDS)
            ? SpamPolicy::NEW_ACCOUNT_LINK_THRESHOLD
            : SpamPolicy::LINK_THRESHOLD_NORMAL;

        return ContentNormalizer::external_link_count($content) >= $threshold ? 'pending' : 'published';
    }

    /**
     * The one-root-comment-per-video-per-24h gate — atomically acquires
     * the slot via `CommentRootLockRepository::try_acquire()`, which is
     * this method's only side effect.
     *
     * @param int $user_id  The authenticated commenter.
     * @param int $video_id The video being commented on.
     *
     * @throws SpamLimitException If this user already holds an active slot for this video.
     */
    private function guard_root_comment(int $user_id, int $video_id): void
    {
        if ($this->root_locks->try_acquire($user_id, $video_id, SpamPolicy::ROOT_COMMENT_WINDOW_SECONDS)) {
            return;
        }

        $window       = SpamPolicy::ROOT_COMMENT_WINDOW_SECONDS;
        $available_at = $this->root_locks->available_at($user_id, $video_id, $window);

        throw new SpamLimitException(
            'tube_comment_video_daily_limit',
            'Bạn đã bình luận video này. Bạn có thể bình luận lại sau 24 giờ.',
            $available_at // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $available_at is a server-computed ISO 8601 timestamp (never user input), consumed only as a JSON response field, never echoed as HTML.
        );
    }

    /**
     * Reply-specific limits: the account-wide burst cap, then either the
     * new-account account-wide daily cap or the normal per-video daily cap.
     *
     * @param int    $user_id        The authenticated commenter.
     * @param int    $video_id       The video being replied on.
     * @param bool   $is_new_account Whether this account is younger than `SpamPolicy::NEW_ACCOUNT_AGE_SECONDS`.
     * @param string $day_cutoff     UTC 'Y-m-d H:i:s' — the start of the rolling
     *                               24-hour window already computed by the caller.
     *
     * @throws SpamLimitException If any reply limit is exceeded.
     */
    private function guard_reply(int $user_id, int $video_id, bool $is_new_account, string $day_cutoff): void
    {
        $burst_cutoff = $this->cutoff(SpamPolicy::REPLY_BURST_WINDOW_SECONDS);

        if ($this->comments->count_replies_since($user_id, $burst_cutoff) >= SpamPolicy::MAX_REPLIES_PER_BURST) {
            throw new SpamLimitException(
                'tube_comment_reply_burst_limit',
                'Bạn đang trả lời quá nhanh. Vui lòng thử lại sau ít phút.'
            );
        }

        if ($is_new_account) {
            $replies_today = $this->comments->count_replies_since($user_id, $day_cutoff);

            if ($replies_today >= SpamPolicy::NEW_ACCOUNT_MAX_REPLIES_PER_DAY) {
                $max_daily_replies = SpamPolicy::NEW_ACCOUNT_MAX_REPLIES_PER_DAY;
                $message           = 'Tài khoản mới chỉ có thể trả lời tối đa ' . $max_daily_replies
                    . ' lần mỗi ngày. Vui lòng quay lại sau.';

                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $message is built above from a hardcoded Vietnamese literal plus an int constant, never user input, and only ever consumed as a JSON response field, never echoed as HTML.
                throw new SpamLimitException('tube_comment_reply_daily_limit', $message);
            }

            return;
        }

        $replies_for_video_today = $this->comments->count_replies_for_video_since($user_id, $video_id, $day_cutoff);

        if ($replies_for_video_today >= SpamPolicy::MAX_REPLIES_PER_VIDEO_PER_DAY) {
            throw new SpamLimitException(
                'tube_comment_reply_video_daily_limit',
                'Bạn đã trả lời quá nhiều trong video này hôm nay. Vui lòng thử lại sau.'
            );
        }
    }

    /**
     * Exact-duplicate and near-duplicate/flood detection against this
     * user's own recent comments (any video, any status — see
     * `CommentRepository::recent_content_since()`'s own docblock for why
     * this stays a small, bounded fetch regardless of table size).
     *
     * @param int    $user_id    The authenticated commenter.
     * @param string $content    Already-sanitized comment content.
     * @param string $day_cutoff UTC 'Y-m-d H:i:s' — the start of the rolling 24-hour lookback window.
     *
     * @throws SpamLimitException If $content exactly duplicates a recent
     *                            comment, or near-duplicates the immediately
     *                            preceding one within the flood window.
     */
    private function assert_not_duplicate_or_flood(int $user_id, string $content, string $day_cutoff): void
    {
        $lookback = SpamPolicy::DUPLICATE_CHECK_LOOKBACK;
        $recent   = $this->comments->recent_content_since($user_id, $day_cutoff, $lookback);

        if ([] === $recent) {
            return;
        }

        $normalized_new = ContentNormalizer::for_exact_duplicate($content);

        foreach ($recent as $row) {
            $normalized_existing = ContentNormalizer::for_exact_duplicate($row['content']);

            if ($normalized_existing !== $normalized_new) {
                continue;
            }

            $matched_timestamp = strtotime($row['created_at'] . ' UTC');
            $available_at      = false !== $matched_timestamp
                ? gmdate('c', $matched_timestamp + SpamPolicy::ROLLING_WINDOW_SECONDS)
                : null;

            throw new SpamLimitException(
                'tube_comment_duplicate_content',
                'Bạn đã đăng nội dung này rồi. Vui lòng viết một bình luận khác.',
                $available_at // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $available_at is a server-computed ISO 8601 timestamp (never user input), consumed only as a JSON response field, never echoed as HTML.
            );
        }

        // recent_content_since() orders newest-first (id DESC), so index 0
        // is this user's single most recent comment/reply -- the only one
        // the flood window's short lookback should ever compare against.
        $most_recent           = $recent[0];
        $most_recent_timestamp = strtotime($most_recent['created_at'] . ' UTC');

        if (false === $most_recent_timestamp) {
            return;
        }

        $elapsed = time() - $most_recent_timestamp;

        if ($elapsed >= SpamPolicy::FLOOD_WINDOW_SECONDS) {
            return;
        }

        $normalized_recent = ContentNormalizer::for_flood_comparison($most_recent['content']);
        $normalized_flood  = ContentNormalizer::for_flood_comparison($content);

        if ($normalized_recent !== $normalized_flood) {
            return;
        }

        $retry_after  = max(1, SpamPolicy::FLOOD_WINDOW_SECONDS - $elapsed);
        $available_at = gmdate('c', time() + $retry_after);

        throw new SpamLimitException(
            'tube_comment_flood_detected',
            'Bạn đang gửi bình luận quá nhanh. Vui lòng chờ một chút rồi thử lại.',
            $available_at // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $available_at is a server-computed ISO 8601 timestamp (never user input), consumed only as a JSON response field, never echoed as HTML.
        );
    }

    /**
     * UTC 'Y-m-d H:i:s' for "now minus $window_seconds" — the shared
     * cutoff-computation every rolling-window check in this class needs.
     *
     * @param int $window_seconds The window length, in seconds.
     */
    private function cutoff(int $window_seconds): string
    {
        return gmdate('Y-m-d H:i:s', time() - $window_seconds);
    }
}
