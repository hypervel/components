<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Fixtures\Models;

use Hypervel\Database\Eloquent\Concerns\HasUuids;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Permission\Traits\HasRoles;

class SoftDeletingGlobalPartitionUser extends UserWithoutHasRoles
{
    use HasRoles;
    use HasUuids;
    use SoftDeletes;

    protected string $guard_name = 'web';

    protected ?string $table = 'global_partition_users';
}
