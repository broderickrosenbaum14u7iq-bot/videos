<?php
/**
 * Extracts an MP4's real duration via small, bounded HTTP Range reads — never a full download.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Video\R2;

/**
 * Reads just enough of an MP4 container to find its `moov`/`mvhd` box and
 * compute a real duration, without ever downloading the file body proper
 * — the zero/low-bandwidth mechanism `R2VideoValidator`'s own docblock
 * previously assumed didn't exist. At most three bounded Range reads
 * (a front chunk; then, only if that didn't contain a usable `mvhd` and
 * the file is large enough for a further read to cover new bytes, a
 * small tail chunk and — only if that one also comes up empty — one
 * larger tail chunk) — every request also carries `limit_response_size`
 * as a hard cap, so even a server that ignores the `Range` header can
 * never make any single read pull more than one chunk's worth of bytes.
 *
 * ISO-BMFF layout background: an MP4 without "faststart" processing —
 * the common case for the phone-recorded/downloader-sourced clips this
 * project actually ingests — places its `moov` box (the only box that
 * carries duration) at the very end of the file, after the often
 * multi-gigabyte `mdat` box; a "faststart"-optimized file places it at
 * the front instead. Trying the front first is the cheap case (skips
 * every tail request entirely when it hits); falling back to the tail
 * covers the far more common real-world case for this project's
 * content — and the *larger* tail fallback specifically covers a real
 * pattern observed in this project's own actual R2 videos: several
 * hundred KB of trailing `udta`/`meta`/`ilst` metadata (embedded cover
 * art/tags a downloader tool wrote) sitting between `mdat` and `moov`'s
 * own `mvhd` child, which the smaller tail chunk alone can miss.
 *
 * Byte-marker search (not a strict box-by-box walk) locates `moov`/
 * `mvhd`: a walk requires starting exactly at a box boundary, which an
 * arbitrary tail byte range can never guarantee, while a literal
 * `"moov"`/`"mvhd"` text search works identically regardless of where
 * the fetched window happens to start. The tiny false-positive risk
 * (those 4 ASCII bytes coincidentally appearing inside unrelated binary
 * data) is bounded by {@see self::sane_duration_seconds()}'s range
 * check on the final parsed result — a coincidental match essentially
 * never decodes to a plausible duration.
 */
final class R2Mp4DurationProber
{
    /**
     * Size of the front-of-file Range read, in bytes — the cheap
     * "faststart" fast path; a `moov` placed at the front has no large
     * trailing metadata pushing it further in, so a small read is
     * always enough there.
     */
    private const FRONT_CHUNK_BYTES = 262144; // 256 KiB.

    /**
     * Size of the first tail-of-file Range read — covers a `moov`-at-
     * end file with little or no trailing metadata (the common case).
     */
    private const TAIL_CHUNK_SMALL_BYTES = 262144; // 256 KiB.

    /**
     * Size of the fallback, larger tail-of-file Range read, tried only
     * when the small tail chunk didn't contain a usable `mvhd` — real
     * files from this project's own actual content have been observed
     * with several hundred KB of trailing `udta`/`meta`/`ilst` metadata
     * (embedded cover art/tags a downloader tool wrote) between `mdat`
     * and `moov`'s own `mvhd` child, which a 256 KiB tail alone can
     * miss entirely. Still trivial next to "downloading a multi-
     * gigabyte file" -- a handful of MiB at most, and only fetched at
     * all when the cheaper 256 KiB attempt already failed.
     */
    private const TAIL_CHUNK_LARGE_BYTES = 4 * 1024 * 1024; // 4 MiB.

    /**
     * Hard ceiling passed as `limit_response_size` to every request —
     * the real safety guarantee (not the `Range` header, which a
     * misbehaving server could ignore): WordPress's HTTP API stops
     * reading the response body at this many bytes no matter what the
     * server sends or claims to send. Sized for the largest of the
     * three possible reads.
     */
    private const MAX_RESPONSE_BYTES = self::TAIL_CHUNK_LARGE_BYTES + 4096;

    /**
     * How long to wait for each Range request before giving up.
     */
    private const TIMEOUT_SECONDS = 10;

    /**
     * How far past a found `"moov"` marker to search for `"mvhd"` —
     * `mvhd` is virtually always `moov`'s first child, so it appears
     * shortly after `moov`'s own header; bounding the search window
     * this way (rather than scanning to the end of the chunk) stops an
     * unrelated, later "mvhd"-shaped byte sequence outside the real
     * `moov` box's own content from ever being picked up instead.
     */
    private const MVHD_SEARCH_WINDOW_BYTES = 4096;

    /**
     * Reject a parsed duration outside this range as a probable
     * coincidental `"moov"`/`"mvhd"` byte match rather than a real box —
     * generous enough (30 days) to never reject genuine content.
     */
    private const MAX_PLAUSIBLE_DURATION_SECONDS = 30 * 24 * 3600;

    /**
     * Probe `$url` for its real duration.
     *
     * @param string $url The fully-resolved, already-reachable (signed if applicable) playback URL.
     *
     * @return int|null The duration in whole seconds, or null if it could not be determined — never throws; the
     *                   caller is always safe to treat null exactly like "no duration known yet".
     */
    public function probe(string $url): ?int
    {
        $front = self::fetch_range($url, 0, self::FRONT_CHUNK_BYTES - 1);

        if (null === $front) {
            return null;
        }

        $duration = self::duration_from_chunk($front['body']);

        if (null !== $duration) {
            return $duration;
        }

        $total_size = $front['total_size'];

        if (null === $total_size || $total_size <= self::FRONT_CHUNK_BYTES) {
            // Either the real total size couldn't be determined, or the
            // front chunk already covered the entire file -- a further
            // request would just re-read bytes already scanned.
            return null;
        }

        foreach ([self::TAIL_CHUNK_SMALL_BYTES, self::TAIL_CHUNK_LARGE_BYTES] as $tail_chunk_bytes) {
            $tail_chunk_bytes = min($tail_chunk_bytes, $total_size);
            $tail_start       = $total_size - $tail_chunk_bytes;
            $tail             = self::fetch_range($url, $tail_start, $total_size - 1);

            if (null === $tail) {
                continue;
            }

            $duration = self::duration_from_chunk($tail['body']);

            if (null !== $duration) {
                return $duration;
            }

            if ($tail_chunk_bytes >= $total_size) {
                // This read already covered everything from here to EOF
                // -- a larger tail chunk on the next iteration couldn't
                // possibly find anything the smaller one didn't.
                break;
            }
        }

        return null;
    }

