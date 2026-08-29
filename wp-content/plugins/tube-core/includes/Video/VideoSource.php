<?php
/**
 * Which backend a video's playable bytes come from.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Video;

/**
 * Which backend a video's playable bytes come from.
 *
 * A native backed enum in code even though the underlying
 * `wp_tube_video_metadata.source` column is a plain `VARCHAR`, the same
 * convention `CfStreamStatus` already establishes for `cf_status` (a
 * MySQL `ENUM`) — see that class's own docblock. `VARCHAR` rather than a
 * MySQL `ENUM` here specifically because a third source is a realistic
 * future addition (this project's own stated target architecture names
 * exactly two today, `cloudflare_stream`/`r2_mp4`, but doesn't rule out
 * more), and altering a MySQL `ENUM`'s value list is a real-schema-risk
 * `ALTER` dbDelta() can't express cleanly the way adding a third
 * `VARCHAR` value never requires touching the column definition at all.
 *
 * Every existing `wp_tube_video_metadata` row predates this column
 * (`Migration014AddVideoSourceColumn`'s `DEFAULT 'cloudflare_stream'`
 * backfills every one of them to this value with no data migration
 * needed) — existing Stream videos are never required to have a `source`
 * value explicitly re-saved to keep playing.
 */
enum VideoSource: string
{
    case CloudflareStream = 'cloudflare_stream';
    case R2Mp4            = 'r2_mp4';
}
