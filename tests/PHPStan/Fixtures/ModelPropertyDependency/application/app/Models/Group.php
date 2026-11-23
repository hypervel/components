<?php

declare(strict_types=1);

namespace ModelPropertyDependency\Models;

use Hypervel\Database\Eloquent\HasBuilder;
use Hypervel\Database\Eloquent\Relations\HasMany;

class Group extends BaseModel
{
    /** @use HasBuilder<UserBuilder<static>> */
    use HasBuilder;

    protected static string $builder = UserBuilder::class;

    /**
     * Get the group's subgroups.
     *
     * @return HasMany<Group, $this>
     */
    public function subgroups(): HasMany
    {
        return $this->hasMany(Group::class, 'parent_id');
    }
}
