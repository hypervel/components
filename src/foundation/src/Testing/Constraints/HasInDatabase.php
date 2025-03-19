<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Constraints;

use Hyperf\Database\Query\Expression;
use Hyperf\DbConnection\Connection;
use PHPUnit\Framework\Constraint\Constraint;

class HasInDatabase extends Constraint
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
        protected array $data
    ) {
    }

    /**
     * Check if the data is found in the given table.
     *
     * @param string $table
     */
    public function matches($table): bool
    {
        return $this->database->table($table)->where($this->data)->count() > 0;
    }

    /**
     * Get the description of the failure.
     *
     * @param string $table
     */
    public function failureDescription($table): string
    {
        return sprintf(
            "a row in the table [%s] matches the attributes %s.\n\n%s",
            $table,
            $this->toString(JSON_PRETTY_PRINT),
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

        $similarResults = $query->where(
            array_key_first($this->data),
            $this->data[array_key_first($this->data)]
        )->limit($this->show)->get();

        if ($similarResults->isNotEmpty()) {
            $description = 'Found similar results: ' . json_encode($similarResults, JSON_PRETTY_PRINT);
        } else {
            $query = $this->database->table($table);

            $results = $query->limit($this->show)->get();

            if ($results->isEmpty()) {
                return 'The table is empty.';
            }

            $description = 'Found: ' . json_encode($results, JSON_PRETTY_PRINT);
        }

        if ($query->count() > $this->show) {
            $description .= sprintf(' and %s others', $query->count() - $this->show);
        }

        return $description;
    }

    /**
     * Get a string representation of the object.
     *
     * @param int $options
     */
    public function toString($options = 0): string
    {
        foreach ($this->data as $key => $data) {
            $output[$key] = $data instanceof Expression ? (string) $data : $data;
        }

        // since phpunit 10 it will pass options in boolean
        // we need to cast it to int
        return json_encode($output ?? [], (int) $options);
    }
}
