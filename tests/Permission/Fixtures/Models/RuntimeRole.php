<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Fixtures\Models;

use Hypervel\Permission\Models\Role;

class RuntimeRole extends Role
{
    protected array $visible = [
        'id',
        'name',
    ];
}
