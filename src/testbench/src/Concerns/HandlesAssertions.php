<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Concerns;

use Closure;

trait HandlesAssertions
{
    /**
     * Mark the test as skipped when condition is not equivalent to true.
     *
     * @param bool|(Closure(): bool) $condition
     */
    protected function markTestSkippedUnless(bool|Closure $condition, string $message): void
    {
        if (! value($condition)) {
            $this->markTestSkipped($message);
        }
    }

    /**
     * Mark the test as skipped when condition is equivalent to true.
     *
     * @param bool|(Closure(): bool) $condition
     */
    protected function markTestSkippedWhen(bool|Closure $condition, string $message): void
    {
        if (value($condition)) {
            $this->markTestSkipped($message);
        }
    }
}
