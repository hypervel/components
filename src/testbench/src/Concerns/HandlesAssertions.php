<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Concerns;

use Closure;

/**
 * Provides assertion helpers for test cases.
 */
trait HandlesAssertions
{
    /**
     * Mark the test as skipped when condition is not equivalent to true.
     *
     * @param null|bool|(Closure($this): bool) $condition
     * @param mixed $condition
     */
    protected function markTestSkippedUnless($condition, string $message): void
    {
        /* @phpstan-ignore argument.type */
        if (! value($condition)) {
            $this->markTestSkipped($message);
        }
    }

    /**
     * Mark the test as skipped when condition is equivalent to true.
     *
     * @param null|bool|(Closure($this): bool) $condition
     * @param mixed $condition
     */
    protected function markTestSkippedWhen($condition, string $message): void
    {
        /* @phpstan-ignore argument.type */
        if (value($condition)) {
            $this->markTestSkipped($message);
        }
    }
}
