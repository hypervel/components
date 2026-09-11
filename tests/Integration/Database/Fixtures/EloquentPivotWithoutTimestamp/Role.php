<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Fixtures\EloquentPivotWithoutTimestamp;

use Hypervel\Database\Eloquent\Attributes\UseFactory;
use Hypervel\Database\Eloquent\Factories\HasFactory;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;

#[UseFactory(RoleFactory::class)]
class Role extends Model
{
    use HasFactory;

    /**
     * Get the users assigned to the role.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('notes')
            ->using(UserRole::class);
    }
}
