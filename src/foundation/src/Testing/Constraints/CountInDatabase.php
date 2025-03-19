<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Constraints;

use Hyperf\DbConnection\Connection;
use PHPUnit\Framework\Constraint\Constraint;
use ReflectionClass;

class CountInDatabase extends Constraint
{
    /**
     * The expected table entries count that will be checked against the actual count.
     */
    protected ?int $expectedCount;

    /**
     * The actual table entries count that will be checked against the expected count.
     */
    protected ?int $actualCount;

    /**
     * Create a new constraint instance.
     */
    public function __construct(
        protected Connection $database,
        int $expectedCount
    ) {
        $this->expectedCount = $expectedCount;
    }

    /**
     * Check if the expected and actual count are equal.
     *
     * @param string $table
     */
    public function matches($table): bool
    {
        $this->actualCount = $this->database->table($table)->count();

        return $this->actualCount === $this->expectedCount;
    }

    /**
     * Get the description of the failure.
     *
     * @param string $table
     */
    public function failureDescription($table): string
    {
        return sprintf(
            "table [%s] matches expected entries count of %s. Entries found: %s.\n",
            $table,
            $this->expectedCount,
            $this->actualCount
        );
    }

    /**
     * Get a string representation of the object.
     *
     * @param int $options
     */
    public function toString($options = 0): string
    {
        return (new ReflectionClass($this))->name;
    }
}
