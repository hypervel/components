<?php

namespace ModelPropertyDynamicKeys;

use App\User;

function fill(User $user, string $column): void
{
    $user->forceFill([$column => 'value']);
    User::query()->update([$column => 'value']);
    $user->forceFill(['missing_column' => 'value']);
}
