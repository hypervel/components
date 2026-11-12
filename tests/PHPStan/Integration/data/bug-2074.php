<?php

namespace Bug2074;

use Hypervel\Database\Eloquent\Builder;

use function PHPStan\Testing\assertType;

/** @param Builder<*> $query */
function test(Builder $query): void
{
    assertType('Hypervel\Database\Eloquent\Builder<*>', $query->where('foo', 'bar'));
}
