<?php
/**
 * Unit tests for MigrationRunner.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Unit\Migration;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tube_Core\Migration\MigrationRunner;
use Tube_Core\Tests\Unit\Migration\Fixtures\FakeMigrationV001;
use Tube_Core\Tests\Unit\Migration\Fixtures\FakeMigrationV002;
use Tube_Core\Tests\Unit\Migration\Fixtures\FakeMigrationV003;
use Tube_Core\Tests\Unit\Migration\Fixtures\InMemorySchemaVersionRepository;
use Tube_Core\Tests\Unit\Migration\Fixtures\MigrationCallLog;

/**
 * Exercises MigrationRunner's orchestration logic in isolation, with a
 * fake SchemaVersionRepositoryInterface and fake migrations — no
 * WordPress or database dependency, per the "every plugin must be
 * independently testable" rule.
 */
final class MigrationRunnerTest extends TestCase
{
    /**
     * The fake repository under test's runner is wired to.
     *
     * @var InMemorySchemaVersionRepository
     */
    private InMemorySchemaVersionRepository $repository;

    /**
     * The runner under test, pre-registered with three fake tube-core migrations.
     *
     * @var MigrationRunner
     */
    private MigrationRunner $runner;

    /**
     * Reset shared fixture state and build a fresh runner for each test.
     */
    protected function setUp(): void
    {
        MigrationCallLog::reset();

        $this->repository = new InMemorySchemaVersionRepository();
        $this->runner     = new MigrationRunner($this->repository);

        $migration_classes = [
            FakeMigrationV001::class,
            FakeMigrationV002::class,
            FakeMigrationV003::class,
        ];

        $this->runner->register_source('tube-core', $migration_classes);
    }

    /**
     * Reflects every plugin that has registered a source.
     */
    public function test_registered_plugins_returns_every_registered_slug(): void
    {
        $this->runner->register_source('tube-search', [FakeMigrationV001::class]);

        self::assertSame(['tube-core', 'tube-search'], $this->runner->registered_plugins());
    }

    /**
     * Applies every pending migration in registration order.
     */
    public function test_migrate_up_applies_every_pending_migration_in_order(): void
    {
        $applied = $this->runner->migrate_up('tube-core');

        $expected_applied = [
            [
                'plugin_slug' => 'tube-core',
                'version'     => '001',
            ],
            [
                'plugin_slug' => 'tube-core',
                'version'     => '002',
            ],
            [
                'plugin_slug' => 'tube-core',
                'version'     => '003',
            ],
        ];
        self::assertSame($expected_applied, $applied);

        $expected_calls = [
            [
                'version' => '001',
                'action'  => 'up',
            ],
            [
                'version' => '002',
                'action'  => 'up',
            ],
            [
                'version' => '003',
                'action'  => 'up',
            ],
        ];
        self::assertSame($expected_calls, MigrationCallLog::$calls);

        self::assertSame(['001', '002', '003'], array_keys($this->repository->applied_versions('tube-core')));
    }

    /**
     * A second migrate_up() call is a no-op once everything is already applied.
     */
    public function test_migrate_up_is_idempotent_and_skips_already_applied_migrations(): void
    {
        $this->runner->migrate_up('tube-core');
        MigrationCallLog::reset();

        $second_run = $this->runner->migrate_up('tube-core');

        self::assertSame([], $second_run);
        self::assertSame([], MigrationCallLog::$calls);
    }

    /**
     * Passing a plugin slug limits migrate_up() to that plugin's migrations only.
     */
    public function test_migrate_up_can_be_limited_to_a_single_plugin(): void
    {
        $this->runner->register_source('tube-search', [FakeMigrationV001::class]);

        $applied = $this->runner->migrate_up('tube-core');

        self::assertSame('tube-core', $applied[0]['plugin_slug']);
        self::assertCount(3, $applied);
        self::assertSame([], $this->repository->applied_versions('tube-search'));
    }

