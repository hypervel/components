<?php

namespace AbortTests;

use function PHPStan\Testing\assertType;
use function PHPStan\Testing\assertVariableCertainty;

function test(?int $foo): void
{
    if ($foo > 0) {
        $operator = '+';
    } elseif ($foo < 0) {
        $operator = '-';
    } else {
        abort(422);
    }

    // Should not complain about $operator possibly not being defined
    assertVariableCertainty(
        \PHPStan\TrinaryLogic::createYes(),
        $operator
    );

    abort_if(! $foo, 500);
    assertType('int<min, -1>|int<1, max>', $foo);

    /** @var int|string $foo */
    abort_if(is_string($foo), 500);
    assertType('int', $foo);

    /** @var ?int $foo */
    abort_unless(! is_null($foo), 500);
    assertType('int', $foo);

    /** @var int|string $foo */
    abort_unless(! is_string($foo), 500);
    assertType('int', $foo);

    // Hypervel: the condition binds by name.
    /** @var ?int $foo */
    abort_if(code: 500, boolean: $foo === null);
    assertType('int', $foo);
}

/**
 * Hypervel: an unpacked argument array is not the condition.
 *
 * @param array{bool, int} $arguments
 */
function unpackedArguments(array $arguments): void
{
    abort_if(...$arguments);
    assertType('array{bool, int}', $arguments);
}
