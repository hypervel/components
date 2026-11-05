<?php

namespace ConfigHelperFunction;

use function PHPStan\Testing\assertType;

// Unlike upstream, a key that is absent returns its default (null without one), a present
// key ignores the default, and env() keeps its declared bool|string|null return type.
function test(): void
{
    assertType('null', config('foo'));
    assertType('array{guard: \'web\', passwords: \'users\'}', config('auth.defaults'));

    assertType('array{1, 2, 3}', config('test.bar'));
    assertType("'bar'", config('test.foo'));
    assertType('null', config('test.foo.bar'));
    assertType('null', config('auth.defaults.missing'));
    assertType('bool|string|null', config('test.default'));

    assertType("'bar'", config('test.foo', 5));
    assertType('int', config('foo', 5));

    // Like value(), only a default that is itself a Closure is called.
    assertType('int', config('foo', fn (): int => 1));
    assertType('non-empty-array<string, Closure(): int>', config('foo', ['callback' => fn (): int => 1]));
    assertType('string', config('foo', 'strlen'));
}
