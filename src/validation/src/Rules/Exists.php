<?php

declare(strict_types=1);

namespace Hypervel\Validation\Rules;

use Hypervel\Contracts\Container\Transient;
use Hypervel\Support\Traits\Conditionable;
use Stringable;

class Exists implements Stringable, Transient
{
    use Conditionable;
    use DatabaseRule;

    /**
     * Convert the rule to a validation string.
     */
    final public function __toString(): string
    {
        [$table, $column] = $this->queryParameters();

        return rtrim(sprintf(
            'exists:%s,%s,%s',
            $table,
            $column,
            $this->formatWheres()
        ), ',');
    }

    /**
     * Get the table and column used by both validation and string rendering.
     *
     * @return array{string, string}
     */
    public function queryParameters(): array
    {
        return [$this->table, $this->column];
    }
}
