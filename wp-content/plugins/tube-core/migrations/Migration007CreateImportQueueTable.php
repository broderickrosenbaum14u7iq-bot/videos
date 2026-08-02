<?php
/**
 * Creates wp_tube_import_queue.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\SchemaMigrations;

use Tube_Core\Migration\AbstractMigration;

/**
 * Creates wp_tube_import_queue — the durable bulk-import pipeline table,
 * per ARCHITECTURE.md §2.4/§12 Phase 5.
 *
 * `source_key` is the exact mechanism behind duplicate detection: a
 * `UNIQUE KEY`, so re-enqueuing the same source item (e.g. re-running an
 * import against the same feed) is a no-op at the database level, not
 * something application code has to remember to check for.
 * `ImportQueueRepository::bulk_enqueue()` uses `INSERT IGNORE` against
 * this constraint.
 *
 * `status`/`attempts`/`max_attempts` back retry: a failed item is
 * requeued (`status` back to `pending`) up to `max_attempts` times before
 * being marked `failed` permanently. `claimed_at` backs automatic resume:
 * a row stuck in `processing` (the worker that claimed it crashed or was
 * killed) is detected by its `claimed_at` being older than a stale
 * threshold and reclaimed as `pending` again, the next time
 * `import:process` runs, with no separate "resume" command needed.
 *
 * `payload` is the JSON the importer needs to create the video (title,
 * Cloudflare Stream UID, and any optional taxonomy/actor/studio
 * assignment) — kept as opaque JSON here, not individual columns, since
 * this table's job is queue bookkeeping, not being a second home for
 * video data (`wp_tube_video_metadata` already owns that per §2.1).
 */
final class Migration007CreateImportQueueTable extends AbstractMigration
{
    /**
     * {@inheritDoc}
     */
    public function version(): string
    {
        return '007';
    }

    /**
     * {@inheritDoc}
     */
    public function description(): string
    {
        return 'Create wp_tube_import_queue (durable bulk-import pipeline, §2.4).';
    }

    /**
     * {@inheritDoc}
     */
    public function up(): void
    {
        $table           = $this->db()->prefix . 'tube_import_queue';
        $charset_collate = $this->charset_collate();

        $this->apply_schema(
            "CREATE TABLE {$table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                source_key VARCHAR(191) NOT NULL,
                payload LONGTEXT NOT NULL,
                status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
                attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 5,
                last_error TEXT,
                video_id BIGINT UNSIGNED DEFAULT NULL,
                claimed_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY source_key_idx (source_key),
                KEY status_idx (status),
                KEY claimed_at_idx (claimed_at)
            ) {$charset_collate};"
        );
    }

    /**
     * {@inheritDoc}
     */
    public function down(): void
    {
        $this->drop_table($this->db()->prefix . 'tube_import_queue');
    }
}
