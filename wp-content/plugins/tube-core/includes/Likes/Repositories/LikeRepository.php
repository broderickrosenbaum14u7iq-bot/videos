<?php
/**
 * Data access for wp_tube_video_likes (LikeRepositoryInterface) implementation.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Likes\Repositories;

use InvalidArgumentException;
use RuntimeException;

/**
 * Data access for `wp_tube_video_likes` (LikeRepositoryInterface)
 * implementation. Direct $wpdb access is the same documented,
 * intentional exception every dedicated-table repository in this
 * project uses (ARCHITECTURE.md §2.5/§11).
 *
 * Every public method branches into a user-identity or a
 * visitor-token-identity query — never a single query with an `OR` and a
 * nullable bound parameter — the same explicit split
 * `WatchHistoryRepository::upsert_for_user()`/`upsert_for_guest()`
 * already uses, for the same reason: a `WHERE user_id = %d OR
 * visitor_token = %s` with one side always NULL doesn't reliably use
 * either `UNIQUE KEY`, where two fully-typed, fully-indexed queries do.
 */
final class LikeRepository implements LikeRepositoryInterface
{
    /**
     * {@inheritDoc}
     *
     * @param int|null    $user_id       The logged-in viewer's ID, or null for a guest.
     * @param string|null $visitor_token The guest's visitor token, or null for a logged-in viewer.
     * @param int         $video_id      The video to check.
     *
     * @throws InvalidArgumentException If neither $user_id nor $visitor_token is given.
     */
    public function has_liked(?int $user_id, ?string $visitor_token, int $video_id): bool
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $table = $wpdb->prefix . 'tube_video_likes';

