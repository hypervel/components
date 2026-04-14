<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Enums;

enum Permission: string
{
    case View = 'view';
    case Edit = 'edit';
}
