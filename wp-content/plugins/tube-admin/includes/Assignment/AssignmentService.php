<?php
/**
 * Orchestrates actor/studio assignment writes plus the resulting event dispatch.
 *
 * @package Tube_Admin
 */

declare(strict_types=1);

namespace Tube_Admin\Assignment;

use Tube_Core\Content\Repositories\ActorRepositoryInterface;
use Tube_Core\Content\Repositories\StudioRepositoryInterface;
use Tube_Core\Events\Dispatcher;
use Tube_Core\Events\EventCatalog;

/**
 * Orchestrates actor/studio assignment writes plus the resulting event
 * dispatch — the feature-owning layer ARCHITECTURE.md §6 describes:
 * `tube-core`'s repositories stay pure data access (no event side
 * effects, consistent with every other repository in the project);
 * `tube-admin` owns the actor/studio assignment *feature*, so it is the
 * one that calls `Tube_Core\Plugin::instance()->events()->dispatch()`
 * after a write, using tube-core's already-public accessor for exactly
 * this (`Plugin.php`'s own docblock: "Public so other tube-* plugins can
 * dispatch or listen for events... the same way self::migration_runner()
 * is exposed for their migration sets").
 *
 * `EventCatalog::VIDEO_UPDATED` already exists and is already subscribed
 * to by `tube-search` (re-syncs `actor_ids`/`studio_ids` into the search
 * index — the exact fix `PHASE-8.md` §3.2 made `VideoIndexer` do) and
 * `tube-cache` (purges the video's cached detail/listing keys) — no new
 * event was needed for this phase's assignment feature.
 *
 * Depends on `ActorRepositoryInterface`/`StudioRepositoryInterface`/
 * `Dispatcher` (constructor-injected, per `DEVELOPMENT_RULES.md` §2)
 * rather than reaching into `Tube_Core\Plugin::instance()` itself
 * mid-method, matching the same constructor-injection shape every other
 * cross-plugin-coupled orchestrator in this project uses
 * (`Tube_Seo\Sitemap\SitemapGenerator`, `Tube_Core\Stream\StreamStatusUpdater`).
 * These are tube-core interfaces/classes, not tube-admin's own, so this
 * class is WordPress/tube-core-coupled throughout and integration-tested
 * only, the same split those classes already established — a unit test
 * against fakes isn't possible here without a Composer-level dependency
 * on tube-core's package, which every plugin in this project deliberately
 * avoids (`tube-player/composer.json`'s own description: "Depends on
 * tube-core at runtime only, not at the Composer level"). The two screens
 * that use it (`VideoDetailsScreen`, `BulkToolsScreen`) construct it via
 * `Tube_Admin\Plugin::assignment_service()`, which is where the real
 * `Tube_Core\Plugin::instance()->actor_repository()`/`events()` wiring happens.
 */
final class AssignmentService
{
    /**
     * Construct the service with tube-core's repositories/dispatcher.
     *
     * @param ActorRepositoryInterface  $actor_repository  Tube-core's actor data access.
     * @param StudioRepositoryInterface $studio_repository Tube-core's studio data access.
     * @param Dispatcher                $events            Tube-core's event dispatcher.
     */
    public function __construct(
        private readonly ActorRepositoryInterface $actor_repository,
        private readonly StudioRepositoryInterface $studio_repository,
        private readonly Dispatcher $events
    ) {
    }

    /**
     * Replace one video's actor assignments and notify subscribers.
     *
     * @param int   $video_id  The video post ID.
     * @param int[] $actor_ids The actor IDs this video should be assigned to.
     */
    public function set_actors_for_video(int $video_id, array $actor_ids): void
    {
        $this->actor_repository->replace_for_video($video_id, $actor_ids);
        $this->notify_updated($video_id);
    }

    /**
     * Replace one video's studio assignments and notify subscribers.
     *
     * @param int   $video_id   The video post ID.
     * @param int[] $studio_ids The studio IDs this video should be assigned to.
     */
    public function set_studios_for_video(int $video_id, array $studio_ids): void
    {
        $this->studio_repository->replace_for_video($video_id, $studio_ids);
        $this->notify_updated($video_id);
    }

    /**
     * Bulk-add actors to several videos and notify subscribers for every affected video.
     *
     * @param int[] $video_ids The video post IDs to add actors to.
     * @param int[] $actor_ids The actor IDs to add.
     *
     * @return int The number of (video, actor) rows actually inserted.
     */
    public function bulk_add_actors(array $video_ids, array $actor_ids): int
    {
        $inserted = $this->actor_repository->bulk_add($video_ids, $actor_ids);
        $this->notify_updated_many($video_ids);

        return $inserted;
    }

    /**
     * Bulk-remove actors from several videos and notify subscribers for every affected video.
     *
     * @param int[] $video_ids The video post IDs to remove actors from.
     * @param int[] $actor_ids The actor IDs to remove.
     *
     * @return int The number of (video, actor) rows actually deleted.
     */
    public function bulk_remove_actors(array $video_ids, array $actor_ids): int
    {
        $deleted = $this->actor_repository->bulk_remove($video_ids, $actor_ids);
        $this->notify_updated_many($video_ids);

        return $deleted;
    }

    /**
     * Bulk-add studios to several videos and notify subscribers for every affected video.
     *
     * @param int[] $video_ids  The video post IDs to add studios to.
     * @param int[] $studio_ids The studio IDs to add.
     *
     * @return int The number of (video, studio) rows actually inserted.
     */
    public function bulk_add_studios(array $video_ids, array $studio_ids): int
    {
        $inserted = $this->studio_repository->bulk_add($video_ids, $studio_ids);
        $this->notify_updated_many($video_ids);

        return $inserted;
    }

    /**
     * Bulk-remove studios from several videos and notify subscribers for every affected video.
     *
     * @param int[] $video_ids  The video post IDs to remove studios from.
     * @param int[] $studio_ids The studio IDs to remove.
     *
     * @return int The number of (video, studio) rows actually deleted.
     */
    public function bulk_remove_studios(array $video_ids, array $studio_ids): int
    {
        $deleted = $this->studio_repository->bulk_remove($video_ids, $studio_ids);
        $this->notify_updated_many($video_ids);

        return $deleted;
    }

    /**
     * Dispatch VIDEO_UPDATED for one video.
     *
     * @param int $video_id The video post ID.
     */
    private function notify_updated(int $video_id): void
    {
        $this->events->dispatch(EventCatalog::VIDEO_UPDATED, ['video_id' => $video_id]);
    }

    /**
     * Dispatch VIDEO_UPDATED for every video in a list.
     *
     * @param int[] $video_ids The video post IDs to notify for.
     */
    private function notify_updated_many(array $video_ids): void
    {
        foreach (array_unique($video_ids) as $video_id) {
            $this->notify_updated($video_id);
        }
    }
}
