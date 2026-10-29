<?php

declare(strict_types=1);

namespace Hypervel\Types\View;

use Hypervel\Support\Collection;
use Hypervel\View\InvokableComponentVariable;
use User;

use function PHPStan\Testing\assertType;

$variable = new InvokableComponentVariable(fn () => true);

assertType('Hypervel\View\InvokableComponentVariable<true>', $variable);
assertType('true', $variable());

/**
 * Verify deferred component variable types.
 *
 * @param InvokableComponentVariable<bool> $flag
 * @param InvokableComponentVariable<array{string, int}> $pair
 * @param InvokableComponentVariable<null|string> $nullable
 * @param InvokableComponentVariable<User> $user
 * @param InvokableComponentVariable<Collection<int, User>> $users
 */
function test(
    InvokableComponentVariable $flag,
    InvokableComponentVariable $pair,
    InvokableComponentVariable $nullable,
    InvokableComponentVariable $untyped,
    InvokableComponentVariable $user,
    InvokableComponentVariable $users,
): void {
    assertType('bool', $flag());
    assertType('array{string, int}', $pair());
    assertType('string|null', $nullable());
    assertType('mixed', $untyped());
    assertType('mixed', $untyped->anything);

    assertType('string', $user->getTable());
    assertType('int<0, max>', $users->count());
    assertType('User|null', $users->first());
    assertType('ArrayIterator', $users->getIterator());

    acceptsNullableBool($flag);
}

/**
 * Accept a covariant nullable boolean variable.
 *
 * @param InvokableComponentVariable<null|bool> $variable
 */
function acceptsNullableBool(InvokableComponentVariable $variable): void
{
}
