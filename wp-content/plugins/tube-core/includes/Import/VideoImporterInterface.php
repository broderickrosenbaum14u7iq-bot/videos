<?php
/**
 * Contract for turning one import queue item's payload into a video.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Import;

use InvalidArgumentException;
use RuntimeException;

/**
 * Contract for turning one import queue item's payload into a video.
 *
 * Adopted per the interface-justification rule (ARCHITECTURE.md §19.1):
 * `VideoImporter` calls `wp_insert_post()`/`wp_set_object_terms()`
 * directly, so it cannot run without WordPress loaded — the real payoff
 * of this interface is a test fake `BatchProcessor` is unit-tested
 * against, exercising both the success and failure paths through it
 * without ever touching WordPress, the same pattern every other
 * WordPress-coupled dependency in this project uses (`ViewCounterInterface`,
 * `HookBusInterface`, every `*RepositoryInterface`).
 */
interface VideoImporterInterface
{
    /**
     * Import one video from a queue item's payload.
     *
     * @param array<string, mixed> $payload The queue item's payload.
     *
     * @return int The video post ID — either newly created, or the existing one if already imported.
     *
     * @throws InvalidArgumentException|RuntimeException If the payload is invalid, or creating the video post fails.
     */
    public function import(array $payload): int;
}
