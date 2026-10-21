<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation\Fixtures;

enum IntegerStatus: int
{
    case Pending = 1;
    case Done = 2;
}
