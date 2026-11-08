<?php

namespace CustomEloquentBuilder;

use App\User;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Database\Eloquent\Relations\HasMany;

use function PHPStan\Testing\assertType;

function test(FooModel $foo, NonGenericBuilder $nonGenericBuilder, ModelWithNonGenericBuilder $nonGenericModel): void
{
    assertType('CustomEloquentBuilder\ModelWithCustomBuilder|null', ModelWithCustomBuilder::where('email', 'bar')->first());
    assertType('CustomEloquentBuilder\CustomEloquentBuilder<CustomEloquentBuilder\ModelWithCustomBuilder>', ModelWithCustomBuilder::where('email', 'bar'));
    assertType('CustomEloquentBuilder\CustomEloquentBuilder<CustomEloquentBuilder\ModelWithCustomBuilder>', ModelWithCustomBuilder::foo('foo')->foo('bar'));
    assertType('CustomEloquentBuilder\CustomEloquentBuilder<CustomEloquentBuilder\ModelWithCustomBuilder>', ModelWithCustomBuilder::type('foo'));
    assertType('Hypervel\Database\Eloquent\Relations\HasMany<CustomEloquentBuilder\ModelWithCustomBuilder, CustomEloquentBuilder\FooModel>', $foo->customModels()->category('foo'));
    // A relation returns itself only when the builder method returns the same builder, which cloneWithout() does not.
    assertType('CustomEloquentBuilder\CustomEloquentBuilder<CustomEloquentBuilder\ModelWithCustomBuilder>', $foo->customModels()->cloneWithout(['orders']));
    assertType('CustomEloquentBuilder\CustomEloquentBuilder<CustomEloquentBuilder\ModelWithCustomBuilder>', ModelWithCustomBuilder::whereEmail(['bar'])->type('foo')->whereEmail(['bar']));
    assertType('CustomEloquentBuilder\CustomEloquentBuilder<CustomEloquentBuilder\ModelWithCustomBuilder>', ModelWithCustomBuilder::whereEmail(['bar'])->categories(['foo'])->whereType(['bar']));
    assertType('CustomEloquentBuilder\ModelWithCustomBuilder|null', ModelWithCustomBuilder::whereEmail(['bar'])->type('foo')->first());
    assertType('CustomEloquentBuilder\ModelWithCustomBuilder|null', ModelWithCustomBuilder::whereEmail(['bar'])->type('foo')->first());
    assertType('int<0, max>', $foo->customModels()->count());
    assertType('bool', $foo->customModels()->exists());
    assertType('Hypervel\Database\Eloquent\Collection<int, CustomEloquentBuilder\ModelWithCustomBuilder>', ModelWithCustomBuilder::get());
    assertType('CustomEloquentBuilder\ModelWithCustomBuilder', ModelWithCustomBuilder::firstOrFail());
    assertType('CustomEloquentBuilder\ModelWithCustomBuilder', ModelWithCustomBuilder::findOrFail(1));
    assertType('Hypervel\Database\Eloquent\Collection<int, CustomEloquentBuilder\ModelWithCustomBuilder>', ModelWithCustomBuilder::findOrFail([1, 2, 3]));
    assertType('CustomEloquentBuilder\CustomEloquentBuilder<CustomEloquentBuilder\ModelWithCustomBuilder>', ModelWithCustomBuilder::query()->has('users'));
    assertType('CustomEloquentBuilder\CustomEloquentBuilder<CustomEloquentBuilder\ModelWithCustomBuilder>', ModelWithCustomBuilder::query()->orHas('users'));
    assertType('CustomEloquentBuilder\CustomEloquentBuilder<CustomEloquentBuilder\ModelWithCustomBuilder>', ModelWithCustomBuilder::query()->doesntHave('users'));
    assertType('CustomEloquentBuilder\CustomEloquentBuilder<CustomEloquentBuilder\ModelWithCustomBuilder>', ModelWithCustomBuilder::query()->orDoesntHave('users'));
    assertType('CustomEloquentBuilder\CustomEloquentBuilder<CustomEloquentBuilder\ModelWithCustomBuilder>', ModelWithCustomBuilder::query()->whereHas('users'));
    assertType('CustomEloquentBuilder\CustomEloquentBuilder<CustomEloquentBuilder\ModelWithCustomBuilder>', ModelWithCustomBuilder::query()->withWhereHas('users'));
    assertType('CustomEloquentBuilder\CustomEloquentBuilder<CustomEloquentBuilder\ModelWithCustomBuilder>', ModelWithCustomBuilder::query()->orWhereHas('users'));
    assertType('CustomEloquentBuilder\CustomEloquentBuilder<CustomEloquentBuilder\ModelWithCustomBuilder>', ModelWithCustomBuilder::query()->whereDoesntHave('users'));
    assertType('CustomEloquentBuilder\CustomEloquentBuilder<CustomEloquentBuilder\ModelWithCustomBuilder>', ModelWithCustomBuilder::query()->orWhereDoesntHave('users'));
    assertType('CustomEloquentBuilder\CustomEloquentBuilder<CustomEloquentBuilder\ModelWithCustomBuilder>', ModelWithCustomBuilder::query()->hasMorph('users', 'types'));
    assertType('CustomEloquentBuilder\CustomEloquentBuilder<CustomEloquentBuilder\ModelWithCustomBuilder>', ModelWithCustomBuilder::query()->orHasMorph('users', 'types'));
    assertType('CustomEloquentBuilder\CustomEloquentBuilder<CustomEloquentBuilder\ModelWithCustomBuilder>', ModelWithCustomBuilder::query()->doesntHaveMorph('users', 'types'));
    assertType('CustomEloquentBuilder\CustomEloquentBuilder<CustomEloquentBuilder\ModelWithCustomBuilder>', ModelWithCustomBuilder::query()->orDoesntHaveMorph('users', 'types'));
    assertType('CustomEloquentBuilder\CustomEloquentBuilder<CustomEloquentBuilder\ModelWithCustomBuilder>', ModelWithCustomBuilder::query()->whereHasMorph('users', 'types'));
    assertType('CustomEloquentBuilder\CustomEloquentBuilder<CustomEloquentBuilder\ModelWithCustomBuilder>', ModelWithCustomBuilder::query()->orWhereHasMorph('users', 'types'));
    assertType('CustomEloquentBuilder\CustomEloquentBuilder<CustomEloquentBuilder\ModelWithCustomBuilder>', ModelWithCustomBuilder::query()->whereDoesntHaveMorph('users', 'types'));
    assertType('CustomEloquentBuilder\CustomEloquentBuilder<CustomEloquentBuilder\ModelWithCustomBuilder>', ModelWithCustomBuilder::query()->orWhereDoesntHaveMorph('users', 'types'));
    assertType('CustomEloquentBuilder\CustomEloquentBuilder<CustomEloquentBuilder\ModelWithCustomBuilder>', ModelWithCustomBuilder::query()->mergeConstraintsFrom(ModelWithCustomBuilder::query()));
    assertType('CustomEloquentBuilder\CustomEloquentBuilder<CustomEloquentBuilder\ModelWithCustomBuilder>', ModelWithCustomBuilder::query()->whereHas('users')->type('foo'));
    assertType('Hypervel\Database\Eloquent\Collection<int, CustomEloquentBuilder\ModelWithCustomBuilderAndDocBlocks>', ModelWithCustomBuilderAndDocBlocks::query()->get());
    assertType('Hypervel\Database\Eloquent\Collection<int, CustomEloquentBuilder\ModelWithCustomBuilderAndDocBlocks>', ModelWithCustomBuilderAndDocBlocks::all());
    assertType('CustomEloquentBuilder\CustomBuilder2<CustomEloquentBuilder\ModelWithCustomBuilderAndDocBlocks>', ModelWithCustomBuilderAndDocBlocks::query());
    assertType('CustomEloquentBuilder\NonGenericBuilder', $nonGenericBuilder->skip(5));

    assertType('CustomEloquentBuilder\ModelWithNonGenericBuilder|null', ModelWithNonGenericBuilder::where('email', 'bar')->first());
    assertType('CustomEloquentBuilder\ChildNonGenericBuilder', ModelWithNonGenericBuilder::where('email', 'bar')->orderBy('email'));
    assertType('CustomEloquentBuilder\ChildNonGenericBuilder', ModelWithNonGenericBuilder::whereEmail('bar'));
    assertType('CustomEloquentBuilder\ChildNonGenericBuilder', ModelWithNonGenericBuilder::query());
    assertType('list<string>', ModelWithNonGenericBuilder::query()->getColumns());
    assertType('CustomEloquentBuilder\ChildNonGenericBuilder', $nonGenericModel->newQuery());
    assertType('CustomEloquentBuilder\ChildNonGenericBuilder', ModelWithNonGenericBuilder::withTrashed());
    assertType('Hypervel\Database\Eloquent\Relations\HasMany<CustomEloquentBuilder\ModelWithNonGenericBuilder, CustomEloquentBuilder\FooModel>', $foo->nonGenericModels()->wherePublishable());
    assertType('Hypervel\Database\Eloquent\Collection<int, CustomEloquentBuilder\ModelWithNonGenericBuilder>', ModelWithNonGenericBuilder::get());

    assertType('CustomEloquentBuilder\CustomEloquentBuilder<CustomEloquentBuilder\ModelWithCustomBuilder>', ModelWithCustomBuilder::query()->whereNotIn('id', [1]));
    assertType('CustomEloquentBuilder\CustomEloquentBuilder<CustomEloquentBuilder\ModelWithCustomBuilder>', ModelWithCustomBuilder::whereNotIn('id', [1]));
    assertType('string', ModelWithCustomBuilder::query()->getColumns());
    assertType('CustomEloquentBuilder\ChildNonGenericBuilder', ModelWithNonGenericBuilder::query()->whereNotIn('id', [1]));
    assertType('int<0, max>', ModelWithCustomBuilder::query()->count());

    // A method that only exists on the custom query builder returned by toBase(), forwarded
    // via __call. Resolving this requires reading the actual query builder class off the
    // Eloquent builder's own `toBase()` return type, instead of assuming
    // Hypervel\Database\Query\Builder.
    assertType('CustomEloquentBuilder\ModelWithCustomQueryBuilder|null', ModelWithCustomQueryBuilder::onlyOnCustomQueryBuilder()->first());

    // exists() is in BuilderHelper::$passthru and is overridden on the custom query builder
    // (not on Hypervel\Database\Query\Builder itself). Forwarding it must still return its
    // own declared scalar return type, not fall back to assuming the Eloquent builder itself
    // (i.e. treating it as chainable) just because it isn't declared on Hypervel's native
    // query builder.
    assertType('bool', ModelWithCustomQueryBuilder::where('email', 'bar')->exists());

    // count() is also passthru, but unlike exists() it is NOT overridden on the custom query
    // builder - it is only inherited from Hypervel\Database\Query\Builder. The declaring
    // class comparison must account for this (the custom query builder "is" its parent),
    // not require an exact class name match, or this would wrongly fall back to the
    // chainable Eloquent builder type instead of the real passthru return type.
    assertType('int<0, max>', ModelWithCustomQueryBuilder::query()->count());
}

