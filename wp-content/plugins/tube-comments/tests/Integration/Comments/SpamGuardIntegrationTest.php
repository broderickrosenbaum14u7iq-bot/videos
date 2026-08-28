<?php
/**
 * Integration tests for SpamGuard, through CommentService::create(), against a real database.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

namespace Tube_Comments\Tests\Integration\Comments;

use PHPUnit\Framework\TestCase;
use Tube_Comments\Comments\AntiSpam\SpamGuard;
use Tube_Comments\Comments\AntiSpam\SpamLimitException;
use Tube_Comments\Comments\AntiSpam\SpamPolicy;
use Tube_Comments\Comments\CommentService;
use Tube_Comments\Comments\Repositories\CommentCounterRepository;
use Tube_Comments\Comments\Repositories\CommentRepository;
use Tube_Comments\Comments\Repositories\CommentRootLockRepository;
use Tube_Comments\Comments\ValidationException;
use Tube_Comments\Support\Params;

/**
 * Exercises every anti-spam rule end-to-end through the real
 * `CommentService::create()` call path (real `$wpdb`, real
 * `wp_tube_comments`/`wp_tube_comment_root_locks` rows, real `WP_User`
 * accounts) — the behaviors the plain Unit suite cannot cover, per
 * `phpunit.xml.dist`'s own docblock.
 *
 * Several tests insert rows directly via `CommentRepository::insert()`
 * (bypassing `SpamGuard` entirely) to set up "N prior actions already
 * exist" preconditions without those setup calls themselves tripping an
 * unrelated rule (e.g. seeding the per-video reply cap without first
 * tripping the account-wide burst cap) — this mirrors how a real account
 * would look after some already-permitted activity, not a shortcut
 * around the guard's own logic.
 */
