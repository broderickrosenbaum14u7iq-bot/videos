<?php
/**
 * Data access for wp_tube_comments.
 *
 * @package Tube_Comments
 */

declare(strict_types=1);

namespace Tube_Comments\Comments\Repositories;

use Tube_Comments\Support\Params;

/**
 * Data access for `wp_tube_comments` — see `Migration001CreateCommentsTable`'s
 * docblock for the full storage-decision writeup and index shapes each
 * query below maps to. Direct `$wpdb` access is this project's own
 * documented exception for dedicated custom tables (ARCHITECTURE.md
 * §2.5/§11), the same posture every other `{Noun}Repository` in this
 * codebase already uses.
 *
 * Every row is returned as a plain associative array (not a value
 * object) — this repository has more call sites needing different
 * subsets of columns than tube-core's Like/Save repositories, and a
 * value object would need to carry every column for every caller
 * regardless of which few fields a given read path actually uses.
 */
final class CommentRepository
{
    /**
     * A soft-deleted root comment with at least one remaining published
     * reply is still shown, rendered as a "[Bình luận đã bị xóa]"
     * placeholder (Phase 18) — this fragment is ORed into every public
     * root-comment listing query's WHERE clause for that reason. A
     * soft-deleted root with zero replies, or a soft-deleted reply
     * (replies never have children — Phase 15's one-level invariant),
     * is simply excluded outright.
     */
    private const PUBLIC_ROOT_STATUS_SQL = "(status = 'published' OR (status = 'deleted' AND replies_total > 0))";

