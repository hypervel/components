<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Fixtures\Enums;

enum NonBackedStatus
{
    case draft;
    case pending;
    case done;
}
