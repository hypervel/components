<?php

declare(strict_types=1);

namespace QueryBuilder;

use Hypervel\Support\Facades\DB;

use function PHPStan\Testing\assertType;

function test(): void
{
    $record = DB::table('user')->pluck('email', 'id');
    assertType('Hypervel\Support\Collection<(int|string), mixed>', $record);
    assertType('Hypervel\Support\LazyCollection<int, stdClass>', DB::table('user')->cursor());

    $subQuery = DB::table('addresses')
        ->select(['id', 'user_id']);

    $builder = DB::table('users')
        ->joinSub($subQuery, 'addresses', 'users.user_id', '=', DB::raw('addresses.user_id'));

    assertType('Hypervel\Database\Query\Builder', $builder);

    $builder = DB::table('users')
        ->where(DB::raw('id'), '=', 1);

    assertType('Hypervel\Database\Query\Builder', $builder);

    $builder = DB::table('users')
        ->where(DB::raw('id'), '=', 1)
        ->orWhere(DB::raw('id'), '=', 2);

    assertType('Hypervel\Database\Query\Builder', $builder);

    $builder = DB::table('users')
        ->whereNull(DB::raw('email_verified_at'));

    assertType('Hypervel\Database\Query\Builder', $builder);

    $builder = DB::table('users')
        ->whereNotNull(DB::raw('email_verified_at'));

    assertType('Hypervel\Database\Query\Builder', $builder);

    $builder = DB::table('users')
        ->whereBetween('id', new \ArrayObject([1, 2]));

    assertType('Hypervel\Database\Query\Builder', $builder);

    // Unlike upstream, the query builder's TValue types a found row.
    assertType('stdClass|null', DB::table('users')->find(1, [DB::raw('email_verified_at')]));
    assertType('mixed', DB::table('users')->aggregate('sum', [DB::raw('id')]));
    assertType('float|int', DB::table('users')->numericAggregate('sum', [DB::raw('id')]));
    // Unlike upstream, these are not numeric strings: PostgreSQL returns interval and money results as text.
    assertType('float|int|string', DB::table('users')->sum(DB::raw('id')));
    assertType('float|int|string|null', DB::table('users')->avg(DB::raw('id')));
    assertType('float|int|string|null', DB::table('users')->average(DB::raw('id')));
    // Unlike upstream, the bindings are keyed by the builder's TBindingType, which custom builders can extend.
    assertType("array<'from'|'groupBy'|'having'|'join'|'order'|'select'|'union'|'unionOrder'|'where', list<mixed>>", DB::table('users')->getRawBindings());
}