/** @param ChildNonGenericBuilder|ModelWithNonGenericBuilder $builder */
function testNonGenericBuilderDocBlock($builder): void
{
    assertType('CustomEloquentBuilder\ChildNonGenericBuilder', $builder);
}

/**
 * @property string $email
 * @property string $category
 * @property string $type
 */
class ModelWithCustomBuilder extends Model
{
    // Dummy relation
    /** @return HasMany<User> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @param  CustomEloquentBuilder<ModelWithCustomBuilder>  $query
     *
     * @phpstan-return CustomEloquentBuilder<ModelWithCustomBuilder>
     */
    public function scopeFoo(CustomEloquentBuilder $query, string $foo): CustomEloquentBuilder
    {
        return $query->where(['email' => $foo]);
    }

    /** @param CustomEloquentBuilder<ModelWithCustomBuilder> $query */
    public function scopeLockForUpdate(CustomEloquentBuilder $query): CustomEloquentBuilder
    {
        return $query;
    }

    /** @phpstan-return CustomEloquentBuilder<ModelWithCustomBuilder> */
    public function testCustomBuilderReturnType(): CustomEloquentBuilder
    {
        return $this->where('email', 'bar');
    }

    /**
     * @param  \Hypervel\Database\Query\Builder  $query
     * @return CustomEloquentBuilder<ModelWithCustomBuilder>
     */
    public function newEloquentBuilder($query): CustomEloquentBuilder
    {
        return new CustomEloquentBuilder($query);
    }
}

