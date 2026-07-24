<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Fixtures\Models;

use Hypervel\Database\Eloquent\Concerns\HasUuids;
use Hypervel\Database\Eloquent\Model;

class PartitionWorkspaceTeam extends Model
{
    use HasUuids;

    protected array $fillable = ['workspace_id', 'name'];

    protected ?string $table = 'partition_workspace_teams';

    public bool $timestamps = false;
}
