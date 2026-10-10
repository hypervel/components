<?php

declare(strict_types=1);

namespace Hypervel\Types\Scout;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Eloquent\Relations\HasManyThrough;
use Hypervel\Scout\Searchable;

use function PHPStan\Testing\assertType;

function test(User $user): void
{
    /* @phpstan-ignore method.void */
    assertType('null', Order::query()->where('price', '>', 100)->searchable());
    /* @phpstan-ignore method.void */
    assertType('null', Order::query()->unsearchable(500));
    /* @phpstan-ignore method.void */
    assertType('null', $user->orders()->searchable());
    /* @phpstan-ignore method.void */
    assertType('null', $user->storeOrders()->unsearchable());
}

class Order extends Model
{
    use Searchable;
}

class Store extends Model
{
}

class User extends Model
{
    /**
     * Get the user's orders.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get the orders placed through the user's stores.
     *
     * @return HasManyThrough<Order, Store, $this>
     */
    public function storeOrders(): HasManyThrough
    {
        return $this->hasManyThrough(Order::class, Store::class);
    }
}
