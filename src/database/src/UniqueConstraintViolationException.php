<?php

declare(strict_types=1);

namespace Hypervel\Database;

class UniqueConstraintViolationException extends QueryException
{
    /**
     * The unique index which prevented the query.
     */
    public ?string $index = null;

    /**
     * The columns which caused the violation.
     *
     * @var list<string>
     */
    public array $columns = [];

    /**
     * Set the unique index which caused the violation.
     */
    public function setIndex(?string $index): self
    {
        $this->index = $index;

        return $this;
    }

    /**
     * Set the columns that caused the violation.
     *
     * @param list<string> $columns
     */
    public function setColumns(array $columns): self
    {
        $this->columns = $columns;

        return $this;
    }
}
