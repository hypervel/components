<?php

declare(strict_types=1);

namespace Hypervel\Coroutine\Exception;

use RuntimeException;
use Throwable;

/**
 * Exception thrown when parallel execution encounters one or more errors.
 *
 * This exception aggregates all throwables from failed parallel tasks
 * while also preserving successful results.
 */
class ParallelExecutionException extends RuntimeException
{
    protected array $results = [];

    /**
     * @var array<array-key, Throwable>
     */
    protected array $throwables = [];

    /**
     * Get the successful results from parallel execution.
     */
    public function getResults(): array
    {
        return $this->results;
    }

    /**
     * Set the successful results from parallel execution.
     */
    public function setResults(array $results): void
    {
        $this->results = $results;
    }

    /**
     * Get the throwables from failed parallel tasks.
     *
     * @return array<array-key, Throwable>
     */
    public function getThrowables(): array
    {
        return $this->throwables;
    }

    /**
     * Set the throwables from failed parallel tasks.
     *
     * @param array<array-key, Throwable> $throwables
     * @return array<array-key, Throwable>
     */
    public function setThrowables(array $throwables): array
    {
        return $this->throwables = $throwables;
    }
}
