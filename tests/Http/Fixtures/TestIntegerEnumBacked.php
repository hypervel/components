<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Fixtures;

enum TestIntegerEnumBacked: int
{
    case Minus1 = -1;
    case Zero = 0;
    case Plus1 = 1;
}
