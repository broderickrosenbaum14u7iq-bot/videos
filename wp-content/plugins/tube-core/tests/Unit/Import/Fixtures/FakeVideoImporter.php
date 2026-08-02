<?php
/**
 * Test fixture: a scriptable VideoImporterInterface.
 *
 * @package Tube_Core
 */

declare(strict_types=1);

namespace Tube_Core\Tests\Unit\Import\Fixtures;

use Throwable;
use Tube_Core\Import\VideoImporterInterface;

/**
 * A scriptable VideoImporterInterface — no WordPress. Queue up a
 * sequence of results (an `int` video ID to return, or a `Throwable` to
 * throw) via {@see self::$results}, consumed in call order — this is
 * what makes `BatchProcessor` unit-testable through both its success and
 * failure paths without ever touching `wp_insert_post()`.
 */
final class FakeVideoImporter implements VideoImporterInterface
{
    /**
     * Every import() call this fake received, in order.
     *
     * @var list<array{payload: array<string, mixed>}>
     */
    public array $import_calls = [];

    /**
     * Scripted results, consumed in call order.
     *
     * @var list<int|Throwable>
     */
    public array $results = [];

    /**
     * {@inheritDoc}
     *
     * @param array<string, mixed> $payload The queue item's payload.
     *
     * @throws Throwable Whatever was scripted via self::$results for this call.
     */
    public function import(array $payload): int
    {
        $this->import_calls[] = ['payload' => $payload];

        $result = array_shift($this->results);

        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result ?? 0;
    }
}
