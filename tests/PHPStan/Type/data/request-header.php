<?php

declare(strict_types=1);

namespace RequestHeader;

use Hypervel\Http\Request;

use function PHPStan\Testing\assertType;

function test(Request $request): void
{
    assertType('array<string, list<string|null>>', $request->header());
    assertType('string|null', $request->header('key'));
    // Hypervel: a header stored with a null value returns null even when a default is given.
    assertType('string|null', $request->header('key', 'default'));
}
