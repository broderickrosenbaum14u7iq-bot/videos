<?php
/**
 * Unit tests for EventCatalog.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Unit\Events;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tube_Core\Events\EventCatalog;

/**
 * Exercises EventCatalog's own internal consistency.
 */
final class EventCatalogTest extends TestCase
{
    /**
     * Every constant is listed in all(), and vice versa — a new event
     * constant added without updating all() would silently be
     * unlistenable, since Dispatcher validates against all() only.
     */
    public function test_all_contains_every_declared_constant(): void
    {
        $reflection = new ReflectionClass(EventCatalog::class);
        $constants  = array_values($reflection->getConstants());

        sort($constants);
        $all = EventCatalog::all();
        sort($all);

        self::assertSame($constants, $all);
    }

    /**
     * Every event name is unique — a duplicate would mean two constants
     * silently share one WordPress hook.
     */
    public function test_all_contains_no_duplicates(): void
    {
        $all = EventCatalog::all();

        self::assertSame(array_unique($all), $all);
    }

    /**
     * Every event name carries the tube_core. prefix, per EVENTS.md's
     * collision-avoidance rule.
     */
    public function test_every_event_name_is_prefixed(): void
    {
        foreach (EventCatalog::all() as $event) {
            self::assertStringStartsWith('tube_core.', $event);
        }
    }
}
