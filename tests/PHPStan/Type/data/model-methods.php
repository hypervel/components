<?php

namespace ModelMethods;

use App\User;

use function PHPStan\Testing\assertType;

// Unlike upstream, email reads as mixed because User's untyped email() accessor takes effect, and
// an unknown key reads as mixed because it can be a selected alias.
function test(User $user): void
{
    assertType('array{name: string, email: mixed}', $user->only('name', 'email'));
    assertType('array{name: string, email: mixed}', $user->only(['name', 'email']));
    assertType('array{name: string, nonexistent: mixed}', $user->only(['name', 'nonexistent']));
    assertType('array<string, mixed>', $user->only(['name', $foo]));

    $columns = ['name', 'email'];
    $foo = 'nonexistent';
    assertType('array{name: string, email: mixed}', $user->only(...$columns));
    assertType('array{name: string, email: mixed}', $user->only($columns));
    assertType('array{nonexistent: mixed}', $user->only($foo));
    assertType(
        'array{name: string, email: mixed, nonexistent: mixed}',
        $user->only([...$columns, $foo]),
    );
}

/**
 * @param list<'id'> $ids
 * @param \Iterator<int, 'id'> $iterator
 */
function testOnlyAlternatives(User $user, OnlyOverride $override, bool $flag, array $ids, \Iterator $iterator): void
{
    assertType('array{name: string, id?: int}', $user->only('name', ...$iterator));
    assertType('array{id: int}|array{name: string}', $user->only($flag ? 'name' : 'id'));
    assertType('array{id: int}|array{name: string}', $user->only($flag ? ['name'] : ['id']));
    assertType('array{name: string, id?: int, email?: mixed}', $user->only('name', $flag ? 'id' : 'email'));
    assertType('array{name: string, id?: int}', $user->only('name', ...$ids));
    assertType('array<string, mixed>', $user->only(...$ids));
    assertType('array{1: mixed}', $user->only('1'));
    assertType('array<string, mixed>', $override->only('name'));
}

class OnlyOverride extends User
{
    /**
     * Get a subset of the model's attributes.
     *
     * @return array<string, mixed>
     */
    public function only(mixed $attributes): array
    {
        return parent::only($attributes);
    }
}