final class SpamGuardIntegrationTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var CommentService
     */
    private CommentService $service;

    /**
     * Direct repository access for seeding preconditions and inspecting rows.
     *
     * @var CommentRepository
     */
    private CommentRepository $comments;

    /**
     * Direct access to the root-lock table, for seeding/inspecting rows.
     *
     * @var CommentRootLockRepository
     */
    private CommentRootLockRepository $root_locks;

    /**
     * A real, normal-age (backdated) member account.
     *
     * @var int
     */
    private int $user_id;

    /**
     * A real video post.
     *
     * @var int
     */
    private int $video_id;

    /**
     * Every user ID created across all tests, for teardown.
     *
     * @var list<int>
     */
    private array $created_user_ids = [];

    /**
     * Every video post ID created across all tests, for teardown.
     *
     * @var list<int>
     */
    private array $created_video_ids = [];

    /**
     * Build a real service and a real, normal-age member + video for each test.
     */
    protected function setUp(): void
    {
        $this->comments   = new CommentRepository();
        $this->root_locks = new CommentRootLockRepository();
        $this->service    = new CommentService(
            $this->comments,
            new CommentCounterRepository(),
            new SpamGuard($this->comments, $this->root_locks)
        );

        $this->user_id  = $this->create_member(90000);
        $this->video_id = $this->create_video();
    }

    /**
     * Delete every row/user/post this test file created.
     */
    protected function tearDown(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
        $comments_table   = $wpdb->prefix . 'tube_comments';
        $root_locks_table = $wpdb->prefix . 'tube_comment_root_locks';
        $counters_table   = $wpdb->prefix . 'tube_comment_counters';

        foreach ($this->created_user_ids as $user_id) {
            $wpdb->delete($comments_table, ['user_id' => $user_id], ['%d']);
            $wpdb->delete($root_locks_table, ['user_id' => $user_id], ['%d']);
            wp_delete_user($user_id);
        }

        foreach ($this->created_video_ids as $video_id) {
            $wpdb->delete($comments_table, ['video_id' => $video_id], ['%d']);
            $wpdb->delete($root_locks_table, ['video_id' => $video_id], ['%d']);
            $wpdb->delete($counters_table, ['video_id' => $video_id], ['%d']);
            wp_delete_post($video_id, true);
        }
    }

    /**
     * A normal account's first root comment on a video succeeds and is published.
     */
    public function test_first_root_comment_succeeds(): void
    {
        $row = $this->service->create($this->video_id, $this->user_id, 'Video hay quá, cảm ơn bạn!', null);

        self::assertSame('published', $row['status']);
        self::assertNull($row['parent_id']);
    }

    /**
     * A second root comment on the SAME video, still inside the 24-hour window, is blocked.
     *
     * @throws SpamLimitException Re-thrown after asserting its code, so PHPUnit's expectException() still observes it.
     */
    public function test_second_root_comment_same_video_within_24h_is_blocked(): void
    {
        $this->service->create($this->video_id, $this->user_id, 'Bình luận đầu tiên của tôi.', null);

        $this->expectException(SpamLimitException::class);

        try {
            $this->service->create($this->video_id, $this->user_id, 'Một bình luận hoàn toàn khác biệt ở đây.', null);
        } catch (SpamLimitException $exception) {
            self::assertSame('tube_comment_video_daily_limit', $exception->code());

            throw $exception;
        }
    }

    /**
     * The same user may still comment on a DIFFERENT video after using up their slot on the first one.
     */
    public function test_same_user_can_comment_a_different_video(): void
    {
        $this->service->create($this->video_id, $this->user_id, 'Bình luận trên video thứ nhất.', null);

        $other_video_id = $this->create_video();

        $row = $this->service->create($other_video_id, $this->user_id, 'Bình luận trên video thứ hai.', null);

        self::assertSame('published', $row['status']);
    }

    /**
     * A DIFFERENT user may comment on the same video the first user already used their slot on.
     */
    public function test_a_different_user_can_comment_the_same_video(): void
    {
        $this->service->create($this->video_id, $this->user_id, 'Bình luận của người dùng thứ nhất.', null);

        $other_user_id = $this->create_member(90000);

        $row = $this->service->create($this->video_id, $other_user_id, 'Bình luận của người dùng thứ hai.', null);

        self::assertSame('published', $row['status']);
    }

    /**
     * Once the 24-hour window has elapsed (simulated by backdating the lock row), a new root comment succeeds.
     */
    public function test_root_comment_allowed_again_after_24_hours(): void
    {
        $this->service->create($this->video_id, $this->user_id, 'Bình luận đầu tiên của tôi.', null);

        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
        $wpdb->update(
            $wpdb->prefix . 'tube_comment_root_locks',
            ['created_at' => gmdate('Y-m-d H:i:s', time() - 90000)],
            [
                'user_id'  => $this->user_id,
                'video_id' => $this->video_id,
            ],
            ['%s'],
            ['%d', '%d']
        );

        $row = $this->service->create($this->video_id, $this->user_id, 'Bình luận thứ hai sau 24 giờ.', null);

        self::assertSame('published', $row['status']);
    }

    /**
     * A reply is still allowed on a video even after that user's root-comment slot for it has been used.
     */
    public function test_reply_allowed_after_root_comment_limit_reached(): void
    {
        $root    = $this->service->create($this->video_id, $this->user_id, 'Bình luận gốc của tôi.', null);
        $root_id = Params::int($root['id']);

        $reply = $this->service->create($this->video_id, $this->user_id, 'Đây là câu trả lời của tôi.', $root_id);

        self::assertSame('published', $reply['status']);
        self::assertSame($root_id, Params::int($reply['parent_id']));
    }

    /**
     * A normal account's 11th reply on one video within 24 hours is blocked, once 10 already exist.
     *
     * @throws SpamLimitException Re-thrown after asserting its code, so PHPUnit's expectException() still observes it.
     */
    public function test_reply_per_video_daily_limit_blocks_the_11th_reply(): void
    {
        $root_id = $this->seed_root_comment();

        // Backdated past the 10-minute burst window but still inside the
        // 24-hour daily window, so this seeds ONLY the per-video daily
        // cap without also tripping the account-wide burst cap.
        for ($i = 1; $i <= SpamPolicy::MAX_REPLIES_PER_VIDEO_PER_DAY; $i++) {
            $this->seed_reply($root_id, "Câu trả lời đã có sẵn số {$i}.", 700);
        }

        $this->expectException(SpamLimitException::class);

        try {
            $this->service->create($this->video_id, $this->user_id, 'Câu trả lời thứ mười một hoàn toàn mới.', $root_id);
        } catch (SpamLimitException $exception) {
            self::assertSame('tube_comment_reply_video_daily_limit', $exception->code());

            throw $exception;
        }
    }

    /**
     * A normal account's 6th reply within 10 minutes (any video) is blocked, once 5 already exist.
     *
     * @throws SpamLimitException Re-thrown after asserting its code, so PHPUnit's expectException() still observes it.
     */
    public function test_reply_burst_limit_blocks_the_6th_reply_within_10_minutes(): void
    {
        $root_id = $this->seed_root_comment();

        for ($i = 1; $i <= SpamPolicy::MAX_REPLIES_PER_BURST; $i++) {
            $this->seed_reply($root_id, "Câu trả lời gần đây số {$i}.", 5);
        }

        $this->expectException(SpamLimitException::class);

        try {
            $this->service->create($this->video_id, $this->user_id, 'Câu trả lời liên tục mới nhất.', $root_id);
        } catch (SpamLimitException $exception) {
            self::assertSame('tube_comment_reply_burst_limit', $exception->code());

            throw $exception;
        }
    }

    /**
     * A normal account's 31st comment/reply action within 24 hours (any video) is blocked, once 30 already exist.
     *
     * @throws SpamLimitException Re-thrown after asserting its code, so PHPUnit's expectException() still observes it.
     */
    public function test_global_daily_action_limit_blocks_the_31st_action(): void
    {
        for ($i = 1; $i <= SpamPolicy::MAX_TOTAL_ACTIONS_PER_DAY; $i++) {
            $this->seed_root_only_row("Bình luận đệm cho giới hạn hàng ngày số {$i}.", 2000);
        }

        $this->expectException(SpamLimitException::class);

        try {
            $this->service->create($this->video_id, $this->user_id, 'Bình luận vượt quá giới hạn hàng ngày.', null);
        } catch (SpamLimitException $exception) {
            self::assertSame('tube_comment_daily_limit', $exception->code());

            throw $exception;
        }
    }

    /**
     * Posting the identical content again within 24 hours is blocked as a duplicate.
     *
     * @throws SpamLimitException Re-thrown after asserting its code, so PHPUnit's expectException() still observes it.
     */
    public function test_identical_content_within_24_hours_is_blocked_as_duplicate(): void
    {
        $this->seed_root_only_row('Hay quá, cảm ơn admin đã đăng video này.', 3000);

        $this->expectException(SpamLimitException::class);

        try {
            $this->service->create($this->video_id, $this->user_id, '  Hay Quá, Cảm Ơn Admin Đã Đăng Video Này.  ', null);
        } catch (SpamLimitException $exception) {
            self::assertSame('tube_comment_duplicate_content', $exception->code());

            throw $exception;
        }
    }

    /**
     * A near-duplicate posted within the flood window (only punctuation differs from the immediately preceding comment) is blocked.
     *
     * @throws SpamLimitException Re-thrown after asserting its code, so PHPUnit's expectException() still observes it.
     */
    public function test_near_duplicate_within_the_flood_window_is_blocked(): void
    {
        $this->seed_root_only_row('hay quá', 2);

        $this->expectException(SpamLimitException::class);

        try {
            $this->service->create($this->video_id, $this->user_id, 'hay quá!!!', null);
        } catch (SpamLimitException $exception) {
            self::assertSame('tube_comment_flood_detected', $exception->code());

            throw $exception;
        }
    }

    /**
     * A punctuation-only root comment is rejected as too low-quality (not an anti-spam rate limit).
     */
    public function test_punctuation_only_content_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create($this->video_id, $this->user_id, '...', null);
    }

    /**
     * A normal account's comment with 2+ external links is held for moderation, not published immediately.
     */
    public function test_two_links_from_a_normal_account_goes_pending(): void
    {
        $row = $this->service->create(
            $this->video_id,
            $this->user_id,
            'Xem thêm tại http://example.com/a và http://example.com/b nhé.',
            null
        );

        self::assertSame('pending', $row['status']);
    }

    /**
     * A normal account's comment with exactly 1 external link still publishes immediately.
     */
    public function test_one_link_from_a_normal_account_still_publishes(): void
    {
        $row = $this->service->create(
            $this->video_id,
            $this->user_id,
            'Xem thêm tại http://example.com/a nhé.',
            null
        );

        self::assertSame('published', $row['status']);
    }

    /**
     * A brand-new account's comment with just 1 external link is held for moderation (stricter than the normal 2-link threshold).
     */
    public function test_one_link_from_a_brand_new_account_goes_pending(): void
    {
        $new_user_id = $this->create_member(0);

        $row = $this->service->create(
            $this->video_id,
            $new_user_id,
            'Xem thêm tại http://example.com/a nhé.',
            null
        );

        self::assertSame('pending', $row['status']);
    }

    /**
     * A brand-new account's 6th reply TOTAL (account-wide, not per-video) within 24 hours is blocked, once 5 already exist.
     *
     * @throws SpamLimitException Re-thrown after asserting its code, so PHPUnit's expectException() still observes it.
     */
    public function test_new_account_reply_daily_limit_is_stricter_and_account_wide(): void
    {
        $new_user_id = $this->create_member(0);
        $root_id     = $this->seed_root_comment();

        for ($i = 1; $i <= SpamPolicy::NEW_ACCOUNT_MAX_REPLIES_PER_DAY; $i++) {
            $this->seed_reply($root_id, "Câu trả lời tài khoản mới số {$i}.", 700, $new_user_id);
        }

        $this->expectException(SpamLimitException::class);

        try {
            $this->service->create($this->video_id, $new_user_id, 'Câu trả lời tài khoản mới vượt giới hạn.', $root_id);
        } catch (SpamLimitException $exception) {
            self::assertSame('tube_comment_reply_daily_limit', $exception->code());

            throw $exception;
        }
    }

    /**
     * A brand-new account's 11th total action within 24 hours is blocked, once 10 already exist (stricter than the normal 30).
     *
     * @throws SpamLimitException Re-thrown after asserting its code, so PHPUnit's expectException() still observes it.
     */
    public function test_new_account_global_daily_limit_is_stricter(): void
    {
        $new_user_id = $this->create_member(0);

        for ($i = 1; $i <= SpamPolicy::NEW_ACCOUNT_MAX_TOTAL_ACTIONS_PER_DAY; $i++) {
            $this->seed_root_only_row("Bình luận đệm tài khoản mới số {$i}.", 2000, $new_user_id);
        }

        $this->expectException(SpamLimitException::class);

        try {
            $this->service->create($this->video_id, $new_user_id, 'Bình luận tài khoản mới vượt giới hạn.', null);
        } catch (SpamLimitException $exception) {
            self::assertSame('tube_comment_daily_limit', $exception->code());

            throw $exception;
        }
    }

    /**
     * A moderator (moderate_comments capability) bypasses every anti-spam limit, including an already-used root slot and the link-spam pending status.
     */
    public function test_moderator_bypasses_every_anti_spam_limit(): void
    {
        $moderator_id = $this->create_member(90000);
        $user         = get_userdata($moderator_id);
        self::assertNotFalse($user);
        $user->add_cap('moderate_comments');

        $original_user_id = get_current_user_id();
        wp_set_current_user($moderator_id);

        try {
            $first = $this->service->create(
                $this->video_id,
                $moderator_id,
                'Bình luận đầu tiên của kiểm duyệt viên.',
                null
            );
            $second = $this->service->create(
                $this->video_id,
                $moderator_id,
                'Bình luận đầu tiên của kiểm duyệt viên.',
                null
            );
            $with_links = $this->service->create(
                $this->video_id,
                $moderator_id,
                'http://a.com http://b.com http://c.com',
                null
            );

            self::assertSame('published', $first['status']);
            self::assertSame('published', $second['status'], 'Moderators bypass the duplicate-content rule.');
            self::assertSame('published', $with_links['status'], 'Moderators bypass the link-spam pending rule.');
        } finally {
            wp_set_current_user($original_user_id);
        }
    }

    /**
     * Editing an existing comment does not consume another slot in the global daily action count.
     */
    public function test_editing_a_comment_does_not_consume_a_daily_action_slot(): void
    {
        $row = $this->service->create($this->video_id, $this->user_id, 'Nội dung ban đầu.', null);

        $before = $this->comments->count_actions_since($this->user_id, gmdate('Y-m-d H:i:s', time() - 86400));

        $row_id = Params::int($row['id']);
        $this->service->update($row_id, $this->user_id, 'Nội dung đã chỉnh sửa.');

        $after = $this->comments->count_actions_since($this->user_id, gmdate('Y-m-d H:i:s', time() - 86400));

        self::assertSame($before, $after);
    }

    /**
     * Deleting a root comment does NOT reset that video's 24-hour root-comment slot.
     *
     * @throws SpamLimitException Re-thrown after asserting its code, so PHPUnit's expectException() still observes it.
     */
    public function test_deleting_a_root_comment_does_not_reset_the_slot(): void
    {
        $row    = $this->service->create($this->video_id, $this->user_id, 'Bình luận sẽ bị xóa.', null);
        $row_id = Params::int($row['id']);

        $this->service->delete($row_id, $this->user_id);

        $this->expectException(SpamLimitException::class);

        try {
            $this->service->create($this->video_id, $this->user_id, 'Bình luận mới sau khi xóa.', null);
        } catch (SpamLimitException $exception) {
            self::assertSame('tube_comment_video_daily_limit', $exception->code());

            throw $exception;
        }
    }

    /**
     * A pending/moderated comment still counts toward the global daily action limit -- moderation never grants a fresh posting slot.
     */
    public function test_a_pending_comment_still_counts_toward_the_daily_limit(): void
    {
        // Two links makes this land as 'pending', not 'published'.
        $row = $this->service->create(
            $this->video_id,
            $this->user_id,
            'Xem http://a.com và http://b.com để biết thêm.',
            null
        );
        self::assertSame('pending', $row['status']);

        $count = $this->comments->count_actions_since($this->user_id, gmdate('Y-m-d H:i:s', time() - 86400));

        self::assertSame(1, $count);
    }

    /**
     * A soft-deleted comment still counts toward the global daily action limit.
     */
    public function test_a_deleted_comment_still_counts_toward_the_daily_limit(): void
    {
        $row    = $this->service->create($this->video_id, $this->user_id, 'Bình luận sẽ bị xóa ngay sau đó.', null);
        $row_id = Params::int($row['id']);
        $this->service->delete($row_id, $this->user_id);

        $count = $this->comments->count_actions_since($this->user_id, gmdate('Y-m-d H:i:s', time() - 86400));

        self::assertSame(1, $count);
    }

    /**
     * The SpamLimitException thrown for a blocked root comment carries a real, future available_at instant.
     */
    public function test_blocked_root_comment_exception_carries_a_future_available_at(): void
    {
        $this->service->create($this->video_id, $this->user_id, 'Bình luận đầu tiên.', null);

        try {
            $this->service->create($this->video_id, $this->user_id, 'Bình luận thứ hai khác biệt.', null);
            self::fail('Expected a SpamLimitException.');
        } catch (SpamLimitException $exception) {
            $available_at = $exception->available_at();
            self::assertIsString($available_at);
            $timestamp = strtotime($available_at);
            self::assertIsInt($timestamp);
            self::assertGreaterThan(time(), $timestamp);
            self::assertLessThanOrEqual(time() + SpamPolicy::ROOT_COMMENT_WINDOW_SECONDS + 5, $timestamp);
        }
    }

    /**
     * Create a real member account, with user_registered backdated by $age_seconds.
     *
     * @param int $age_seconds How many seconds in the past to backdate user_registered.
     */
    private function create_member(int $age_seconds): int
    {
        $user_id = wp_insert_user(
            [
                'user_login' => 'spam-guard-it-' . uniqid('', true),
                'user_email' => uniqid('spam-guard-it-', true) . '@example.com',
                'user_pass'  => wp_generate_password(),
                'role'       => 'subscriber',
            ]
        );

        self::assertIsInt($user_id);

        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
        $wpdb->update(
            $wpdb->users,
            ['user_registered' => gmdate('Y-m-d H:i:s', time() - $age_seconds)],
            ['ID' => $user_id],
            ['%s'],
            ['%d']
        );

        $this->created_user_ids[] = $user_id;

        return $user_id;
    }

    /**
     * Create a real video post.
     */
    private function create_video(): int
    {
        $video_id = wp_insert_post(
            [
                'post_type'   => 'video',
                'post_title'  => 'SpamGuard Integration Test Video',
                'post_status' => 'draft',
            ],
            true
        );

        self::assertIsInt($video_id);

        $this->created_video_ids[] = $video_id;

        return $video_id;
    }

    /**
     * Insert a real root comment row directly (bypassing SpamGuard), for use as a reply target.
     */
    private function seed_root_comment(): int
    {
        return $this->comments->insert(
            [
                'video_id'         => $this->video_id,
                'user_id'          => $this->user_id,
                'parent_id'        => null,
                'reply_to_user_id' => null,
                'content'          => 'Bình luận gốc để chứa các câu trả lời thử nghiệm.',
                'status'           => 'published',
            ]
        );
    }

    /**
     * Insert a real reply row directly (bypassing SpamGuard), backdated by $age_seconds.
     *
     * @param int      $parent_id   The root comment to attach the reply to.
     * @param string   $content     The reply's content.
     * @param int      $age_seconds How many seconds in the past to backdate created_at.
     * @param int|null $user_id     Overrides $this->user_id.
     */
    private function seed_reply(int $parent_id, string $content, int $age_seconds, ?int $user_id = null): int
    {
        $id = $this->comments->insert(
            [
                'video_id'         => $this->video_id,
                'user_id'          => $user_id ?? $this->user_id,
                'parent_id'        => $parent_id,
                'reply_to_user_id' => null,
                'content'          => $content,
                'status'           => 'published',
            ]
        );

        $this->backdate($id, $age_seconds);

        return $id;
    }

    /**
     * Insert a real root-comment row directly (bypassing SpamGuard AND the root-lock table), backdated by $age_seconds.
     *
     * @param string   $content     The comment's content.
     * @param int      $age_seconds How many seconds in the past to backdate created_at.
     * @param int|null $user_id     Overrides $this->user_id.
     */
    private function seed_root_only_row(string $content, int $age_seconds, ?int $user_id = null): int
    {
        $id = $this->comments->insert(
            [
                'video_id'         => $this->video_id,
                'user_id'          => $user_id ?? $this->user_id,
                'parent_id'        => null,
                'reply_to_user_id' => null,
                'content'          => $content,
                'status'           => 'published',
            ]
        );

        $this->backdate($id, $age_seconds);

        return $id;
    }

    /**
     * Directly set a comment row's created_at to $age_seconds in the past.
     *
     * @param int $comment_id  The comment to backdate.
     * @param int $age_seconds How many seconds in the past to backdate created_at.
     */
    private function backdate(int $comment_id, int $age_seconds): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
        $wpdb->update(
            $wpdb->prefix . 'tube_comments',
            ['created_at' => gmdate('Y-m-d H:i:s', time() - $age_seconds)],
            ['id' => $comment_id],
            ['%s'],
            ['%d']
        );
    }
}
