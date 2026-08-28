<?php
/**
 * Maps raw wp_tube_comments rows to the public JSON shape.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

namespace Tube_Comments\Http;

use Tube_Comments\Comments\Repositories\CommentLikeRepository;
use Tube_Comments\Support\Params;

/**
 * Maps raw `wp_tube_comments` rows to the public JSON shape, shared by
 * every read/write controller that returns comment data
 * (List/Replies/Mine/Create/Update). Batches author lookups (Phase 30's
 * "avoid N+1 queries for avatars/users/like counts") rather than
 * resolving each comment's author one query at a time.
 *
 * Never includes email, IP, or any private user meta (Phase 29) — only
 * `id`, `display_name`, and an avatar URL are exposed for an author.
 */
final class CommentPresenter
{
    /**
     * Construct around the repository this presenter batch-resolves like state through.
     *
     * @param CommentLikeRepository $likes Resolves the viewer's own like state in one batch query.
     */
    public function __construct(private readonly CommentLikeRepository $likes)
    {
    }

    /**
     * Present a batch of raw comment rows for one viewer.
     *
     * @param list<array<string, mixed>> $rows            The raw comment rows to present.
     * @param int                        $viewer_user_id  The current viewer, or 0 for a guest.
     *
     * @return list<array<string, mixed>>
     */
    public function present_many(array $rows, int $viewer_user_id): array
    {
        if ([] === $rows) {
            return [];
        }

        $user_ids = [];

        foreach ($rows as $row) {
            $user_ids[] = Params::int($row['user_id']);

            if (null !== $row['reply_to_user_id']) {
                $user_ids[] = Params::int($row['reply_to_user_id']);
            }
        }

        $user_ids = array_values(array_unique($user_ids));

        cache_users($user_ids);

        $names = [];

        $found_users = get_users(
            [
                'include' => $user_ids,
                'fields'  => ['ID', 'display_name'],
            ]
        );

        /** @var list<object{ID: int|string, display_name: string}> $found_users */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
        foreach ($found_users as $user) {
            $found_user_id           = (int) $user->ID;
            $names[ $found_user_id ] = $user->display_name;
        }

        $comment_ids = array_map(static fn (array $row): int => Params::int($row['id']), $rows);
        $liked_set   = $viewer_user_id > 0
            ? array_flip($this->likes->liked_comment_ids($viewer_user_id, $comment_ids))
            : [];

        return array_map(
            fn (array $row): array => $this->present_one($row, $names, $liked_set, $viewer_user_id),
            $rows
        );
    }

    /**
     * Present one raw comment row for one viewer.
     *
     * @param array<string, mixed> $row            The raw comment row.
     * @param array<int, string>   $names          Pre-resolved user_id => display_name.
     * @param array<int, int>      $liked_set      Pre-resolved comment_id => 1 for the viewer's own likes.
     * @param int                  $viewer_user_id The current viewer, or 0 for a guest.
     *
     * @return array<string, mixed>
     */
    public function present_one(array $row, array $names, array $liked_set, int $viewer_user_id): array
    {
        $is_deleted       = 'deleted' === $row['status'];
        $comment_id       = Params::int($row['id']);
        $author_id        = Params::int($row['user_id']);
        $reply_to_user_id = null !== $row['reply_to_user_id'] ? Params::int($row['reply_to_user_id']) : null;
        $created_at_raw   = Params::string($row['created_at']);

        return [
            'id'            => $comment_id,
            'parent_id'     => null !== $row['parent_id'] ? Params::int($row['parent_id']) : null,
            'content'       => $is_deleted ? '' : Params::string($row['content']),
            'is_deleted'    => $is_deleted,
            'status'        => Params::string($row['status']),
            'author'        => $is_deleted ? null : [
                'id'           => $author_id,
                'display_name' => $names[ $author_id ] ?? '',
                'avatar_url'   => function_exists('tube_members_get_avatar_url')
                    ? tube_members_get_avatar_url($author_id)
                    : '',
            ],
            'reply_to'      => (! $is_deleted && null !== $reply_to_user_id) ? [
                'id'           => $reply_to_user_id,
                'display_name' => $names[ $reply_to_user_id ] ?? '',
            ] : null,
            'likes_total'   => Params::int($row['likes_total']),
            'replies_total' => Params::int($row['replies_total']),
            'liked'         => isset($liked_set[ $comment_id ]),
            'is_mine'       => $viewer_user_id > 0 && $viewer_user_id === $author_id,
            'edited'        => null !== $row['edited_at'],
            'created_at'    => get_date_from_gmt($created_at_raw, 'c'),
        ];
    }
}