    /**
     * This comment's full row, or null if it doesn't exist. Used for
     * ownership checks (update/delete), reply-target flattening (Phase
     * 15), and resolving a like/report target's video_id.
     *
     * @param int $id The comment ID.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $table = $wpdb->prefix . 'tube_comments';

        $sql = Params::required_sql($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $table, $id));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); $sql is Params::required_sql()'s narrowed wrapper around $wpdb->prepare() above, which this sniff's static analysis can't trace through.
        $row = $wpdb->get_row($sql, ARRAY_A);

        /** @var array<string, mixed>|null $row */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
        return is_array($row) ? $row : null;
    }

    /**
     * Insert a new comment/reply row.
     *
     * @param array{video_id:int,user_id:int,parent_id:?int,reply_to_user_id:?int,content:string,status:string} $data Column values.
     */
    public function insert(array $data): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $table = $wpdb->prefix . 'tube_comments';
        $now   = current_time('mysql', true);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table (§2.5, §11).
        $wpdb->insert(
            $table,
            [
                'video_id'         => $data['video_id'],
                'user_id'          => $data['user_id'],
                'parent_id'        => $data['parent_id'],
                'reply_to_user_id' => $data['reply_to_user_id'],
                'content'          => $data['content'],
                'status'           => $data['status'],
                'created_at'       => $now,
                'updated_at'       => $now,
            ],
            ['%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s']
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Update a comment's content after an author edit, stamping `edited_at`
     * so the UI can show "đã chỉnh sửa" (Phase 18).
     *
     * @param int    $id      The comment ID.
     * @param string $content The new, already-sanitized content.
     */
    public function update_content(int $id, string $content): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $now = current_time('mysql', true);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table (§2.5, §11).
        $wpdb->update(
            $wpdb->prefix . 'tube_comments',
            [
                'content'    => $content,
                'edited_at'  => $now,
                'updated_at' => $now,
            ],
            ['id' => $id],
            ['%s', '%s', '%s'],
            ['%d']
        );
    }

    /**
     * Set a comment's moderation status (`published`/`pending`/`spam`/`deleted`).
     *
     * @param int    $id     The comment ID.
     * @param string $status The new status.
     */
    public function set_status(int $id, string $status): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table (§2.5, §11).
        $wpdb->update(
            $wpdb->prefix . 'tube_comments',
            [
                'status'     => $status,
                'updated_at' => current_time('mysql', true),
            ],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        );
    }

    /**
     * Atomically add 1 to a comment's `replies_total`. Never called
     * unless a reply was just genuinely inserted with `published` status.
     *
     * @param int $id The root comment's ID.
     */
    public function increment_replies_total(int $id): void
    {
        $this->bump_column($id, 'replies_total', 1);
    }

    /**
     * Atomically subtract 1 from a comment's `replies_total`, floored at 0.
     *
     * @param int $id The root comment's ID.
     */
    public function decrement_replies_total(int $id): void
    {
        $this->bump_column($id, 'replies_total', -1);
    }

    /**
     * Atomically add 1 to a comment's `likes_total`.
     *
     * @param int $id The comment ID.
     */
    public function increment_likes(int $id): void
    {
        $this->bump_column($id, 'likes_total', 1);
    }

    /**
     * Atomically subtract 1 from a comment's `likes_total`, floored at 0.
     *
     * @param int $id The comment ID.
     */
    public function decrement_likes(int $id): void
    {
        $this->bump_column($id, 'likes_total', -1);
    }

    /**
     * A video's root comments, newest first, keyset-paginated on `id`
     * (this table's rows are inserted in strictly increasing id order,
     * so `id DESC` is a safe, index-friendly proxy for `created_at DESC`
     * — see this repository's class docblock note on the accepted
     * same-second edge case). Uses `video_root_recent_idx`.
     *
     * @param int      $video_id The video to list root comments for.
     * @param int      $limit    Maximum number of comments to return.
     * @param int|null $after_id Keyset cursor: only comments with id < this value. Null for the first page.
     *
     * @return list<array<string, mixed>>
     */
    public function list_root_recent(int $video_id, int $limit, ?int $after_id): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $table = $wpdb->prefix . 'tube_comments';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::PUBLIC_ROOT_STATUS_SQL is a private literal class constant, never derived from request input, safe to concatenate directly ahead of $wpdb->prepare()'s own %d/%s binding for the remaining placeholders below.
        $status_clause = self::PUBLIC_ROOT_STATUS_SQL;

        if (null !== $after_id) {
            $sql = 'SELECT * FROM %i WHERE video_id = %d AND parent_id IS NULL AND id < %d
                AND ' . $status_clause . ' ORDER BY id DESC LIMIT %d';

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is a literal template built above, never request input.
            $prepared = Params::required_sql($wpdb->prepare($sql, $table, $video_id, $after_id, $limit));
        } else {
            $sql = 'SELECT * FROM %i WHERE video_id = %d AND parent_id IS NULL
                AND ' . $status_clause . ' ORDER BY id DESC LIMIT %d';

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is a literal template built above, never request input.
            $prepared = Params::required_sql($wpdb->prepare($sql, $table, $video_id, $limit));
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); $prepared is Params::required_sql()'s narrowed wrapper around $wpdb->prepare() above, which this sniff's static analysis can't trace through.
        $rows = $wpdb->get_results($prepared, ARRAY_A);

        /** @var list<array<string, mixed>>|null $rows */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
        return is_array($rows) ? $rows : [];
    }

    /**
     * A video's root comments ranked by popularity — a simple, transparent
     * `likes_total DESC` order with `id DESC` as the recency tiebreak
     * (Phase 23: "primarily on likes/replies and age", deliberately not
     * a weighted/decayed score). Offset-paginated rather than
     * keyset-paginated: unlike the "recent" default, a per-video
     * comment count staying in the thousands (not millions) makes an
     * OFFSET scan acceptable here (Phase 30 — no premature optimization).
     * Uses `video_root_popular_idx`.
     *
     * @param int $video_id The video to list root comments for.
     * @param int $limit    Maximum number of comments to return.
     * @param int $offset   Number of comments to skip, for pagination.
     *
     * @return list<array<string, mixed>>
     */
    public function list_root_popular(int $video_id, int $limit, int $offset): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $table = $wpdb->prefix . 'tube_comments';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::PUBLIC_ROOT_STATUS_SQL is a private literal class constant, never derived from request input.
        $status_clause = self::PUBLIC_ROOT_STATUS_SQL;
        $sql           = 'SELECT * FROM %i WHERE video_id = %d AND parent_id IS NULL
            AND ' . $status_clause . ' ORDER BY likes_total DESC, id DESC LIMIT %d OFFSET %d';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is a literal template built above, never request input.
        $prepared = Params::required_sql($wpdb->prepare($sql, $table, $video_id, $limit, $offset));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); $prepared is Params::required_sql()'s narrowed wrapper around $wpdb->prepare() above, which this sniff's static analysis can't trace through.
        $rows = $wpdb->get_results($prepared, ARRAY_A);

        /** @var list<array<string, mixed>>|null $rows */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
        return is_array($rows) ? $rows : [];
    }

    /**
     * A root comment's published replies, oldest first (a reply thread
     * reads top-to-bottom as a conversation, unlike the root listing).
     * Uses `parent_idx`. Replies never have children of their own
     * (Phase 15), so no placeholder-preservation exception is needed
     * here the way {@see self::PUBLIC_ROOT_STATUS_SQL} needs one.
     *
     * @param int $parent_id The root comment whose replies to list.
     * @param int $limit     Maximum number of replies to return.
     * @param int $offset    Number of replies to skip, for pagination.
     *
     * @return list<array<string, mixed>>
     */
    public function list_replies(int $parent_id, int $limit, int $offset): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $table = $wpdb->prefix . 'tube_comments';

        $prepared = Params::required_sql(
            $wpdb->prepare(
                "SELECT * FROM %i WHERE parent_id = %d AND status = 'published' ORDER BY id ASC LIMIT %d OFFSET %d",
                $table,
                $parent_id,
                $limit,
                $offset
            )
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); $prepared is Params::required_sql()'s narrowed wrapper around $wpdb->prepare() above, which this sniff's static analysis can't trace through.
        $rows = $wpdb->get_results($prepared, ARRAY_A);

        /** @var list<array<string, mixed>>|null $rows */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
        return is_array($rows) ? $rows : [];
    }

    /**
     * One member's own comments (any status they authored), newest
     * first, for the frontend account page's "Bình luận của tôi"
     * (Phase 9) — deliberately not status-filtered, so a member can see
     * their own pending/spam-flagged comments, unlike the public listings.
     * Uses `user_idx`.
     *
     * @param int $user_id The member account.
     * @param int $limit   Maximum number of comments to return.
     * @param int $offset  Number of comments to skip, for pagination.
     *
     * @return list<array<string, mixed>>
     */
    public function list_mine(int $user_id, int $limit, int $offset): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $table = $wpdb->prefix . 'tube_comments';

        $prepared = Params::required_sql(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE user_id = %d ORDER BY id DESC LIMIT %d OFFSET %d',
                $table,
                $user_id,
                $limit,
                $offset
            )
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); $prepared is Params::required_sql()'s narrowed wrapper around $wpdb->prepare() above, which this sniff's static analysis can't trace through.
        $rows = $wpdb->get_results($prepared, ARRAY_A);

        /** @var list<array<string, mixed>>|null $rows */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
        return is_array($rows) ? $rows : [];
    }

    /**
     * Total comment-creation actions (root comments + replies, any
     * status, any video) by $user_id since $since — the global daily
     * action cap (`SpamPolicy::MAX_TOTAL_ACTIONS_PER_DAY`). Every status
     * counts, not just `published` (Phase "moderated comments still
     * count against limits" — a spammer must not earn a fresh slot
     * simply because a prior comment was marked pending/spam/deleted).
     *
     * Index audit: `user_idx (user_id, created_at)` already shapes this
     * exactly — an equality seek on `user_id` followed by a range scan
     * on `created_at`, both leading index columns, so this query's cost
     * depends only on how many rows THIS ONE user has posted in the
     * window, never on the table's overall row count. No new index is
     * needed for this query.
     *
     * @param int    $user_id The account to count.
     * @param string $since   UTC 'Y-m-d H:i:s' — only rows at or after this instant count.
     */
    public function count_actions_since(int $user_id, string $since): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $table = $wpdb->prefix . 'tube_comments';

        $sql = Params::required_sql(
            $wpdb->prepare('SELECT COUNT(*) FROM %i WHERE user_id = %d AND created_at >= %s', $table, $user_id, $since)
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); $sql is Params::required_sql()'s narrowed wrapper around $wpdb->prepare() above, which this sniff's static analysis can't trace through.
        $count = $wpdb->get_var($sql);

        return null === $count ? 0 : (int) $count;
    }

    /**
     * Total replies (any status, any video) by $user_id since $since —
     * the account-wide reply-burst check
     * (`SpamPolicy::MAX_REPLIES_PER_BURST`) and the new-account
     * account-wide daily reply cap
     * (`SpamPolicy::NEW_ACCOUNT_MAX_REPLIES_PER_DAY`) both call this
     * with different windows. Same `user_idx` index-audit reasoning as
     * {@see self::count_actions_since()}.
     *
     * @param int    $user_id The account to count.
     * @param string $since   UTC 'Y-m-d H:i:s' — only rows at or after this instant count.
     */
    public function count_replies_since(int $user_id, string $since): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $table = $wpdb->prefix . 'tube_comments';

        $sql = Params::required_sql(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE user_id = %d AND parent_id IS NOT NULL AND created_at >= %s',
                $table,
                $user_id,
                $since
            )
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); $sql is Params::required_sql()'s narrowed wrapper around $wpdb->prepare() above, which this sniff's static analysis can't trace through.
        $count = $wpdb->get_var($sql);

        return null === $count ? 0 : (int) $count;
    }

    /**
     * Total replies by $user_id on $video_id since $since — the normal
     * (not brand-new) account's per-video daily reply cap
     * (`SpamPolicy::MAX_REPLIES_PER_VIDEO_PER_DAY`). Same `user_idx`
     * index-audit reasoning as {@see self::count_actions_since()}: the
     * `video_id`/`parent_id` conditions are applied as a filter on the
     * already-small set of this user's own rows in the window, not as a
     * separate scan.
     *
     * @param int    $user_id  The account to count.
     * @param int    $video_id The video to count replies on.
     * @param string $since    UTC 'Y-m-d H:i:s' — only rows at or after this instant count.
     */
    public function count_replies_for_video_since(int $user_id, int $video_id, string $since): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $table = $wpdb->prefix . 'tube_comments';

        $sql = Params::required_sql(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE user_id = %d AND video_id = %d AND parent_id IS NOT NULL AND created_at >= %s',
                $table,
                $user_id,
                $video_id,
                $since
            )
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); $sql is Params::required_sql()'s narrowed wrapper around $wpdb->prepare() above, which this sniff's static analysis can't trace through.
        $count = $wpdb->get_var($sql);

        return null === $count ? 0 : (int) $count;
    }

    /**
     * This user's $limit most recent comments/replies (any video, any
     * status), newest first — the small, bounded fetch behind duplicate-
     * content and flood detection (`SpamGuard`). Deliberately NOT a full
     * historical scan: `$limit` is `SpamPolicy::DUPLICATE_CHECK_LOOKBACK`,
     * a small fixed number, and the `user_idx` seek this query performs
     * costs the same whether the table holds thousands or millions of
     * rows overall (see {@see self::count_actions_since()}'s identical
     * index-audit reasoning).
     *
     * @param int    $user_id The account whose recent content to fetch.
     * @param string $since   UTC 'Y-m-d H:i:s' — only rows at or after this instant are considered.
     * @param int    $limit   Maximum rows to return.
     *
     * @return list<array{content: string, created_at: string}>
     */
    public function recent_content_since(int $user_id, string $since, int $limit): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $table = $wpdb->prefix . 'tube_comments';

        $sql = Params::required_sql(
            $wpdb->prepare(
                'SELECT content, created_at FROM %i WHERE user_id = %d AND created_at >= %s ORDER BY id DESC LIMIT %d',
                $table,
                $user_id,
                $since,
                $limit
            )
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); $sql is Params::required_sql()'s narrowed wrapper around $wpdb->prepare() above, which this sniff's static analysis can't trace through.
        $rows = $wpdb->get_results($sql, ARRAY_A);

        /** @var list<array<string, mixed>>|null $rows */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
        $safe_rows = is_array($rows) ? $rows : [];

        return array_map(
            static fn (array $row): array => [
                'content'    => Params::string($row['content']),
                'created_at' => Params::string($row['created_at']),
            ],
            $safe_rows
        );
    }

    /**
     * Moderation-screen listing (Phase 22), filtered by status ('all'
     * bypasses the filter) and an optional content/user-id search term.
     * A wp-admin-only, low-QPS read path — an OFFSET scan and a
     * `LIKE '%...%'` content search are both acceptable trade-offs here
     * that would not be acceptable on a public hot path (Phase 30's
     * budget applies to visitor-facing reads, not the backend).
     *
     * @param string $status_filter One of 'all'/'published'/'pending'/'spam'/'deleted'.
     * @param string $search        Optional content search term; '' skips the filter.
     * @param int    $limit         Maximum number of comments to return.
     * @param int    $offset        Number of comments to skip, for pagination.
     *
     * @return list<array<string, mixed>>
     */
    public function list_for_moderation(string $status_filter, string $search, int $limit, int $offset): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $table = $wpdb->prefix . 'tube_comments';
        $where = ['1 = 1'];
        $args  = [$table];

        if ('all' !== $status_filter && '' !== $status_filter) {
            $where[] = 'status = %s';
            $args[]  = $status_filter;
        }

        if ('' !== $search) {
            $where[] = 'content LIKE %s';
            $args[]  = '%' . $wpdb->esc_like($search) . '%';
        }

        $args[] = $limit;
        $args[] = $offset;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $where is built only from the two literal fragments above plus a fixed '1 = 1', never from request input directly.
        $where_clause = implode(' AND ', $where);
        $sql          = 'SELECT * FROM %i WHERE ' . $where_clause . ' ORDER BY id DESC LIMIT %d OFFSET %d';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $sql is a literal template built two lines above, never request input; $args' element count varies with how many of the optional filter fragments above are active, which this sniff's static placeholder count can't follow.
        $prepared = Params::required_sql($wpdb->prepare($sql, $args));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11), admin-only listing (see this method's own docblock); $prepared is Params::required_sql()'s narrowed wrapper, which this sniff can't trace through.
        $rows = $wpdb->get_results($prepared, ARRAY_A);

        /** @var list<array<string, mixed>>|null $rows */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.
        return is_array($rows) ? $rows : [];
    }

    /**
     * Total rows matching the same filter {@see self::list_for_moderation()}
     * uses, for the moderation screen's pagination.
     *
     * @param string $status_filter One of 'all'/'published'/'pending'/'spam'/'deleted'.
     * @param string $search        Optional content search term; '' skips the filter.
     */
    public function count_for_moderation(string $status_filter, string $search): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $table = $wpdb->prefix . 'tube_comments';
        $where = ['1 = 1'];
        $args  = [$table];

        if ('all' !== $status_filter && '' !== $status_filter) {
            $where[] = 'status = %s';
            $args[]  = $status_filter;
        }

        if ('' !== $search) {
            $where[] = 'content LIKE %s';
            $args[]  = '%' . $wpdb->esc_like($search) . '%';
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- see list_for_moderation()'s identical, fully-explained ignore comment for $where.
        $where_clause = implode(' AND ', $where);
        $sql          = 'SELECT COUNT(*) FROM %i WHERE ' . $where_clause;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- see list_for_moderation()'s identical, fully-explained ignore comment.
        $prepared = Params::required_sql($wpdb->prepare($sql, $args));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11), admin-only (see list_for_moderation()'s docblock); $prepared is Params::required_sql()'s narrowed wrapper, which this sniff can't trace through.
        $count = $wpdb->get_var($prepared);

        return null === $count ? 0 : (int) $count;
    }

    /**
     * Atomically add $delta (positive or negative) to one integer
     * column, floored at 0 — the shared primitive behind
     * self::increment_likes()/decrement_likes()/increment_replies_total()/
     * decrement_replies_total(), all four of which are "adjust a
     * denormalized counter column by exactly 1" and differ only in
     * which column and which direction.
     *
     * @param int    $id     The comment ID.
     * @param string $column The column to adjust — always a literal from one of the four callers above, never request input.
     * @param int    $delta  +1 or -1.
     */
    private function bump_column(int $id, string $column, int $delta): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $table     = $wpdb->prefix . 'tube_comments';
        $sign      = $delta >= 0 ? '+' : '-';
        $magnitude = abs($delta);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $column/$sign are literal, caller-controlled-only (never user input) identifiers/operators, not bindable via wpdb::prepare(); $magnitude is prepared as %d below.
        $sql = "UPDATE %i SET {$column} = GREATEST(0, {$column} {$sign} %d) WHERE id = %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is a literal template built above, never request input. @phpstan-ignore argument.type (wpdb::prepare()'s literal-string stub can't be satisfied while $column/$sign vary by caller)
        $prepared = Params::required_sql($wpdb->prepare($sql, $table, $magnitude, $id));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); $prepared is Params::required_sql()'s narrowed wrapper, which this sniff can't trace through.
        $wpdb->query($prepared);
    }
}
