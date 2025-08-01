<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Hyperf\Stubs;

enum IntegerStatus: int
{
    case Active = 1;
    case Inactive = 2;
}
