<?php
/**
 * Test fixture: a fake StreamDetailsProviderInterface.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Unit\Stream\Fixtures;

use Tube_Core\Stream\StreamDetailsProviderInterface;
use Tube_Core\Video\StreamDetails;

/**
 * A fake StreamDetailsProviderInterface — no network, no WordPress.
 * Seeded per-UID with either a real StreamDetails result or an implicit
 * failure (any UID not seeded returns null, the same "could not be
 * determined" contract the real Cloudflare-backed implementation uses
 * for every one of its own failure modes).
 */
final class FakeStreamDetailsProvider implements StreamDetailsProviderInterface
{
    /**
     * Seeded results, keyed by Cloudflare Stream UID.
     *
     * @var array<string, StreamDetails>
     */
    private array $details_by_uid = [];

    /**
     * Every UID this fake's fetch() was called with, in order.
     *
     * @var list<string>
     */
    public array $fetch_calls = [];

    /**
     * Seed a successful result for one UID.
     *
     * @param string        $cf_stream_uid The Cloudflare Stream UID.
     * @param StreamDetails $details The result fetch() should return for it.
     */
    public function seed(string $cf_stream_uid, StreamDetails $details): void
    {
        $this->details_by_uid[ $cf_stream_uid ] = $details;
    }

    /**
     * {@inheritDoc}
     *
     * @param string $cf_stream_uid The Cloudflare Stream UID to look up.
     */
    public function fetch(string $cf_stream_uid): ?StreamDetails
    {
        $this->fetch_calls[] = $cf_stream_uid;

        return $this->details_by_uid[ $cf_stream_uid ] ?? null;
    }
}
