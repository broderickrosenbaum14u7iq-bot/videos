<?php
/**
 * Test fixture: a fake migration that records its own execution.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Unit\Migration\Fixtures;

use Tube_Core\Migration\MigrationInterface;

/**
 * A fake migration that records its own execution via MigrationCallLog
 * instead of touching a real database — this is what makes MigrationRunner
 * unit-testable without WordPress or MySQL.
 */
abstract class AbstractFakeMigration implements MigrationInterface
{
    /**
     * {@inheritDoc}
     */
    public function description(): string
    {
        return 'Fake migration ' . $this->version();
    }

    /**
     * {@inheritDoc}
     */
    public function up(): void
    {
        MigrationCallLog::record($this->version(), 'up');
    }

    /**
     * {@inheritDoc}
     */
    public function down(): void
    {
        MigrationCallLog::record($this->version(), 'down');
    }
}
