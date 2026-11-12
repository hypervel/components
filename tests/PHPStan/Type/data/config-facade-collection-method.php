<?php

namespace ConfigFacadeCollectionMethod;

use Hypervel\Support\Facades\Config;
use function PHPStan\Testing\assertType;

function test(): void
{
    assertType('*ERROR*', Config::collection('foo'));
    assertType("Hypervel\Support\Collection<'guard'|'passwords', 'users'|'web'>", Config::collection('auth.defaults'));

    assertType('Hypervel\Support\Collection<0|1|2, 1|2|3>', Config::collection('test.bar'));
    assertType('*ERROR*', Config::collection('test.foo'));
}
