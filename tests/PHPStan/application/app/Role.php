<?php

declare(strict_types=1);

namespace App;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected string $keyType = 'uuid';

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * @param  array<int, Role>  $models
     * @return RoleCollection<int, Role>
     */
    public function newCollection(array $models = []): RoleCollection
    {
        return new RoleCollection($models);
    }
}
