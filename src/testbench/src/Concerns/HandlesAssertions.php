<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Concerns;

use Closure;
use Hypervel\Testbench\PHPUnit\Assert;

trait HandlesAssertions
{
    /**
     * Mark the test as skipped when condition is not equivalent to true.
     *
     * @param bool|(Closure(): bool) $condition
     */
    protected function markTestSkippedUnless(bool|Closure $condition, string $message): void
    {
        Assert::markTestSkippedUnless(value($condition), $message);
    }

    /**
     * Mark the test as skipped when condition is equivalent to true.
     *
     * @param bool|(Closure(): bool) $condition
     */
    protected function markTestSkippedWhen(bool|Closure $condition, string $message): void
    {
        Assert::markTestSkippedWhen(value($condition), $message);
    }
}
