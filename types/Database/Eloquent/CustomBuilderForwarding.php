<?php

declare(strict_types=1);

namespace Hypervel\Types\CustomBuilderForwarding;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\HasBuilder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Support\Collection;
use stdClass;

use function PHPStan\Testing\assertType;

/**
 * Preserve custom signatures, model rows, and the receiver through forwarding.
 */
function test(User $user, ScopedUser $scoped, FixedUser $fixed): void
{
    assertType('Hypervel\Types\CustomBuilderForwarding\UserBuilder<Hypervel\Types\CustomBuilderForwarding\User>', User::tenant('one'));
    assertType('Hypervel\Types\CustomBuilderForwarding\UserBuilder<Hypervel\Types\CustomBuilderForwarding\Admin>', Admin::tenant('one'));
    assertType('Hypervel\Types\CustomBuilderForwarding\UserBuilder<Hypervel\Types\CustomBuilderForwarding\User>', $user->tenant('one'));
    assertType('Hypervel\Types\CustomBuilderForwarding\UserBuilder<Hypervel\Types\CustomBuilderForwarding\User>', User::query()->selectRaw('id', record: true));
    assertType('Hypervel\Types\CustomBuilderForwarding\UserBuilder<Hypervel\Types\CustomBuilderForwarding\User>', User::query()->ignoredAnswer());
    assertType('int', User::query()->rawAnswer());
    assertType('Hypervel\Types\CustomBuilderForwarding\UserQuery<int, Hypervel\Types\CustomBuilderForwarding\User, \'fixture\'>', User::query()->dump());
    assertType('Hypervel\Types\CustomBuilderForwarding\UserQuery', User::query()->getQuery());
    assertType('Hypervel\Support\Collection<int, Hypervel\Types\CustomBuilderForwarding\User>', User::tenant('one')->models());
    assertType('Hypervel\Support\Collection<int, Hypervel\Types\CustomBuilderForwarding\Admin>', Admin::tenant('one')->models());

    User::query()->tenant('one')->each(function ($model, $key): void {
        assertType('Hypervel\Types\CustomBuilderForwarding\User', $model);
        assertType('int', $key);
    });

    User::query()->inspectRow(function ($model): void {
        assertType('Hypervel\Types\CustomBuilderForwarding\User', $model);
    });

    assertType('Hypervel\Database\Eloquent\Relations\HasMany<Hypervel\Types\CustomBuilderForwarding\User, Hypervel\Types\CustomBuilderForwarding\User>', $user->children()->tenant('one')->published());
    assertType('Hypervel\Database\Eloquent\Relations\HasMany<Hypervel\Types\CustomBuilderForwarding\User, Hypervel\Types\CustomBuilderForwarding\User>', $user->children()->ignoredAnswer());
    assertType('Hypervel\Support\Collection<int, Hypervel\Types\CustomBuilderForwarding\User>', $user->children()->tenant('one')->models());
    assertType('Hypervel\Types\CustomBuilderForwarding\UserBuilder<Hypervel\Types\CustomBuilderForwarding\User>', $user->children()->clone());
    assertType('Hypervel\Types\CustomBuilderForwarding\UserBuilder<Hypervel\Types\CustomBuilderForwarding\User>', $user->children()->applyScopes());
    assertType('int', $user->children()->rawAnswer());
    assertType('Hypervel\Types\CustomBuilderForwarding\UserQuery<int, Hypervel\Types\CustomBuilderForwarding\User, \'fixture\'>', $user->children()->dump());

    assertType('int', ScopedUser::query()->limit('scope'));
    assertType('int', ScopedUser::limit('scope'));
    assertType('Hypervel\Database\Eloquent\Relations\HasMany<Hypervel\Types\CustomBuilderForwarding\ScopedUser, Hypervel\Types\CustomBuilderForwarding\ScopedUser>', $scoped->children()->limit(1));
    assertType('int', $scoped->children()->offset('scope'));
    assertType('string', ScopedUser::query()->rawAnswer('scope'));
    assertType('string', $scoped->children()->rawAnswer('scope'));
    assertType('Hypervel\Types\CustomBuilderForwarding\UserBuilder<Hypervel\Types\CustomBuilderForwarding\ScopedUser>', ScopedUser::query()->published());
    assertType('Hypervel\Database\Eloquent\Relations\HasMany<Hypervel\Types\CustomBuilderForwarding\ScopedUser, Hypervel\Types\CustomBuilderForwarding\ScopedUser>', $scoped->children()->published());

    assertType('Hypervel\Types\CustomBuilderForwarding\FixedBuilder<Hypervel\Types\CustomBuilderForwarding\FixedUser>', FixedUser::tenant('one'));
    assertType('Hypervel\Database\Eloquent\Relations\HasMany<Hypervel\Types\CustomBuilderForwarding\FixedUser, Hypervel\Types\CustomBuilderForwarding\FixedUser>', $fixed->children()->tenant('one'));

    FixedUser::query()->inspectRow(function ($row): void {
        assertType('stdClass', $row);
    });
}

