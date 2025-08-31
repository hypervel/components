<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Enums;

enum Role: string
{
    case ADMIN = 'admin';
    case VIEWER = 'viewer';
}
