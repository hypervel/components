<?php

namespace ConfigRepositoryMethod;

use Hypervel\Config\Repository;
use function PHPStan\Testing\assertType;

// Unlike upstream, only the application's repository has the application's values.
function test(Repository $config): void
{
    assertType('*ERROR*', config()->collection('foo'));
    assertType("Hypervel\Support\Collection<'guard'|'passwords', 'users'|'web'>", config()->collection('auth.defaults'));

    assertType('Hypervel\Support\Collection<0|1|2, 1|2|3>', config()->collection('test.bar'));
    assertType('*ERROR*', config()->collection('test.foo'));
    assertType('Hypervel\Support\Collection<(int|string), mixed>', $config->collection('test.foo'));
}
