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
     *
     * @return $this
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
     * @return $this
     */
    public function setColumns(array $columns): self
    {
        $this->columns = $columns;

        return $this;
    }
}
