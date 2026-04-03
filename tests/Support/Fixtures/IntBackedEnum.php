<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support\Fixtures;

enum IntBackedEnum: int
{
    case ROLE_ADMIN = 1;
    case TWO = 2;
}
