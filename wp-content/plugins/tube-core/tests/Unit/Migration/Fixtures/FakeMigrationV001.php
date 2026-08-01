<?php
/**
 * Test fixture: fake migration version 001.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Unit\Migration\Fixtures;

/**
 * Fake migration version 001.
 */
final class FakeMigrationV001 extends AbstractFakeMigration
{
    /**
     * {@inheritDoc}
     */
    public function version(): string
    {
        return '001';
    }
}
