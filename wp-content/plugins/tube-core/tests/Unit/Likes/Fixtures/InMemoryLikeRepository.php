<?php
/**
 * Test fixture: an in-memory LikeRepositoryInterface.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Unit\Likes\Fixtures;

use Tube_Core\Likes\Repositories\LikeRepositoryInterface;

/**
 * An in-memory LikeRepositoryInterface backed by a real in-memory set of
 * (identity, video_id) pairs — genuinely enforces "one active like per
 * viewer/video" and reports true/false for add()/remove() exactly like
 * the real `UNIQUE KEY`-backed repository would, so `LikeToggleServiceTest`
 * exercises real race-safety-relevant behavior, not a dumb call recorder.
 */
final class InMemoryLikeRepository implements LikeRepositoryInterface
{
    /**
     * Every currently-liked (identity, video_id) pair, as `"identity:video_id"` strings.
     *
     * @var array<string, true>
     */
    private array $likes = [];

    /**
     * {@inheritDoc}
     *
     * @param int|null    $user_id       The logged-in viewer's ID, or null for a guest.
     * @param string|null $visitor_token The guest's visitor token, or null for a logged-in viewer.
     * @param int         $video_id      The video to check.
     */
    public function has_liked(?int $user_id, ?string $visitor_token, int $video_id): bool
    {
        return isset($this->likes[ self::key($user_id, $visitor_token, $video_id) ]);
    }

    /**
     * {@inheritDoc}
     *
     * @param int|null    $user_id       The logged-in viewer's ID, or null for a guest.
     * @param string|null $visitor_token The guest's visitor token, or null for a logged-in viewer.
     * @param int         $video_id      The video being liked.
     */
    public function add(?int $user_id, ?string $visitor_token, int $video_id): bool
    {
        $key = self::key($user_id, $visitor_token, $video_id);

        if (isset($this->likes[ $key ])) {
            return false;
        }

        $this->likes[ $key ] = true;

        return true;
    }

    /**
     * {@inheritDoc}
     *
     * @param int|null    $user_id       The logged-in viewer's ID, or null for a guest.
     * @param string|null $visitor_token The guest's visitor token, or null for a logged-in viewer.
     * @param int         $video_id      The video being unliked.
     */
    public function remove(?int $user_id, ?string $visitor_token, int $video_id): bool
    {
        $key = self::key($user_id, $visitor_token, $video_id);

        if (! isset($this->likes[ $key ])) {
            return false;
        }

        unset($this->likes[ $key ]);

        return true;
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
        $prefix     = "guest:{$visitor_token}:";
        $prefix_len = strlen($prefix);
        $merged     = [];
        $duplicate  = [];

        foreach (array_keys($this->likes) as $key) {
            if (0 !== strpos($key, $prefix)) {
                continue;
            }

            $video_id = (int) substr($key, $prefix_len);
            $user_key = "user:{$user_id}:{$video_id}";

            unset($this->likes[ $key ]);

            if (isset($this->likes[ $user_key ])) {
                $duplicate[] = $video_id;

                continue;
            }

            $this->likes[ $user_key ] = true;
            $merged[]                 = $video_id;
        }

        return [
            'merged_video_ids'    => $merged,
            'duplicate_video_ids' => $duplicate,
        ];
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
