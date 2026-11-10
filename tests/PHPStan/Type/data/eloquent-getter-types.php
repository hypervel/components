<?php

namespace EloquentGetterTypes;

use App\User;

use function PHPStan\Testing\assertType;

function test(User $user): void
{
    assertType('array<string, string>', $user->getCasts());
    // Hypervel: setTouchedRelations() and setAppends() accept any array of names.
    assertType('array<string>', $user->getTouchedRelations());
    assertType('array<string>', $user->getAppends());
    assertType('list<string>', $user->getMutatedAttributes());
}
