<?php
/**
 * Unit tests for StreamMetadataSyncer.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Unit\Stream;

use PHPUnit\Framework\TestCase;
use Tube_Core\Events\Dispatcher;
use Tube_Core\Events\EventCatalog;
use Tube_Core\Stream\StreamMetadataSyncer;
use Tube_Core\Stream\StreamStatusUpdater;
use Tube_Core\Tests\Unit\Events\Fixtures\RecordingHookBus;
use Tube_Core\Tests\Unit\Stream\Fixtures\FakeStreamDetailsProvider;
use Tube_Core\Tests\Unit\Video\Fixtures\InMemoryVideoMetadataRepository;
use Tube_Core\Video\CfStreamStatus;
use Tube_Core\Video\StreamDetails;

/**
 * Exercises StreamMetadataSyncer against a fake Cloudflare Stream lookup
 * and a real `StreamStatusUpdater` wired to fakes (the same
 * `InMemoryVideoMetadataRepository`/`RecordingHookBus`/`Dispatcher` combo
 * `StreamStatusUpdaterTest` already uses) — no network, no WordPress.
 * Composing the real `StreamStatusUpdater`, rather than a fake/mock of
 * it, is deliberate: the whole point of this class is that a successful
 * sync goes through the *exact* same apply-and-announce logic a webhook
 * delivery does (event dispatch included), so the test proves that by
 * construction rather than by asserting a mock was called correctly.
 *
 * Deliberately never exercises `CfStreamStatus::Ready` as the *new*
 * status, for the exact reason `StreamStatusUpdaterTest`'s own docblock
 * gives: that transition triggers `StreamStatusUpdater::maybe_publish()`,
 * which calls `get_post()`/`wp_update_post()` directly and cannot run
 * without WordPress loaded — verified live and via integration tests
 * instead (`StreamUidMetaBoxIntegrationTest`).
 */
final class StreamMetadataSyncerTest extends TestCase
{
    /**
     * The fake Cloudflare Stream lookup the syncer under test reads from.
     *
     * @var FakeStreamDetailsProvider
     */
    private FakeStreamDetailsProvider $details_provider;

    /**
     * The fake metadata repository the real StreamStatusUpdater writes to.
     *
     * @var InMemoryVideoMetadataRepository
     */
    private InMemoryVideoMetadataRepository $metadata_repository;

    /**
     * The fake hook bus the real StreamStatusUpdater's dispatcher is wired to.
     *
     * @var RecordingHookBus
     */
    private RecordingHookBus $hook_bus;

    /**
     * The syncer under test.
     *
     * @var StreamMetadataSyncer
     */
    private StreamMetadataSyncer $syncer;

    /**
     * Build a fresh syncer (composing a real StreamStatusUpdater against fakes) for each test.
     */
    protected function setUp(): void
    {
        $this->details_provider    = new FakeStreamDetailsProvider();
        $this->metadata_repository = new InMemoryVideoMetadataRepository();
        $this->hook_bus            = new RecordingHookBus();

        $status_updater = new StreamStatusUpdater($this->metadata_repository, new Dispatcher($this->hook_bus));
        $this->syncer   = new StreamMetadataSyncer($this->details_provider, $status_updater);
    }

    /**
     * A successful lookup reporting a real status change applies it and
     * dispatches VIDEO_STREAM_STATUS_CHANGED — the same event a webhook
     * delivery would, so tube-search's index sync/tube-cache's purge
     * subscriber react identically regardless of which path produced it.
     */
    public function test_successful_lookup_applies_status_and_announces_a_real_change(): void
    {
        $this->metadata_repository->seed('uid-1', 42, CfStreamStatus::Pending);
        $this->details_provider->seed('uid-1', new StreamDetails(CfStreamStatus::Processing, 137));

        $result = $this->syncer->sync('uid-1');

        self::assertTrue($result);
        self::assertSame(CfStreamStatus::Processing, $this->metadata_repository->status_for(42));
        self::assertSame(
            [
                [
                    'hook'    => EventCatalog::VIDEO_STREAM_STATUS_CHANGED,
                    'payload' => [
                        'video_id' => 42,
                        'status'   => 'processing',
                    ],
                ],
            ],
            $this->hook_bus->dispatched
        );
    }

