<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Eloquent;

use Hypervel\Database\Connection;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Database\Query\Grammars\Grammar;
use Hypervel\Database\Query\Processors\Processor;
use Hypervel\Support\Collection;
use Hypervel\Tests\TestCase;
use Mockery as m;

class CustomBuilderForwardingTest extends TestCase
{
    public function testOrdinaryForwardingDiscardsResultsButPassthruAndNativeTerminalsReturnThem(): void
    {
        $builder = $this->builder(new ForwardingTestModel);

        $this->assertSame($builder, $builder->tenant('one'));
        $this->assertSame('one', $builder->getQuery()->wheres[0]['value']);
        $this->assertSame($builder, $builder->ignoredAnswer());
        $this->assertSame(42, $builder->rawAnswer());
        $this->assertSame($builder->getModel(), $builder->models()->sole());
    }

    public function testRelationsDecorateFluentResultsButRetainTerminalsAndClonedBuilders(): void
    {
        $builder = $this->builder(new ForwardingTestModel);
        $parent = new ForwardingTestModel;
        $parent->setRawAttributes(['id' => 1]);
        $relation = new HasMany($builder, $parent, 'items.parent_id', 'id');

        $this->assertSame($relation, $relation->tenant('one')->published());
        $this->assertSame($relation, $relation->ignoredAnswer());
        $this->assertSame(42, $relation->rawAnswer());
        $this->assertSame($builder->getModel(), $relation->models()->sole());
        $clone = $relation->clone();
        $this->assertInstanceOf(ForwardingTestBuilder::class, $clone);
        $this->assertNotSame($builder, $clone);
        $this->assertNotSame($builder->getQuery(), $clone->getQuery());
    }

    public function testScopesWinOverQueryForwardingButNotNativeEloquentOrRelationMethods(): void
    {
        $builder = $this->builder(new ForwardingTestScopedModel);
        $parent = new ForwardingTestModel;
        $parent->exists = true;
        $relation = new HasMany($builder, $parent, 'items.parent_id', 'id');

        $this->assertSame(5, $builder->limit('scope'));
        $this->assertSame(5, $relation->offset('scope'));
        $this->assertSame(99, $builder->rawAnswer());
        $this->assertSame(99, $relation->rawAnswer());
        $this->assertSame($builder, $builder->published());
        $this->assertSame($relation, $relation->published());
        $this->assertSame($relation, $relation->limit(1));
        // The native relation method ignores the scalar returned by its inner scope call.
        $this->assertNull($builder->getQuery()->limit);
    }

    /**
     * Construct real forwarding objects without an external database.
     */
    private function builder(Model $model): ForwardingTestBuilder
    {
        $connection = m::mock(Connection::class);
        $query = new ForwardingTestQuery($connection, new Grammar($connection), new Processor);

        return (new ForwardingTestBuilder($query))->setModel($model);
    }
}

class ForwardingTestQuery extends QueryBuilder
{
    /**
     * Add a custom query-only predicate.
     */
    public function tenant(string $tenant): static
    {
        return $this->where('tenant_id', $tenant);
    }

    /**
     * Return an explicitly passed-through scalar.
     */
    public function rawAnswer(): int
    {
        return 42;
    }

    /**
     * Return a scalar that ordinary forwarding discards.
     */
    public function ignoredAnswer(): int
    {
        return 42;
    }
}

class ForwardingTestBuilder extends Builder
{
    protected array $passthru = ['rawanswer'];

    /**
     * Return a native fluent result.
     */
    public function published(): static
    {
        return $this;
    }

    /**
     * Return a native terminal result without running a query.
     */
    public function models(): Collection
    {
        return new Collection([$this->getModel()]);
    }
}

class ForwardingTestModel extends Model
{
}

class ForwardingTestScopedModel extends ForwardingTestModel
{
    /**
     * Override a query-only method with a scalar-returning scope.
     */
    public function scopeLimit(Builder $query, int|string $label): int
    {
        return is_int($label) ? $label : strlen($label);
    }

    /**
     * Override a method that the relation does not own.
     */
    public function scopeOffset(Builder $query, string $label): int
    {
        return strlen($label);
    }

    /**
     * Remain subordinate to the builder's native method.
     */
    public function scopePublished(Builder $query): int
    {
        return 1;
    }

    /**
     * Take precedence over a passthru declaration.
     */
    public function scopeRawAnswer(Builder $query): int
    {
        return 99;
    }
}
