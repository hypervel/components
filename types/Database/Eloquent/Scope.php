<?php

declare(strict_types=1);

namespace Hypervel\Types\Scope;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Scope;

use function PHPStan\Testing\assertType;

/**
 * @implements Scope<User>
 */
class UserScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        assertType('Hypervel\Database\Eloquent\Builder<covariant Hypervel\Types\Scope\User>', $builder);
        assertType('Hypervel\Types\Scope\User', $model);
    }
}

/**
 * @implements Scope<Model>
 */
class GenericScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        assertType('Hypervel\Database\Eloquent\Builder<covariant Hypervel\Database\Eloquent\Model>', $builder);
        assertType('Hypervel\Database\Eloquent\Model', $model);
    }
}

class User extends Model
{
}

$user = new User;
$query = User::query();
(new UserScope)->apply($query, $user);
(new GenericScope)->apply($query, $user);
