<?php

namespace Bug2283;

use App\Account;
use App\User;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsTo;

use function PHPStan\Testing\assertType;

class Comment extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        assertType('Hypervel\Database\Eloquent\Relations\BelongsTo<App\User, $this(Bug2283\Comment)>', $this->belongsTo(User::class)
            ->when(true, fn($query) => $query));

        return $this->belongsTo(User::class)
            ->when(true, fn($query) => $query);
    }
}

function test(User $user, bool $condition): void
{
    assertType('Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>', $user->accounts()->when($condition, fn ($query) => $query->where('active', '1')));
    assertType('Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>', $user->accounts()->unless($condition, fn ($query) => $query->where('active', '1')));
    assertType('Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>', $user->accounts()->tap(fn ($query) => $query->where('active', '1')));
    assertType('Hypervel\Database\Eloquent\Relations\BelongsToMany<App\Role, App\User, Hypervel\Database\Eloquent\Relations\Pivot, \'pivot\'>', $user->roles()->when($condition, fn ($query) => $query->where('name', 'admin')));
    // Hypervel: a false condition returns the builder, which Relation::__call() returns as the relation.
    assertType('Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>|int<0, max>', $user->accounts()->when($condition, fn ($query) => $query->count()));
    assertType('Hypervel\Database\Eloquent\Builder<App\User>|Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>', $user->accounts()->when($condition, fn ($query) => User::query()));
}