/**
 * @template TModel of ModelWithCustomBuilder
 *
 * @extends Builder<ModelWithCustomBuilder>
 */
class CustomEloquentBuilder extends Builder
{
    public function testScopeCollision(): void
    {
        // Unlike upstream, the scope's builder result is this builder, which callScope() passes it.
        assertType('$this(CustomEloquentBuilder\CustomEloquentBuilder<TModel of CustomEloquentBuilder\ModelWithCustomBuilder (class CustomEloquentBuilder\CustomEloquentBuilder, argument)>)', $this->lockForUpdate());
    }

    public function wherePublishable(): static
    {
        assertType('$this(CustomEloquentBuilder\CustomEloquentBuilder<TModel of CustomEloquentBuilder\ModelWithCustomBuilder (class CustomEloquentBuilder\CustomEloquentBuilder, argument)>)', $this->whereNotIn('status', ['draft', 'archived']));

        return $this->whereNotIn('status', ['draft', 'archived']);
    }

    public function wherePublished(): static
    {
        assertType('$this(CustomEloquentBuilder\CustomEloquentBuilder<TModel of CustomEloquentBuilder\ModelWithCustomBuilder (class CustomEloquentBuilder\CustomEloquentBuilder, argument)>)', $this->where('status', 'published'));

        return $this->where('status', 'published');
    }

