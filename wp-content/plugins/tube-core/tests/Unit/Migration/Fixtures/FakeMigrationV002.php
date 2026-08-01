<?php
/**
 * Test fixture: fake migration version 002.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Unit\Migration\Fixtures;

/**
 * Fake migration version 002.
 */
final class FakeMigrationV002 extends AbstractFakeMigration
{
    /**
     * {@inheritDoc}
     */
    public function version(): string
    {
        return '002';
    }
}
