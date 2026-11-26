<?php

namespace QueryBuilderMethods;

use Hypervel\Database\Query\Builder;
use Hypervel\Database\Query\JoinClause;
use Hypervel\Support\Facades\DB;

use function PHPStan\Testing\assertType;

/**
 * @method string whereCustom(string $value)
 */
class CustomBuilder extends Builder
{
}

function test(Builder $query, JoinClause $join, CustomBuilder $customQuery): void
{
    // Dynamic wheres return the builder they were called on.
    assertType('Hypervel\Database\Query\Builder', $query->whereName('foo'));
    assertType('Hypervel\Database\Query\Builder', $query->whereNameAndEmail('foo', 'bar')->whereActive(true));
    assertType('Hypervel\Database\Query\Builder', DB::table('users')->whereName('foo'));
    assertType('Hypervel\Database\Query\JoinClause', $join->whereName('foo'));
    // Hypervel: a declared method keeps its signature.
    assertType('string', $customQuery->whereCustom('foo'));

    assertType('*ERROR*', $query->doesNotExist());

    // Macros take precedence, on instances and statically.
    assertType('Hypervel\Database\Query\Builder', $query->queryBuilderMacro());
    assertType('Hypervel\Database\Query\JoinClause', $join->queryBuilderMacro());
    assertType('string', Builder::globalCustomDatabaseQueryMacro(b: 99));
}