    /** @phpstan-return $this Unlike upstream, which treats any builder result as this builder. */
    public function category(string $category): CustomEloquentBuilder
    {
        assertType('$this(CustomEloquentBuilder\CustomEloquentBuilder<TModel of CustomEloquentBuilder\ModelWithCustomBuilder (class CustomEloquentBuilder\CustomEloquentBuilder, argument)>)', $this->where('category', $category));

        return $this->where('category', $category);
    }

    /** @phpstan-return CustomEloquentBuilder<ModelWithCustomBuilder> */
    public function type(string $type): CustomEloquentBuilder
    {
        assertType('$this(CustomEloquentBuilder\CustomEloquentBuilder<TModel of CustomEloquentBuilder\ModelWithCustomBuilder (class CustomEloquentBuilder\CustomEloquentBuilder, argument)>)', $this->where(['type' => $type]));

        return $this->where(['type' => $type]);
    }

    /**
     * @param  string[]  $categories
     *
     * @phpstan-return CustomEloquentBuilder<ModelWithCustomBuilder>
     */
    public function categories(array $categories): CustomEloquentBuilder
    {
        assertType('$this(CustomEloquentBuilder\CustomEloquentBuilder<TModel of CustomEloquentBuilder\ModelWithCustomBuilder (class CustomEloquentBuilder\CustomEloquentBuilder, argument)>)', $this->whereIn('category', $categories));

        return $this->whereIn('category', $categories);
    }
}

class FooModel extends Model
{
    /** @return HasMany<ModelWithCustomBuilder, $this> */
    public function customModels(): HasMany
    {
        return $this->hasMany(ModelWithCustomBuilder::class);
    }

    /** @return HasMany<ModelWithNonGenericBuilder, $this> */
    public function nonGenericModels(): HasMany
    {
        return $this->hasMany(ModelWithNonGenericBuilder::class);
    }
}

/**
 * @method static CustomBuilder2|ModelWithCustomBuilderAndDocBlocks newModelQuery()
 * @method static CustomBuilder2|ModelWithCustomBuilderAndDocBlocks newQuery()
 * @method static CustomBuilder2|ModelWithCustomBuilderAndDocBlocks query()
 */
class ModelWithCustomBuilderAndDocBlocks extends Model
{
    /**
     * @param  \Hypervel\Database\Query\Builder  $query
     * @return CustomBuilder2<ModelWithCustomBuilderAndDocBlocks>
     */
    public function newEloquentBuilder($query): CustomBuilder2
    {
        return new CustomBuilder2($query);
    }
}

/**
 * @template TModel of ModelWithCustomBuilderAndDocBlocks
 *
 * @extends Builder<TModel>
 */
class CustomBuilder2 extends Builder
{
}

/** @property string $email Unlike upstream, which runs without model property checks, whereEmail() needs this column. */
class ModelWithNonGenericBuilder extends Model
{
    use SoftDeletes;

    /**
     * @param  \Hypervel\Database\Query\Builder  $query
     * @return ChildNonGenericBuilder
     */
    public function newEloquentBuilder($query): ChildNonGenericBuilder
    {
        return new ChildNonGenericBuilder($query);
    }
}

/**
 * @extends Builder<ModelWithNonGenericBuilder>
 */
class NonGenericBuilder extends Builder
{
    public function wherePublishable(): static
    {
        assertType('$this(CustomEloquentBuilder\NonGenericBuilder)', $this->whereNotIn('status', ['draft', 'archived']));

        return $this->whereNotIn('status', ['draft', 'archived']);
    }
}

class ChildNonGenericBuilder extends NonGenericBuilder
{
    /** @return list<string> */
    public function getColumns(): array
    {
        return [];
    }
}

/**
 * A query builder with a method that does not exist on Hypervel\Database\Query\Builder.
 * Mirrors packages (e.g. mongodb/laravel-mongodb) that replace the query layer entirely.
 */
class CustomQueryBuilder extends \Hypervel\Database\Query\Builder
{
    public function onlyOnCustomQueryBuilder(): static
    {
        return $this;
    }

    /** Overridden here, like mongodb/laravel-mongodb's own Query\Builder::exists() does. */
    public function exists(): bool
    {
        return true;
    }
}

/**
 * @template TModel of ModelWithCustomQueryBuilder
 *
 * @extends Builder<TModel>
 *
 * @method CustomQueryBuilder toBase()
 */
class EloquentBuilderWithCustomQueryBuilder extends Builder
{
}

class ModelWithCustomQueryBuilder extends Model
{
    /**
     * @param  \Hypervel\Database\Query\Builder  $query
     * @return EloquentBuilderWithCustomQueryBuilder<ModelWithCustomQueryBuilder>
     */
    public function newEloquentBuilder($query): EloquentBuilderWithCustomQueryBuilder
    {
        return new EloquentBuilderWithCustomQueryBuilder($query);
    }
}
