<?php
/**
 * Import queue item status.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Import;

/**
 * Import queue item status.
 *
 * A native backed enum in code even though the underlying
 * `wp_tube_import_queue.status` column is a plain MySQL `ENUM`, per
 * ARCHITECTURE.md §11 ("Status-like fields... are represented in PHP as
 * native backed enums"). Case values match the MySQL
 * `ENUM('pending','processing','completed','failed')` declared in
 * `Migration007CreateImportQueueTable` exactly.
 */
enum ImportStatus: string
{
    case Pending    = 'pending';
    case Processing = 'processing';
    case Completed  = 'completed';
    case Failed     = 'failed';
}
