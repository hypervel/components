<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support\Fixtures;

enum IntBackedEnum: int
{
    case RoleAdmin = 1;
    case Two = 2;
}
