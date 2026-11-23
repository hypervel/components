<?php

namespace Passthru;

use App\User;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;

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
    // Unlike upstream, the query builder declares the returned rows.
    assertType('Hypervel\Support\Collection<int, object>', User::query()->insertOrIgnoreReturning([]));
    assertType('int', User::query()->insertOrIgnoreUsing(['name'], 'select 1'));
    assertType('float|int', User::query()->numericAggregate('sum', ['id']));
    assertType('mixed', User::query()->rawValue('max(id)'));
}

class Post extends Model
{
}

/** @extends Builder<Post> */
class PassthruBuilder extends Builder
{
    public function test(): void
    {
        assertType('Hypervel\Support\Collection<int, object>', $this->insertOrIgnoreReturning([]));
    }
}
