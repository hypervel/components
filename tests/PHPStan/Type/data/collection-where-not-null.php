<?php

namespace CollectionWhereNotNull;

use Hypervel\Database\Eloquent\Collection as EloquentCollection;

use function PHPStan\Testing\assertType;

/** @param EloquentCollection<int, ?\App\User> $foo */
function test(EloquentCollection $foo): void
{
    assertType('Hypervel\Support\Collection<int, int|string>', collect([1, 2, null, '', 'hello'])->whereNotNull());
    assertType('Hypervel\Support\Collection<int, int|string>', collect([1, 2, null, '', 'hello'])->whereNotNull());
    assertType('Hypervel\Support\Collection<int, int|string>', collect([1, 2, null, '', 'hello'])->whereNotNull(null));
    assertType(
        'Hypervel\Support\Collection<int, App\User|array{id: \'foo\'}|stdClass>',
        collect([new \App\User, new \stdClass, ['id' => 'foo'], 'foo', true, 22])->whereNotNull('id')
    );
    assertType("Hypervel\Database\Eloquent\Collection<int, App\User>", $foo->whereNotNull('blocked'));
    assertType("Hypervel\Database\Eloquent\Collection<int, App\User>", $foo->whereNotNull('blocked'));
    assertType("Hypervel\Database\Eloquent\Collection<int, App\User>", $foo->whereNotNull());
}
