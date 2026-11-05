<?php

namespace ConfigRepositoryMethod;

use Hypervel\Config\Repository;
use function PHPStan\Testing\assertType;

function test(Repository $config): void
{
    assertType('*ERROR*', $config->collection('foo'));
    assertType("Hypervel\Support\Collection<'guard'|'passwords', 'users'|'web'>", $config->collection('auth.defaults'));

    assertType('Hypervel\Support\Collection<0|1|2, 1|2|3>', $config->collection('test.bar'));
    assertType('*ERROR*', $config->collection('test.foo'));
}
