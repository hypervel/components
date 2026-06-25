<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Fixtures\Models;

use Hypervel\Permission\Traits\HasRoles;

class User extends UserWithoutHasRoles
{
    use HasRoles;
}
