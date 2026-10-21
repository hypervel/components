<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Fixtures\Enums;

enum IntegerStatus: int
{
    case Draft = 0;
    case Pending = 1;
    case Done = 2;
}
