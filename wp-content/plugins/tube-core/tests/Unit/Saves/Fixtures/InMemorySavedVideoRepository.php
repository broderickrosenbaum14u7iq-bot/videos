<?php
/**
 * Test fixture: an in-memory SavedVideoRepositoryInterface.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Unit\Saves\Fixtures;

use Tube_Core\Saves\Repositories\SavedVideoRepositoryInterface;

/**
 * An in-memory SavedVideoRepositoryInterface — same real-set-backed shape
 * as `Tube_Core\Tests\Unit\Likes\Fixtures\InMemoryLikeRepository`, see its
 * docblock for why.
 */
final class InMemorySavedVideoRepository implements SavedVideoRepositoryInterface
{
    /**
     * Every currently-saved (identity, video_id) pair, as `"identity:video_id"` strings.
     *
     * @var array<string, true>
     */
    private array $saves = [];

    /**
     * {@inheritDoc}
     *
     * @param int|null    $user_id       The logged-in viewer's ID, or null for a guest.
     * @param string|null $visitor_token The guest's visitor token, or null for a logged-in viewer.
     * @param int         $video_id      The video to check.
     */
    public function has_saved(?int $user_id, ?string $visitor_token, int $video_id): bool
    {
        return isset($this->saves[ self::key($user_id, $visitor_token, $video_id) ]);
    }

    /**
     * {@inheritDoc}
     *
     * @param int|null    $user_id       The logged-in viewer's ID, or null for a guest.
     * @param string|null $visitor_token The guest's visitor token, or null for a logged-in viewer.
     * @param int         $video_id      The video being saved.
     */
    public function add(?int $user_id, ?string $visitor_token, int $video_id): bool
    {
        $key = self::key($user_id, $visitor_token, $video_id);

        if (isset($this->saves[ $key ])) {
            return false;
        }

        $this->saves[ $key ] = true;

        return true;
    }

    /**
     * {@inheritDoc}
     *
     * @param int|null    $user_id       The logged-in viewer's ID, or null for a guest.
     * @param string|null $visitor_token The guest's visitor token, or null for a logged-in viewer.
     * @param int         $video_id      The video being unsaved.
     */
    public function remove(?int $user_id, ?string $visitor_token, int $video_id): bool
    {
        $key = self::key($user_id, $visitor_token, $video_id);

        if (! isset($this->saves[ $key ])) {
            return false;
        }

        unset($this->saves[ $key ]);

        return true;
    }

    /**
     * {@inheritDoc}
     *
     * @param string $visitor_token The guest's cookie token being merged away.
     * @param int    $user_id       The member account absorbing the guest's saves.
     *
     * @return array{merged_video_ids: list<int>, duplicate_video_ids: list<int>}
     */
    public function merge_visitor_into_user(string $visitor_token, int $user_id): array
    {
        $prefix     = "guest:{$visitor_token}:";
        $prefix_len = strlen($prefix);
        $merged     = [];
        $duplicate  = [];

        foreach (array_keys($this->saves) as $key) {
            if (0 !== strpos($key, $prefix)) {
                continue;
            }

            $video_id = (int) substr($key, $prefix_len);
            $user_key = "user:{$user_id}:{$video_id}";

            unset($this->saves[ $key ]);

            if (isset($this->saves[ $user_key ])) {
                $duplicate[] = $video_id;

                continue;
            }

            $this->saves[ $user_key ] = true;
            $merged[]                 = $video_id;
        }

        return [
            'merged_video_ids'    => $merged,
            'duplicate_video_ids' => $duplicate,
        ];
    }

    /**
     * {@inheritDoc}
     *
     * @param int $user_id The member account.
     * @param int $limit   Maximum number of video IDs to return.
     *
     * @return list<int>
     */
    public function video_ids_for_user(int $user_id, int $limit): array
    {
        $prefix     = "user:{$user_id}:";
        $prefix_len = strlen($prefix);
        $video_ids  = [];

        foreach (array_keys($this->saves) as $key) {
            if (0 !== strpos($key, $prefix)) {
                continue;
            }

            $video_ids[] = (int) substr($key, $prefix_len);

            if (count($video_ids) >= $limit) {
                break;
            }
        }

        return $video_ids;
    }

    /**
     * Build this fake's internal identity+video key.
     *
     * @param int|null    $user_id       The logged-in viewer's ID, or null for a guest.
     * @param string|null $visitor_token The guest's visitor token, or null for a logged-in viewer.
     * @param int         $video_id      The video.
     */
    private static function key(?int $user_id, ?string $visitor_token, int $video_id): string
    {
        $identity = null !== $user_id ? "user:{$user_id}" : "guest:{$visitor_token}";

        return "{$identity}:{$video_id}";
    }
}
