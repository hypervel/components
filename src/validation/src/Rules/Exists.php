<?php

declare(strict_types=1);

namespace Hypervel\Validation\Rules;

use Hypervel\Support\Traits\Conditionable;
use Hypervel\Validation\Contracts\DatabasePresenceRule;
use Stringable;

class Exists implements DatabasePresenceRule, Stringable
{
    use Conditionable;
    use DatabaseRule;

    /**
     * Convert the rule to a validation string.
     */
    public function __toString(): string
    {
        return rtrim(sprintf(
            'exists:%s,%s,%s',
            $this->table,
            $this->column,
            $this->formatWheres()
        ), ',');
    }
}
