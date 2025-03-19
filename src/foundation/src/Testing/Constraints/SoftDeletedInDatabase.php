<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Constraints;

use Hyperf\DbConnection\Connection;
use PHPUnit\Framework\Constraint\Constraint;

class SoftDeletedInDatabase extends Constraint
{
    /**
     * Number of records that will be shown in the console in case of failure.
     */
    protected int $show = 3;

    /**
     * Create a new constraint instance.
     */
    public function __construct(
        protected Connection $database,
        array $data,
        string $deletedAtColumn
    ) {
    }

    /**
     * Check if the data is found in the given table.
     *
     * @param string $table
     */
    public function matches($table): bool
    {
        return $this->database->table($table)
            ->where($this->data)
            ->whereNotNull($this->deletedAtColumn)
            ->count() > 0;
    }

    /**
     * Get the description of the failure.
     *
     * @param string $table
     */
    public function failureDescription($table): string
    {
        return sprintf(
            "any soft deleted row in the table [%s] matches the attributes %s.\n\n%s",
            $table,
            $this->toString(),
            $this->getAdditionalInfo($table)
        );
    }

    /**
     * Get additional info about the records found in the database table.
     *
     * @param string $table
     * @return string
     */
    protected function getAdditionalInfo($table)
    {
        $query = $this->database->table($table);

        $results = $query->limit($this->show)->get();

        if ($results->isEmpty()) {
            return 'The table is empty';
        }

        $description = 'Found: ' . json_encode($results, JSON_PRETTY_PRINT);

        if ($query->count() > $this->show) {
            $description .= sprintf(' and %s others', $query->count() - $this->show);
        }

        return $description;
    }

    /**
     * Get a string representation of the object.
     */
    public function toString(): string
    {
        return json_encode($this->data);
    }
}
