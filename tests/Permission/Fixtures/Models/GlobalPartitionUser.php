<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Fixtures\Models;

use Hypervel\Database\Eloquent\Concerns\HasUuids;
use Hypervel\Permission\Traits\HasRoles;

class GlobalPartitionUser extends UserWithoutHasRoles
{
    use HasRoles;
    use HasUuids;

    protected ?string $table = 'global_partition_users';
}
