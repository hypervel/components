<?php

declare(strict_types=1);

namespace ModelRelationDefaults;

use App\User;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasMany;

class ValidDefaults extends User
{
    protected array $with = ['accounts.transactions:id', 'group:id'];
    protected array $withCount = ['accounts AS total'];
}

class InvalidDefaults extends User
{
    protected array $with = ['accounts.transactions', 'missing', 'accounts.missing'];
    protected array $withCount = ['missing as total', 'accounts  as  total', 'accounts.transactions'];
}

abstract class ParentDefaults extends Model
{
    protected array $with = ['children'];
    protected array $withCount = ['children as total'];
}

class ValidChild extends ParentDefaults
{
    /** @return HasMany<User, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(User::class);
    }
}

class InvalidChild extends ParentDefaults
{
}

class OverriddenDefaults extends ParentDefaults
{
    protected array $with = [];
    protected array $withCount = [];
}

// Hypervel: upstream declares this default in a trait, which PHP rejects, since it differs from Model's $with.
class NestedDefaults extends User
{
    protected array $with = ['accounts:id' => ['transactions', 'missing']];
}

class ConstantDefaults extends User
{
    private const RELATIONS = [2 => 'missing'];
    protected array $with = self::RELATIONS;
}

class NonModel
{
    protected array $with = ['missing'];
    protected array $withCount = ['missing'];
}

class UnknownRelatedDefault extends \App\Comment
{
    protected array $with = ['commentable.anything'];
}

class SharedDeclaration extends User
{
    protected array $with = ['accounts'], $withCount = ['missing'];
}
