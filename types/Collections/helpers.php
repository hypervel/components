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
assertType('42|null', when(random_int(0, 1), 42));
assertType('42|1337', when(random_int(0, 1), 42, 1337));

assertType("'fallback'", enum_value(null, static fn () => 'fallback'));
