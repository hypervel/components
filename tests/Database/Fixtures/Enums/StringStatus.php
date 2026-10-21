<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Fixtures\Enums;

enum StringStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Done = 'done';
}
