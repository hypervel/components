<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Hyperf\Stubs;

enum StringStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
