<?php

declare(strict_types=1);

use function Hypervel\Support\enum_value;
use function PHPStan\Testing\assertType;

assertType("'foo'", value('foo', 42));
assertType('42', value(fn () => 42));
assertType('42', value(function ($foo) {
    assertType('true', $foo);

    return 42;
}, true));

assertType("'foo'", when(true, 'foo'));
assertType("'foo'", when(true, 'foo', 42));
assertType('null', when(false, 'foo'));
assertType('42', when(false, 'foo', 42));
assertType("'foo'", when(true, fn () => 'foo'));
assertType("'foo'", when(true, fn () => 'foo', fn () => 42));
assertType('null', when(false, fn () => 'foo'));
assertType('42', when(false, fn () => 'foo', fn () => 42));
assertType("'foo'", when(1, 'foo', 42));
assertType("'foo'", when(42, 'foo'));
assertType('null', when(0, 'foo'));
assertType("'foo'", when(-42, 'foo'));
assertType('null', when(null, 'foo'));
assertType('42', when(['foo'], 42));
assertType('null', when([], 42));
assertType('42|null', when(random_int(0, 1), 42));
assertType('42|1337', when(random_int(0, 1), 42, 1337));
assertType("array{'bar'}|array{'foo'}", when(random_int(0, 1), ['foo'], ['bar']));
assertType('42|null', when(fn () => random_int(0, 1), 42));
assertType('42|1337', when(fn () => random_int(0, 1), 42, 1337));

assertType("'yes'", when(1.5, 'yes', 'no'));
assertType("'no'", when(0.0, 'yes', 'no'));
assertType("'yes'", when(new stdClass, 'yes', 'no'));
assertType('int', when(0, 'yes', fn (int $condition): int => $condition));
assertType("'yes'", when(new stdClass, fn (stdClass $condition) => 'yes', fn (bool $condition) => 'no'));
assertType("'no'", when(false, fn (stdClass $condition) => 'yes', fn (bool $condition) => 'no'));

function (?stdClass $user): void {
    assertType("'no'|'yes'", when($user, fn (stdClass $user) => 'yes', 'no'));
};

assertType("'fallback'", enum_value(null, static fn () => 'fallback'));
