<?php
/**
 * Cloudflare Stream encoding status.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Video;

/**
 * Cloudflare Stream encoding status.
 *
 * A native backed enum in code even though the underlying
 * `wp_tube_video_metadata.cf_status` column is a plain MySQL `ENUM`, per
 * ARCHITECTURE.md §11 ("Status-like fields... are represented in PHP as
 * native backed enums, even though the underlying MySQL column remains a
 * plain ENUM/VARCHAR — the enum lives in code, not just in the schema").
 * Case values match the MySQL `ENUM('pending','processing','ready','error')`
 * declared in `Migration001CreateVideoMetadataTable` exactly.
 */
enum CfStreamStatus: string
{
    case Pending    = 'pending';
    case Processing = 'processing';
    case Ready      = 'ready';
    case Error      = 'error';
}
