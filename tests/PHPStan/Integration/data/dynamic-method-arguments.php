<?php

namespace DynamicMethodArguments;

use App\User;
use Hypervel\Database\Query\Builder;
use Hypervel\Mail\Mailable;

function test(Builder $query, Mailable $mailable): void
{
    $query->whereName('alice');
    $query->whereNameAndEmail('alice', 'alice@example.com');
    $query->whereName();
    $query->whereNameAndEmail('alice');
    $query->whereName('alice', 'bob');
    $query->whereNameAndEmail('alice', value: 'alice@example.com');

    User::query()->whereNameOrEmail('alice', 'alice@example.com');
    User::query()->whereNameOrEmail('alice');
    User::whereEmail();

    $mailable->withFoo('bar');
    $mailable->withFoo();
    $mailable->withFoo(value: 'bar');
}
