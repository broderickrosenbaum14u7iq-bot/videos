<?php
/**
 * Test fixture: an in-memory ViewCounterInterface.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Unit\Views\Fixtures;

use Tube_Core\Views\ViewCounterInterface;

/**
 * An in-memory ViewCounterInterface — no Redis, no WordPress.
 */
final class InMemoryViewCounter implements ViewCounterInterface
{
    /**
     * Buffered counts, video ID => count.
     *
     * @var array<int, int>
     */
    private array $buffer = [];

    /**
     * {@inheritDoc}
     *
     * @param int $video_id The video that was viewed.
     */
    public function record(int $video_id): void
    {
        $this->buffer[ $video_id ] = ($this->buffer[ $video_id ] ?? 0) + 1;
    }

    /**
     * {@inheritDoc}
     *
     * @return array<int, int> Video ID => buffered view count.
     */
    public function flush(): array
    {
        $result       = $this->buffer;
        $this->buffer = [];

        return $result;
    }
}
