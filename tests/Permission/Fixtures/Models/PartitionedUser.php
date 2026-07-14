<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Fixtures\Models;

use Hypervel\Database\Eloquent\Concerns\HasUuids;
use Hypervel\Permission\Traits\HasRoles;

class PartitionedUser extends UserWithoutHasRoles
{
    use HasRoles;
    use HasUuids;

    protected array $fillable = ['workspace_id', 'email'];

    protected ?string $table = 'partitioned_users';
}
