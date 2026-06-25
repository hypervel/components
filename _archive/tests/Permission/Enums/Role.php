<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Viewer = 'viewer';
}