        if (null !== $user_id) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table (§2.5, §11).
            $found = $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT 1 FROM %i WHERE user_id = %d AND video_id = %d',
                    $table,
                    $user_id,
                    $video_id
                )
            );

            return null !== $found;
        }

        if (null !== $visitor_token) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table (§2.5, §11).
            $found = $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT 1 FROM %i WHERE visitor_token = %s AND video_id = %d',
                    $table,
                    $visitor_token,
                    $video_id
                )
            );

            return null !== $found;
        }

        throw new InvalidArgumentException('Provide either $user_id or $visitor_token.');
    }

    /**
     * {@inheritDoc}
     *
     * @param int|null    $user_id       The logged-in viewer's ID, or null for a guest.
     * @param string|null $visitor_token The guest's visitor token, or null for a logged-in viewer.
     * @param int         $video_id      The video being liked.
     *
     * @throws InvalidArgumentException If neither $user_id nor $visitor_token is given.
     * @throws RuntimeException If the query template is malformed (a bug in this method, not in any argument).
     */
    public function add(?int $user_id, ?string $visitor_token, int $video_id): bool
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $table = $wpdb->prefix . 'tube_video_likes';
        $now   = current_time('mysql', true);

        if (null !== $user_id) {
            $sql = $wpdb->prepare(
                'INSERT IGNORE INTO %i (video_id, user_id, visitor_token, created_at) VALUES (%d, %d, NULL, %s)',
                $table,
                $video_id,
                $user_id,
                $now
            );
        } elseif (null !== $visitor_token) {
            $sql = $wpdb->prepare(
                'INSERT IGNORE INTO %i (video_id, user_id, visitor_token, created_at) VALUES (%d, NULL, %s, %s)',
                $table,
                $video_id,
                $visitor_token,
                $now
            );
        } else {
            throw new InvalidArgumentException('Provide either $user_id or $visitor_token.');
        }

        if (null === $sql) {
            throw new RuntimeException('wpdb::prepare() returned null in ' . __METHOD__ . '().');
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); $sql *is* $wpdb->prepare()'d above.
        $wpdb->query($sql);

        // INSERT IGNORE reports 1 affected row for a genuine new insert,
        // 0 for a duplicate-key no-op -- the race-safe signal
        // LikeToggleService uses to decide whether to bump likes_total,
        // per this interface method's own docblock.
        return 1 === $wpdb->rows_affected;
    }

    /**
     * {@inheritDoc}
     *
     * @param int|null    $user_id       The logged-in viewer's ID, or null for a guest.
     * @param string|null $visitor_token The guest's visitor token, or null for a logged-in viewer.
     * @param int         $video_id      The video being unliked.
     *
     * @throws InvalidArgumentException If neither $user_id nor $visitor_token is given.
     * @throws RuntimeException If the query template is malformed (a bug in this method, not in any argument).
     */
    public function remove(?int $user_id, ?string $visitor_token, int $video_id): bool
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $table = $wpdb->prefix . 'tube_video_likes';

        if (null !== $user_id) {
            $sql = $wpdb->prepare(
                'DELETE FROM %i WHERE user_id = %d AND video_id = %d',
                $table,
                $user_id,
                $video_id
            );
        } elseif (null !== $visitor_token) {
            $sql = $wpdb->prepare(
                'DELETE FROM %i WHERE visitor_token = %s AND video_id = %d',
                $table,
                $visitor_token,
                $video_id
            );
        } else {
            throw new InvalidArgumentException('Provide either $user_id or $visitor_token.');
        }

        if (null === $sql) {
            throw new RuntimeException('wpdb::prepare() returned null in ' . __METHOD__ . '().');
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dedicated custom table (§2.5, §11); $sql *is* $wpdb->prepare()'d above.
        $deleted = $wpdb->query($sql);

        return is_int($deleted) && $deleted > 0;
    }

    /**
     * {@inheritDoc}
     *
     * @param string $visitor_token The guest's cookie token being merged away.
     * @param int    $user_id       The member account absorbing the guest's likes.
     *
     * @return array{merged_video_ids: list<int>, duplicate_video_ids: list<int>}
     */
    public function merge_visitor_into_user(string $visitor_token, int $user_id): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type-narrowing annotation, not documented API; a short description adds nothing.

        $table = $wpdb->prefix . 'tube_video_likes';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table (§2.5, §11).
        $guest_video_ids = $wpdb->get_col(
            $wpdb->prepare('SELECT video_id FROM %i WHERE visitor_token = %s', $table, $visitor_token)
        );
        $guest_video_ids = array_map(static fn ($id): int => is_numeric($id) ? (int) $id : 0, $guest_video_ids);

        if ([] === $guest_video_ids) {
            return [
                'merged_video_ids'    => [],
                'duplicate_video_ids' => [],
            ];
        }

        $placeholders = implode(', ', array_fill(0, count($guest_video_ids), '%d'));
        $sql          = "SELECT video_id FROM %i WHERE user_id = %d AND video_id IN ({$placeholders})";
        $args         = array_merge([$table, $user_id], $guest_video_ids);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- dedicated custom table (§2.5, §11); $sql is a literal template built above (the {$placeholders} fragment is a fixed count of literal '%d' tokens, never request input), and $args' element count varies with count($guest_video_ids), which this sniff's static placeholder count can't follow.
        $existing_user_video_ids = $wpdb->get_col($wpdb->prepare($sql, $args));
        $existing_user_video_ids = array_map(static fn ($id): int => is_numeric($id) ? (int) $id : 0, $existing_user_video_ids);

        $duplicate_video_ids = array_values(array_intersect($guest_video_ids, $existing_user_video_ids));
        $merged_video_ids    = array_values(array_diff($guest_video_ids, $existing_user_video_ids));

        foreach ($duplicate_video_ids as $video_id) {
            $this->remove(null, $visitor_token, $video_id);
        }

        foreach ($merged_video_ids as $video_id) {
            $wpdb->update(
                $table,
                [
                    'user_id'       => $user_id,
                    'visitor_token' => null,
                ],
                [
                    'visitor_token' => $visitor_token,
                    'video_id'      => $video_id,
                ],
                ['%d', '%s'],
                ['%s', '%d']
            );
        }

        return [
            'merged_video_ids'    => $merged_video_ids,
            'duplicate_video_ids' => $duplicate_video_ids,
        ];
    }
}
