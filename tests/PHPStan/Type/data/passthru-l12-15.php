<?php

namespace Passthru;

use App\User;

use function PHPStan\Testing\assertType;

function test() {
    assertType('int<0, max>', User::query()->getCountForPagination());
    assertType('mixed', User::query()->aggregate('sum', ['id']));
    assertType('mixed', User::query()->doesntExistOr(fn () => 'x'));
    assertType('mixed', User::query()->existsOr(fn () => 'x'));
    assertType('Hypervel\Support\Collection', User::query()->explain());
    // Unlike upstream, the bindings are keyed by the builder's TBindingType, which custom builders can extend.
    assertType("array<'from'|'groupBy'|'having'|'join'|'order'|'select'|'union'|'unionOrder'|'where', list<mixed>>", User::query()->getRawBindings());
    assertType('string', User::query()->implode('name'));
    assertType('int', User::query()->insertOrIgnoreUsing(['name'], 'select 1'));
    assertType('float|int', User::query()->numericAggregate('sum', ['id']));
    assertType('mixed', User::query()->rawValue('max(id)'));
}
