<?php

namespace App;

use App\Traits\HasOwner;
use App\Traits\HasParent;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    use HasOwner;
    use HasParent;

    /**
     * @param Builder<Account> $query
     *
     * @return Builder<Account>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', 1);
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** @phpstan-return BelongsTo<Group, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * @return BelongsToMany<Post, $this>
     */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class);
    }

    /**
     * @param  array<int, Account>  $models
     * @return AccountCollection<int, Account>
     */
    public function newCollection(array $models = []): AccountCollection
    {
        return new AccountCollection($models);
    }
}
