<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Enums;

enum Permission: string
{
    case VIEW = 'view';
    case EDIT = 'edit';
}
