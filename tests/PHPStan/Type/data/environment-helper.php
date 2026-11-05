<?php

namespace EnvironmentHelper;

use Hypervel\Support\Facades\App;

use function PHPStan\Testing\assertType;

function test(): void
{
    assertType('string', app()->environment());
    assertType('bool', app()->environment('local'));
    assertType('bool', app()->environment(['local', 'production']));
}

/**
 * Hypervel: unpacked arguments pass values only when the array is not empty.
 *
 * @param list<string> $maybeEmpty
 * @param non-empty-list<string> $nonEmpty
 * @param array{} $empty
 */
function unpackedArguments(array $maybeEmpty, array $nonEmpty, array $empty): void
{
    assertType('bool|string', app()->environment(...$maybeEmpty));
    assertType('bool', app()->environment(...$nonEmpty));
    assertType('string', app()->environment(...$empty));
    assertType('bool', app()->environment(...$maybeEmpty, ...['local']));
    assertType('bool', app()->environment(environments: 'local'));

    assertType('string', App::environment());
    assertType('bool', App::environment('local'));
    assertType('bool|string', App::environment(...$maybeEmpty));
}
