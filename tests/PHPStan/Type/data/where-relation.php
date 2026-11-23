<?php

namespace WhereRelation;

use Hypervel\Database\Eloquent\Model;

use function PHPStan\Testing\assertType;

class Field extends Model
{
}

function test(): void
{
    $query = Field::query();
    assertType('Hypervel\Database\Eloquent\Builder<WhereRelation\Field>', $query);

    $query = $query->whereRelation('orderTypes', 'order_types.id', '=', 1);
    assertType('Hypervel\Database\Eloquent\Builder<WhereRelation\Field>', $query);

    $collection = $query->get();
    assertType('Hypervel\Database\Eloquent\Collection<int, WhereRelation\Field>', $collection);
}
