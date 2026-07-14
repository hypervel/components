<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\PHPUnit\Fixtures;

use Hypervel\Testing\PHPUnit\AfterEachTestCleanup;
use Hypervel\Tests\TestCase;
use RuntimeException;

class FinalUnpreparedCleanupFixture extends TestCase
{
    /**
     * Register observable cleanup, then fail the final test during setup.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $path = getenv('HYPERVEL_FINAL_CLEANUP_MARKER');

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('The final-cleanup marker path is not configured.');
        }

        AfterEachTestCleanup::flushUsing(
            'final-unprepared-cleanup-fixture',
            static function () use ($path): void {
                file_put_contents($path, 'cleaned' . PHP_EOL, FILE_APPEND);
            },
        );

        throw new RuntimeException('intentional final setup error');
    }

    public function testFinalUnpreparedTestIsCleanedAtExecutionFinished(): void
    {
    }
}
