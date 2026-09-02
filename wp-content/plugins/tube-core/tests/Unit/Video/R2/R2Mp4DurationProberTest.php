<?php
/**
 * Unit tests for R2Mp4DurationProber.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Unit\Video\R2;

use PHPUnit\Framework\TestCase;
use Tube_Core\Video\R2\R2Mp4DurationProber;

/**
 * Exercises `duration_from_chunk()` — the pure, WordPress-independent
 * byte-parsing core of the prober — against synthetic ISO-BMFF `moov`/
 * `mvhd` buffers covering both `mvhd` versions, the "not found in this
 * chunk" cases a real tail/front-only read regularly hits, and the
 * sanity-check that guards against a coincidental byte-marker match.
 * The network-calling half ({@see R2Mp4DurationProber::probe()}) is
 * WordPress-coupled (`wp_remote_get()`) and is covered by this
 * project's live end-to-end verification instead, the same split
 * already applied to every other WP-coupled class in this plugin.
 */
final class R2Mp4DurationProberTest extends TestCase
{
    /**
     * A version-0 `mvhd` (32-bit fields): timescale 1000, duration
     * 125000 -> 125 seconds. Preceded by unrelated "ftyp"-shaped filler
     * bytes and a `moov` box header, mirroring a real front-of-file
     * ("faststart") layout.
     */
    public function test_finds_version_0_mvhd_in_front_of_file_layout(): void
    {
        $chunk = str_repeat("\x00", 40)
            . "\x00\x00\x00\x08ftyp"
            . self::moov_box(self::mvhd_v0(1000, 125000));

        self::assertSame(125, R2Mp4DurationProber::duration_from_chunk($chunk));
    }

    /**
     * A version-1 `mvhd` (64-bit fields): timescale 90000, duration
     * 8100000 -> 90 seconds.
     */
    public function test_finds_version_1_mvhd_with_64_bit_fields(): void
    {
        $chunk = str_repeat("\x00", 12) . self::moov_box(self::mvhd_v1(90000, 8100000));

        self::assertSame(90, R2Mp4DurationProber::duration_from_chunk($chunk));
    }

    /**
     * An arbitrary tail byte range: the chunk starts mid-`mdat`, not at
     * any box boundary -- exactly what a real "moov-at-end" file's last-
     * N-bytes Range read looks like. The marker search must still find
     * `moov`/`mvhd` even though nothing here is aligned to a box start.
     */
    public function test_finds_mvhd_in_unaligned_tail_chunk(): void
    {
        $chunk = random_bytes(300) . self::moov_box(self::mvhd_v0(30000, 4530000));

        self::assertSame(151, R2Mp4DurationProber::duration_from_chunk($chunk));
    }

    /**
     * A chunk with neither marker (e.g. a chunk that only covers `mdat`
     * bytes when `moov` lives outside this particular read) parses to
     * null, never a false duration.
     */
    public function test_returns_null_when_moov_marker_is_absent(): void
    {
        self::assertNull(R2Mp4DurationProber::duration_from_chunk(random_bytes(512)));
    }

    /**
     * `moov` present but no `mvhd` within the search window (a
     * malformed/truncated capture) parses to null rather than reading
     * garbage past the buffer.
     */
    public function test_returns_null_when_mvhd_marker_is_absent(): void
    {
        $chunk = str_repeat("\x00", 20) . "\x00\x00\x00\x08moov" . str_repeat("\x00", 40);

        self::assertNull(R2Mp4DurationProber::duration_from_chunk($chunk));
    }

    /**
     * A zero timescale (never valid, but a possible outcome of a
     * coincidental byte match) is rejected rather than causing a
     * division-by-zero or a bogus result.
     */
    public function test_returns_null_for_zero_timescale(): void
    {
        $chunk = self::moov_box(self::mvhd_v0(0, 12345));

        self::assertNull(R2Mp4DurationProber::duration_from_chunk($chunk));
    }

    /**
     * A parsed duration far outside any plausible video length (the
     * guard against a coincidental `"moov"`/`"mvhd"` match inside
     * unrelated binary data) is rejected.
     */
    public function test_returns_null_for_implausibly_large_duration(): void
    {
        // timescale=1, duration=huge -> far past the 30-day sanity cap.
        $chunk = self::moov_box(self::mvhd_v0(1, 999_999_999));

        self::assertNull(R2Mp4DurationProber::duration_from_chunk($chunk));
    }

    /**
     * Builds a minimal `moov` box header immediately followed by the
     * given `mvhd` box bytes (as `moov`'s first child, the real-world
     * layout this class's own docblock relies on).
     */
    private static function moov_box(string $mvhd): string
    {
        return "\x00\x00\x00\x08moov" . $mvhd;
    }

    /**
     * A version-0 `mvhd` box: 4-byte creation/modification/timescale/duration fields.
     */
    private static function mvhd_v0(int $timescale, int $duration): string
    {
        $content = "\x00\x00\x00\x00" // version 0 + flags
            . pack('N', 0)             // creation_time
            . pack('N', 0)             // modification_time
            . pack('N', $timescale)
            . pack('N', $duration)
            . "\x00\x01\x00\x00";      // rate/volume filler, unread

        return pack('N', 8 + strlen($content)) . 'mvhd' . $content;
    }

    /**
     * A version-1 `mvhd` box: 8-byte creation/modification/duration fields, 4-byte timescale.
     */
    private static function mvhd_v1(int $timescale, int $duration): string
    {
        $content = "\x01\x00\x00\x00"  // version 1 + flags
            . pack('J', 0)              // creation_time
            . pack('J', 0)              // modification_time
            . pack('N', $timescale)
            . pack('J', $duration)
            . "\x00\x01\x00\x00";       // filler, unread

        return pack('N', 8 + strlen($content)) . 'mvhd' . $content;
    }
}
