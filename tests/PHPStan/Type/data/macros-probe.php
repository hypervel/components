<?php

namespace MacrosProbe;

use App\User;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;

use function PHPStan\Testing\assertType;

/**
 * @param Collection<int, User> $users
 * @param Builder<User> $q
 */
function test(Collection $users, Builder $q): void
{
    assertType("'literal'", $users->probeUntyped());
    assertType('Hypervel\Support\Collection<int, decimal-int-string>', $users->probeNativeNarrowed());
    assertType('Hypervel\Support\Collection<int, App\User>', $users->probeThisGeneric());
    assertType('App\User|null', $users->probeThisFirst());
    assertType('Hypervel\Support\Collection<int, int>', $users->probeThisKeys());
    assertType('Hypervel\Support\Collection<*NEVER*, *NEVER*>', $users->probeStatic());
    assertType('Hypervel\Support\Collection<*NEVER*, *NEVER*>', Collection::probeStatic());
    assertType('array{int, mixed}', $users->probeParam(1, 'a'));
    assertType('mixed', $users->probeRecursive(3));
    assertType('App\User|null', $users->probeCallsOther());
    assertType('mixed', $users->probeUse());
    assertType('mixed', $users->probeSameLineA());
    // Hypervel: the registered closure is nested in another on its lines, so its declared signature is used.
    assertType('mixed', $users->probeNested());
    // Hypervel: the body is read with the names of where the closure is written.
    assertType("array{string, 'local', 'Hypervel\\\\Tests\\\\PHPStan\\\\Fixtures\\\\Macros', 'Hypervel\\\\Support\\\\Collection'}", $users->probeLexical());
    // Hypervel: __CLASS__ names the class the closure is written in, not the macro's class, so the declared signature is used.
    assertType('mixed', $users->probeLexicalClass());
    assertType('int', $users->probeFirstClass('abc'));
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', $q);
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', User::query());
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', $q->probeBuilderThis());
    assertType('App\User|null', $q->probeBuilderFirst());
    assertType('App\User|null', User::query()->probeBuilderFirst());
    // Hypervel: a static model call forwards to a builder of that model.
    assertType('App\User|null', User::probeBuilderFirst());
    assertType('Hypervel\Support\Stringable', Str::probeStr('a'));
    assertType('Hypervel\Support\Collection<int, App\User>', $users->probeTypedThis());
    assertType('array<int, App\User>', $users->probeTypedArray());
    assertType('App\User|null', $users->probeTypedMixed());
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', $q->probeTypedBuilder());
    assertType('App\User|null', $q->probeTypedModel());
    assertType('string', Str::trimMacro('a'));
    assertType('int', Str::probeLen('a'));
    assertType('array|int', Str::probeCount('a'));
    assertType('string', Str::trimMacro('a'));
}
