<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Fixtures;

enum TestIntegerEnumBacked: int
{
    case minus_1 = -1;
    case zero = 0;
    case plus_1 = 1;
}
