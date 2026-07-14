<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Fixtures\Models;

use Hypervel\Database\Eloquent\Concerns\HasUuids;
use Hypervel\Permission\Traits\HasPermissions;

class GlobalPartitionPermissionsOnlyUser extends UserWithoutHasRoles
{
    use HasPermissions;
    use HasUuids;

    protected string $guard_name = 'web';

    protected ?string $table = 'global_partition_users';
}