    /**
     * A lookup reporting the same status the video already has, with a
     * newly-known duration, applies the duration AND announces
     * VIDEO_STREAM_STATUS_CHANGED — this is exactly what a resync of an
     * already-`Ready` video (backfilling a duration never fetched before)
     * looks like; without the announcement, tube-search's index sync/
     * tube-cache's purge subscriber would never see it.
     */
    public function test_lookup_reporting_the_same_status_with_new_duration_updates_and_announces(): void
    {
        $this->metadata_repository->seed('uid-1', 42, CfStreamStatus::Processing);
        $this->details_provider->seed('uid-1', new StreamDetails(CfStreamStatus::Processing, 200));

        $result = $this->syncer->sync('uid-1');

        self::assertTrue($result);
        self::assertSame(
            [
                [
                    'video_id'         => 42,
                    'status'           => CfStreamStatus::Processing,
                    'duration_seconds' => 200,
                ],
            ],
            $this->metadata_repository->update_status_calls
        );
        self::assertSame(
            [
                [
                    'hook'    => EventCatalog::VIDEO_STREAM_STATUS_CHANGED,
                    'payload' => [
                        'video_id' => 42,
                        'status'   => 'processing',
                    ],
                ],
            ],
            $this->hook_bus->dispatched
        );
    }

    /**
     * An unrecognized UID (not seeded on the fake — the same shape a real
     * 404 from Cloudflare's API produces) leaves the repository untouched
     * and reports failure, never writing a wrong/guessed status.
     */
    public function test_unrecognized_uid_leaves_existing_data_untouched(): void
    {
        $this->metadata_repository->seed('uid-1', 42, CfStreamStatus::Pending);

        $result = $this->syncer->sync('a-uid-that-does-not-exist-on-cloudflare');

        self::assertFalse($result);
        self::assertSame([], $this->metadata_repository->update_status_calls);
        self::assertSame(CfStreamStatus::Pending, $this->metadata_repository->status_for(42));
        self::assertSame([], $this->hook_bus->dispatched);
    }

    /**
     * An unconfigured/unreachable Cloudflare account (fetch() returns
     * null for the same reason a 404 would) is indistinguishable to this
     * class from an unrecognized UID, by design — both leave existing
     * data untouched, per StreamDetailsProviderInterface::fetch()'s own
     * "never corrupt on failure" contract.
     */
    public function test_unconfigured_provider_leaves_existing_data_untouched(): void
    {
        $this->metadata_repository->seed('uid-1', 42, CfStreamStatus::Processing);
        // FakeStreamDetailsProvider deliberately not seeded for 'uid-1' here
        // — this simulates CloudflareStreamDetailsFetcher::fetch() returning
        // null because no account_id/api_token is configured.

        $result = $this->syncer->sync('uid-1');

        self::assertFalse($result);
        self::assertSame([], $this->metadata_repository->update_status_calls);
        self::assertSame(CfStreamStatus::Processing, $this->metadata_repository->status_for(42));
    }

    /**
     * A successful Cloudflare lookup for a UID no video's metadata
     * actually references (StreamStatusUpdater::handle() throws
     * InvalidArgumentException in that case) is caught and treated as a
     * failure, not allowed to propagate — this class's own contract is
     * "return false on any failure, never throw."
     */
    public function test_successful_lookup_for_an_unregistered_uid_is_treated_as_failure(): void
    {
        $this->details_provider->seed('uid-not-in-our-database', new StreamDetails(CfStreamStatus::Processing, 90));

        $result = $this->syncer->sync('uid-not-in-our-database');

        self::assertFalse($result);
        self::assertSame([], $this->hook_bus->dispatched);
    }

    /**
     * Sync() always calls fetch() with the exact UID it was given.
     */
    public function test_sync_calls_fetch_with_the_given_uid(): void
    {
        $this->metadata_repository->seed('uid-1', 42, CfStreamStatus::Pending);

        $this->syncer->sync('uid-1');

        self::assertSame(['uid-1'], $this->details_provider->fetch_calls);
    }
}
