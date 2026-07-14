<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Fixtures\Models;

use Hypervel\Database\Eloquent\Concerns\HasUuids;
use Hypervel\Permission\Models\Role;

class PartitionedRole extends Role
{
    use HasUuids;
}
