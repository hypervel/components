<?php

namespace CollectionNullsafeChain;

use App\User;
use Hypervel\Support\Collection;

use function PHPStan\Testing\assertType;

class Holder
{
    /** @param Collection<int, User|null> $users */
    public function __construct(public Collection $users)
    {
    }
}

function test(?Holder $holder): void
{
    assertType('Hypervel\Support\Collection<int, App\User>|null', $holder?->users->filter(fn (?User $user) => $user instanceof User));
    assertType('Hypervel\Support\Collection<int, App\User>|null', $holder?->users->filter(function (?User $user) {
        return $user instanceof User;
    }));
    assertType('Hypervel\Support\Collection<int, App\User>|null', $holder?->users->filter());
    assertType('Hypervel\Support\Collection<int, App\User>|null', $holder?->users->reject(fn (?User $user) => $user === null));
    assertType('Hypervel\Support\Collection<int, App\User>|null', $holder?->users->whereNotNull());
    assertType('Hypervel\Support\Collection<int, App\User>|null', $holder?->users->values()->filter(fn (?User $user) => $user instanceof User));
}
