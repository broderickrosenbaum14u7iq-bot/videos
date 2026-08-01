<?php
/**
 * Test fixture: fake migration version 003.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Unit\Migration\Fixtures;

/**
 * Fake migration version 003.
 */
final class FakeMigrationV003 extends AbstractFakeMigration
{
    /**
     * {@inheritDoc}
     */
    public function version(): string
    {
        return '003';
    }
}
