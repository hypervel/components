<?php

declare(strict_types=1);

namespace Hypervel\Testbench\PHPUnit;

use Hypervel\Testing\Assert as TestingAssert;

abstract class Assert extends TestingAssert
{
    /**
     * Mark the test as skipped when condition is not equivalent to true.
     */
    public static function markTestSkippedUnless(bool $condition, string $message = ''): void
    {
        if ($condition === false) {
            static::markTestSkipped($message);
        }
    }

    /**
     * Mark the test as skipped when condition is equivalent to true.
     */
    public static function markTestSkippedWhen(bool $condition, string $message = ''): void
    {
        if ($condition === true) {
            static::markTestSkipped($message);
        }
    }
}