class User extends Model
{
    /** @use HasBuilder<UserBuilder<static>> */
    use HasBuilder;

    protected static string $builder = UserBuilder::class;

    /**
     * Build a relationship retaining the concrete model type.
     *
     * @return HasMany<static, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(static::class, 'parent_id');
    }

    /**
     * Create the custom query used by this model.
     */
    protected function newBaseQueryBuilder(): UserQuery
    {
        return new UserQuery($this->getConnection());
    }
}

class Admin extends User
{
}

class ScopedUser extends User
{
    /**
     * Override query-only dispatch with a scope of a different signature.
     *
     * @param UserBuilder<static> $query
     */
    public function scopeLimit(UserBuilder $query, string $label): int
    {
        return strlen($label);
    }

    /**
     * Override a query-only method that is not owned by the relation either.
     *
     * @param UserBuilder<static> $query
     */
    public function scopeOffset(UserBuilder $query, string $label): int
    {
        return strlen($label);
    }

    /**
     * Override a passthru method with a scope-specific signature and result.
     *
     * @param UserBuilder<static> $query
     */
    public function scopeRawAnswer(UserBuilder $query, string $label): string
    {
        return $label;
    }

    /**
     * Remain subordinate to a real Eloquent builder method of the same name.
     *
     * @param UserBuilder<static> $query
     */
    public function scopePublished(UserBuilder $query): int
    {
        return 1;
    }
}

/**
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class UserBuilder extends Builder
{
    /** @var UserQuery */
    protected QueryBuilder $query;

    protected array $passthru = ['rawanswer', 'dump'];

    /**
     * Return the declared raw query.
     */
    public function getQuery(): UserQuery
    {
        return $this->query;
    }

    /**
     * Apply a custom fluent model constraint.
     */
    public function published(): static
    {
        return $this->whereNotNull('published_at');
    }

    /**
     * Return a custom model-valued terminal result.
     *
     * @return Collection<int, TModel>
     */
    public function models(): Collection
    {
        return $this->get();
    }
}

/**
 * @template TKey of array-key = int
 * @template TValue = stdClass
 * @template TOption of string = 'fixture'
 *
 * @extends QueryBuilder<TKey, TValue, 'select'|'tenant'|'where'>
 */
class UserQuery extends QueryBuilder
{
    /**
     * Add a query-only fluent method with an additional template default.
     *
     * @param TOption $option
     */
    public function tenant(string $tenant, string $option = 'fixture'): static
    {
        return $this->where('tenant_id', $tenant);
    }

    /**
     * Extend an inherited signature with a named option.
     *
     * @param array<mixed> $bindings
     */
    public function selectRaw(string $expression, array $bindings = [], bool $record = false): static
    {
        return parent::selectRaw($expression, $bindings);
    }

    /**
     * Describe a callback receiving the active forwarded row type.
     *
     * @param callable(TValue): void $callback
     */
    public function inspectRow(callable $callback): static
    {
        return $this;
    }

    /**
     * Return a value explicitly passed through the custom Eloquent builder.
     */
    public function rawAnswer(): int
    {
        return 42;
    }

    /**
     * Return a value that ordinary Eloquent forwarding deliberately discards.
     */
    public function ignoredAnswer(): int
    {
        return 42;
    }
}

/** @extends UserQuery<int, stdClass> */
class FixedQuery extends UserQuery
{
}

/**
 * @template TModel of Model
 *
 * @extends UserBuilder<TModel>
 */
class FixedBuilder extends UserBuilder
{
    /** @var FixedQuery */
    protected QueryBuilder $query;

    /**
     * Return a non-generic concrete query builder.
     */
    public function getQuery(): FixedQuery
    {
        return $this->query;
    }
}

class FixedUser extends Model
{
    /** @use HasBuilder<FixedBuilder<static>> */
    use HasBuilder;

    protected static string $builder = FixedBuilder::class;

    /**
     * Create the fixed raw query used by this model.
     */
    protected function newBaseQueryBuilder(): FixedQuery
    {
        return new FixedQuery($this->getConnection());
    }

    /**
     * Build a relationship using the fixed query builder.
     *
     * @return HasMany<static, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(static::class, 'parent_id');
    }
}
