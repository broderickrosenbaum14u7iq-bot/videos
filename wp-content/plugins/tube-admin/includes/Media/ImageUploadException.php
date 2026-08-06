<?php
/**
 * Thrown when a Cloudflare Images upload/delete request fails.
 *
 * @package Tube_Admin
 */

declare(strict_types=1);

namespace Tube_Admin\Media;

use RuntimeException;

/**
 * Thrown when a Cloudflare Images upload/delete request fails — network
 * failure, non-2xx response, or a malformed response body. The message
 * is written to be safe for an admin-facing notice (no raw API response
 * body or credentials embedded in it).
 */
final class ImageUploadException extends RuntimeException
{
}
