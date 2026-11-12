<?php

namespace CollectionStubsL1220;

use App\User;
use function PHPStan\Testing\assertType;

function test(): void
{
    assertType('Hypervel\Support\Collection<(int|string), non-falsy-string>', User::get()->pluck(fn (User $user) => "$user->id - $user->email", 'id'));
    assertType('Hypervel\Support\Collection<string, mixed>', User::get()->pluck('id', fn (User $user) => "$user->id - $user->email"));
    // Unlike upstream, the value is the name column: User::email() is an accessor Eloquent applies, so email reads as its untyped callback.
    assertType('Hypervel\Support\Collection<string, string>', User::get()->pluck(fn (User $user) => $user->name, fn (User $user) => "$user->id - $user->email"));
}
