<?php

namespace Bug1997;

use Hypervel\Database\Eloquent\Relations\BelongsToMany;

use function PHPStan\Testing\assertType;

interface User
{
    /** @return BelongsToMany<Team> */
    public function teams(): BelongsToMany;

    public function getKey();
}

interface Team {}


function test(User $user): void
{
    // Hypervel: a related type that is not a model forwards to the base builder; PHPStan reports the invalid type argument.
    assertType('Hypervel\Database\Eloquent\Relations\BelongsToMany<Bug1997\Team, Hypervel\Database\Eloquent\Relations\Pivot, \'pivot\'>', $user->teams()->whereKey($user->getKey()));
}
