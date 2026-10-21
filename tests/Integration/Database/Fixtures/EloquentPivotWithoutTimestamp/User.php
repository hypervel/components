<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Fixtures\EloquentPivotWithoutTimestamp;

use Hypervel\Database\Eloquent\Attributes\UseFactory;
use Hypervel\Database\Eloquent\Factories\HasFactory;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Foundation\Auth\User as Authenticatable;
use Hypervel\Testbench\Factories\UserFactory;

#[UseFactory(UserFactory::class)]
class User extends Authenticatable
{
    use HasFactory;

    /**
     * Get the roles assigned to the user.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)
            ->withPivot('notes')
            ->using(UserRole::class)
            ->withTimestamps(updatedAt: false);
    }
}
