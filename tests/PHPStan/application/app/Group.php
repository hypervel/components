<?php

namespace App;

use App\Traits\NestedSoftDeletes;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasMany;

/**
 * @property string $name
 */
class Group extends Model
{
    use NestedSoftDeletes;

    /** @phpstan-return HasMany<Account, $this> */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /** @phpstan-return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