    /**
     * One bounded Range GET.
     *
     * @param string $url   The URL to fetch a byte range from.
     * @param int    $start First byte offset, inclusive.
     * @param int    $end   Last byte offset, inclusive.
     *
     * @return array{body: string, total_size: int|null}|null Null on any failure (network error, or a status
     *                                                         other than 206/200).
     */
    private static function fetch_range(string $url, int $start, int $end): ?array
    {
        $response = wp_remote_get(
            $url,
            [
                'timeout'             => self::TIMEOUT_SECONDS,
                'limit_response_size' => self::MAX_RESPONSE_BYTES,
                'headers'             => [
                    'Range' => "bytes={$start}-{$end}",
                ],
            ]
        );

        if (is_wp_error($response)) {
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);

        if (206 !== $code && 200 !== $code) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);

        if ('' === $body) {
            return null;
        }

        return [
            'body'       => $body,
            'total_size' => self::total_size_from_headers($response),
        ];
    }

    /**
     * The real total object size, from whichever header actually
     * carries it for the response received: `Content-Range`'s
     * `/total` suffix for a 206, or `Content-Length` for a 200 (only
     * reachable when the whole object is smaller than
     * {@see self::CHUNK_SIZE_BYTES}, so that header value is the true
     * total in that case too, never a client-truncated one).
     *
     * @param array<string, mixed>|object $response The raw `wp_remote_get()` return value.
     */
    private static function total_size_from_headers($response): ?int
    {
        $content_range = wp_remote_retrieve_header($response, 'content-range');

        if (is_string($content_range) && 1 === preg_match('#/(\d+)$#', $content_range, $matches)) {
            return (int) $matches[1];
        }

        $content_length = wp_remote_retrieve_header($response, 'content-length');

        return is_string($content_length) && is_numeric($content_length) ? (int) $content_length : null;
    }

    /**
     * Search one fetched chunk for `moov`/`mvhd` and parse a duration
     * out of it, or null if this chunk doesn't contain a usable `mvhd`.
     *
     * Public/static purely so this pure, WordPress-independent parsing
     * core (the part that actually needs correctness coverage) is
     * directly unit-testable against synthetic byte buffers, the same
     * "real-unit-testable, no WP bootstrap" posture already applied to
     * this feature's own {@see R2MediaUrlNormalizer}.
     *
     * @param string $chunk Raw bytes from one {@see self::fetch_range()} call.
     */
    public static function duration_from_chunk(string $chunk): ?int
    {
        $moov_marker = strpos($chunk, 'moov');

        if (false === $moov_marker) {
            return null;
        }

        $search_window = substr($chunk, $moov_marker, self::MVHD_SEARCH_WINDOW_BYTES);
        $mvhd_marker   = strpos($search_window, 'mvhd');

        if (false === $mvhd_marker) {
            return null;
        }

        $mvhd_content_start = $moov_marker + $mvhd_marker + 4;

        if (! isset($chunk[$mvhd_content_start])) {
            return null;
        }

        $version = ord($chunk[$mvhd_content_start]);

        if (1 === $version) {
            $timescale_offset = $mvhd_content_start + 4 + 8 + 8;
            $duration_offset  = $timescale_offset + 4;
            $duration_bytes   = 8;
        } else {
            $timescale_offset = $mvhd_content_start + 4 + 4 + 4;
            $duration_offset  = $timescale_offset + 4;
            $duration_bytes   = 4;
        }

        if (! isset($chunk[$duration_offset + $duration_bytes - 1])) {
            return null;
        }

        $timescale = self::read_uint_be($chunk, $timescale_offset, 4);
        $duration  = self::read_uint_be($chunk, $duration_offset, $duration_bytes);

        if (null === $timescale || $timescale <= 0 || null === $duration || $duration <= 0) {
            return null;
        }

        $seconds = (int) round($duration / $timescale);

        return self::sane_duration_seconds($seconds) ? $seconds : null;
    }

    /**
     * Read a big-endian unsigned integer of `$byte_count` bytes (4 or 8) from `$bytes` at `$offset`.
     *
     * @param string $bytes      The buffer to read from.
     * @param int    $offset     Byte offset to start reading at.
     * @param int    $byte_count Either 4 or 8.
     */
    private static function read_uint_be(string $bytes, int $offset, int $byte_count): ?int
    {
        $slice = substr($bytes, $offset, $byte_count);

        if (strlen($slice) !== $byte_count) {
            return null;
        }

        $unpacked = unpack(4 === $byte_count ? 'N' : 'J', $slice);

        return false === $unpacked ? null : $unpacked[1];
    }

    /**
     * Whether `$seconds` is plausible enough to trust as a real duration.
     *
     * @param int $seconds The parsed candidate duration.
     */
    private static function sane_duration_seconds(int $seconds): bool
    {
        return $seconds > 0 && $seconds <= self::MAX_PLAUSIBLE_DURATION_SECONDS;
    }
}
