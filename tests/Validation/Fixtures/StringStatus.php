<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation\Fixtures;

enum StringStatus: string
{
    case Pending = 'pending';
    case Done = 'done';
}
