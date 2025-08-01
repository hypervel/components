<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Hyperf\Stubs\Model;

enum Gender: int
{
    case UNKNOWN = 0;
    case MALE = 1;
    case FEMALE = 2;
}