    /**
     * The --to equivalent stops applying migrations once the target version is reached.
     */
    public function test_migrate_up_can_stop_at_a_target_version(): void
    {
        $applied = $this->runner->migrate_up('tube-core', '002');

        $expected = [
            [
                'plugin_slug' => 'tube-core',
                'version'     => '001',
            ],
            [
                'plugin_slug' => 'tube-core',
                'version'     => '002',
            ],
        ];
        self::assertSame($expected, $applied);

        self::assertArrayNotHasKey('003', $this->repository->applied_versions('tube-core'));
    }

    /**
     * With no plugin argument, migrate_up() applies every registered source.
     */
    public function test_migrate_up_without_a_plugin_argument_applies_every_registered_source(): void
    {
        $this->runner->register_source('tube-search', [FakeMigrationV001::class]);

        $applied = $this->runner->migrate_up();

        self::assertCount(4, $applied);
        self::assertSame(['001'], array_keys($this->repository->applied_versions('tube-search')));
    }

    /**
     * Rejects a plugin slug nothing was registered for.
     */
    public function test_migrate_up_throws_for_an_unregistered_plugin(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->runner->migrate_up('no-such-plugin');
    }

    /**
     * Reverts applied migrations newest-first, down to the target version.
     */
    public function test_migrate_down_rolls_back_to_the_target_version_in_reverse_order(): void
    {
        $this->runner->migrate_up('tube-core');
        MigrationCallLog::reset();

        $rolled_back = $this->runner->migrate_down('tube-core', '001');

        $expected_rolled_back = [
            [
                'plugin_slug' => 'tube-core',
                'version'     => '003',
            ],
            [
                'plugin_slug' => 'tube-core',
                'version'     => '002',
            ],
        ];
        self::assertSame($expected_rolled_back, $rolled_back);

        $expected_calls = [
            [
                'version' => '003',
                'action'  => 'down',
            ],
            [
                'version' => '002',
                'action'  => 'down',
            ],
        ];
        self::assertSame($expected_calls, MigrationCallLog::$calls);

        self::assertSame(['001'], array_keys($this->repository->applied_versions('tube-core')));
    }

    /**
     * The target version itself is left applied, not rolled back.
     */
    public function test_migrate_down_leaves_the_target_version_applied(): void
    {
        $this->runner->migrate_up('tube-core');

        $this->runner->migrate_down('tube-core', '002');

        self::assertSame(['001', '002'], array_keys($this->repository->applied_versions('tube-core')));
    }

    /**
     * Only reverts migrations that were actually applied.
     */
    public function test_migrate_down_only_rolls_back_migrations_that_were_actually_applied(): void
    {
        $this->runner->migrate_up('tube-core', '001');

        $rolled_back = $this->runner->migrate_down('tube-core', '001');

        self::assertSame([], $rolled_back);
    }

    /**
     * Rejects a plugin slug nothing was registered for, on rollback too.
     */
    public function test_migrate_down_throws_for_an_unregistered_plugin(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->runner->migrate_down('no-such-plugin', '001');
    }

    /**
     * Rejects a target version that isn't one of the registered ones.
     */
    public function test_migrate_down_throws_for_an_unregistered_version(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->runner->migrate_down('tube-core', '999');
    }

    /**
     * Reports every registered migration alongside its applied state.
     */
    public function test_status_reports_every_registered_migration_with_its_applied_state(): void
    {
        $this->runner->migrate_up('tube-core', '002');

        $status = $this->runner->status();

        $expected = [
            [
                'plugin_slug' => 'tube-core',
                'version'     => '001',
                'description' => 'Fake migration 001',
                'applied'     => true,
                'applied_at'  => '2026-01-01 00:00:00',
            ],
            [
                'plugin_slug' => 'tube-core',
                'version'     => '002',
                'description' => 'Fake migration 002',
                'applied'     => true,
                'applied_at'  => '2026-01-01 00:00:00',
            ],
            [
                'plugin_slug' => 'tube-core',
                'version'     => '003',
                'description' => 'Fake migration 003',
                'applied'     => false,
                'applied_at'  => null,
            ],
        ];

        self::assertSame($expected, $status);
    }
}
