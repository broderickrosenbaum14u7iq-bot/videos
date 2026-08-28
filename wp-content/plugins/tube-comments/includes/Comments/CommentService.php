<?php
/**
 * Create/edit/delete a comment, keeping counters and reply-depth in sync.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

namespace Tube_Comments\Comments;

use Tube_Comments\Comments\AntiSpam\SpamGuard;
use Tube_Comments\Comments\Repositories\CommentCounterRepository;
use Tube_Comments\Comments\Repositories\CommentRepository;
use Tube_Comments\Support\Params;

/**
 * Create/edit/delete a comment, keeping `wp_tube_comment_counters` and
 * each root comment's `replies_total` in sync — the pure logic behind
 * the HTTP controllers, separated from their request/response concerns
 * the same way `Tube_Core\Likes\LikeToggleService` is split from
 * `LikeController`.
 */
final class CommentService
{
    /**
     * Construct around the collaborators this service reads and writes through.
     *
     * @param CommentRepository        $comments   The comment rows themselves.
     * @param CommentCounterRepository $counters   Each video's "💬 Bình luận N" total.
     * @param SpamGuard                $spam_guard Enforces every anti-spam rule before a row is ever inserted.
     */
    public function __construct(
        private readonly CommentRepository $comments,
        private readonly CommentCounterRepository $counters,
        private readonly SpamGuard $spam_guard
    ) {
    }

    // phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber -- this sniff only counts exceptions literally thrown in this method's own body (ValidationException, twice, one distinct class); it can't see SpamLimitException propagating from the SpamGuard::guard() call inside, which PHPStan's dead-catch analysis at the call site needs this second @throws tag to resolve correctly.
    /**
     * Create a new root comment or reply.
     *
     * $reply_target_id, if given, is flattened per Phase 15 ("one
     * visible nested level only"): replying to a reply stores the new
     * row under the ORIGINAL ROOT comment's id, carrying the immediate
     * target's user_id as `reply_to_user_id` (rendered as an
     * "@DisplayName" prefix) — never a deeper `parent_id` chain.
     *
     * @param int      $video_id         The video being commented on.
     * @param int      $user_id          The authenticated commenter.
     * @param string   $raw_content      The visitor's raw, untrusted comment text.
     * @param int|null $reply_target_id  The comment being replied to, or null for a new root comment.
     *
     * @return array<string, mixed> The newly-created comment's full row.
     *
     * @throws ValidationException If $raw_content is empty/too-low-quality after sanitizing, or the reply target doesn't exist.
     * @throws \Tube_Comments\Comments\AntiSpam\SpamLimitException If an anti-spam rate/duplicate/flood/daily-cap rule blocks this creation -- propagated from SpamGuard::guard(), never thrown directly in this method's own body.
     */
    public function create(int $video_id, int $user_id, string $raw_content, ?int $reply_target_id): array
    {
        $content = (new ContentSanitizer())->sanitize($raw_content);

        if ('' === $content) {
            throw new ValidationException('Nội dung bình luận không được để trống.');
        }

        $is_root          = null === $reply_target_id;
        $parent_id        = null;
        $reply_to_user_id = null;

        if (! $is_root) {
            $target = $this->comments->find($reply_target_id);

            if (null === $target || Params::int($target['video_id']) !== $video_id) {
                throw new ValidationException('Không tìm thấy bình luận để trả lời.');
            }

            // Flatten: if the target is itself a reply, its own parent_id
            // (the true root) is what this new row must point to.
            $parent_id        = null !== $target['parent_id'] ? Params::int($target['parent_id']) : Params::int($target['id']);
            $reply_to_user_id = Params::int($target['user_id']);
        }

        $this->spam_guard->guard($user_id, $video_id, $content, $is_root);

        $status = $this->spam_guard->initial_status($user_id, $content);

        $id = $this->comments->insert(
            [
                'video_id'         => $video_id,
                'user_id'          => $user_id,
                'parent_id'        => $parent_id,
                'reply_to_user_id' => $reply_to_user_id,
                'content'          => $content,
                'status'           => $status,
            ]
        );

        if ('published' === $status) {
            $this->counters->increment($video_id);

            if (null !== $parent_id) {
                $this->comments->increment_replies_total($parent_id);
            }
        }

        $row = $this->comments->find($id);

        return null === $row ? [] : $row;
    }
    // phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber

    /**
     * Edit a comment's own content. Ownership is enforced here, not just
     * at the HTTP layer, so this method is safe to call from anywhere.
     *
     * @param int    $comment_id         The comment being edited.
     * @param int    $requesting_user_id The authenticated visitor attempting the edit.
     * @param string $raw_content        The visitor's raw, untrusted new comment text.
     *
     * @return array<string, mixed> The updated comment's full row.
     *
     * @throws ForbiddenException If $requesting_user_id doesn't own the comment.
     * @throws ValidationException If the comment doesn't exist, or the new content is empty after sanitizing.
     */
    public function update(int $comment_id, int $requesting_user_id, string $raw_content): array
    {
        $comment = $this->comments->find($comment_id);

        if (null === $comment) {
            throw new ValidationException('Không tìm thấy bình luận.');
        }

        $comment_author_id = Params::int($comment['user_id']);

        if ($comment_author_id !== $requesting_user_id) {
            throw new ForbiddenException('Bạn không thể chỉnh sửa bình luận của người khác.');
        }

        $content = (new ContentSanitizer())->sanitize($raw_content);

        if ('' === $content) {
            throw new ValidationException('Nội dung bình luận không được để trống.');
        }

        $this->comments->update_content($comment_id, $content);

        $row = $this->comments->find($comment_id);

        return null === $row ? [] : $row;
    }

    /**
     * Soft-delete a comment the requester owns (Phase 18). A root
     * comment with remaining published replies keeps its row (rendered
     * as "[Bình luận đã bị xóa]" — see `CommentRepository`'s public-
     * listing queries), so replies are never orphaned; a reply or a
     * childless root is fully hidden by those same queries once marked
     * `deleted`, with no further branching needed here.
     *
     * @param int $comment_id         The comment being deleted.
     * @param int $requesting_user_id The authenticated visitor attempting the delete.
     *
     * @throws ForbiddenException If $requesting_user_id doesn't own the comment.
     * @throws ValidationException If the comment doesn't exist.
     */
    public function delete(int $comment_id, int $requesting_user_id): void
    {
        $comment = $this->comments->find($comment_id);

        if (null === $comment) {
            throw new ValidationException('Không tìm thấy bình luận.');
        }

        $comment_author_id = Params::int($comment['user_id']);

        if ($comment_author_id !== $requesting_user_id) {
            throw new ForbiddenException('Bạn không thể xóa bình luận của người khác.');
        }

        $was_published = 'published' === $comment['status'];

        $this->comments->set_status($comment_id, 'deleted');

        if (! $was_published) {
            return;
        }

        $video_id = Params::int($comment['video_id']);
        $this->counters->decrement($video_id);

        if (null !== $comment['parent_id']) {
            $parent_id = Params::int($comment['parent_id']);
            $this->comments->decrement_replies_total($parent_id);
        }
    }

    /**
     * Change a comment's status from the moderation screen (Phase 22):
     * Approve/Unapprove/Mark Spam/Restore/admin-side Delete are all the
     * same operation underneath — set a new status and adjust
     * `wp_tube_comment_counters`/the parent's `replies_total` by exactly
     * the delta this transition causes (+1 if the comment is newly
     * counted as published, -1 if it no longer is, 0 for a transition
     * between two already-uncounted statuses such as pending → spam).
     *
     * No ownership check — this is an administrator/editor action
     * (`ModerationScreen` gates the whole screen on the `moderate_comments`
     * capability before this is ever reached), unlike
     * {@see self::update()}/{@see self::delete()}, which are a comment
     * owner's own actions.
     *
     * @param int                                    $comment_id The comment being moderated.
     * @param 'published'|'pending'|'spam'|'deleted' $new_status The status to transition to.
     *
     * @throws ValidationException If the comment doesn't exist.
     */
    public function set_status_as_moderator(int $comment_id, string $new_status): void
    {
        $comment = $this->comments->find($comment_id);

        if (null === $comment) {
            throw new ValidationException('Không tìm thấy bình luận.');
        }

        $was_published = 'published' === $comment['status'];
        $will_publish  = 'published' === $new_status;

        $this->comments->set_status($comment_id, $new_status);

        if ($was_published === $will_publish) {
            return;
        }

        $video_id  = Params::int($comment['video_id']);
        $parent_id = null !== $comment['parent_id'] ? Params::int($comment['parent_id']) : null;

        if ($will_publish) {
            $this->counters->increment($video_id);

            if (null !== $parent_id) {
                $this->comments->increment_replies_total($parent_id);
            }

            return;
        }

        $this->counters->decrement($video_id);

        if (null !== $parent_id) {
            $this->comments->decrement_replies_total($parent_id);
        }
    }
}
