<?php

namespace Bug1346;

use Hypervel\Database\Eloquent\Builder;

use function PHPStan\Testing\assertType;

/** @template TModel of \Hypervel\Database\Eloquent\Model */
class DailyQuery
{
    /** @var Builder<TModel> */
    private Builder $query;

    public function daily()
    {
        assertType('Hypervel\Database\Eloquent\Builder<TModel of Hypervel\Database\Eloquent\Model (class Bug1346\DailyQuery, argument)>', $this->query->select());
    }
}
