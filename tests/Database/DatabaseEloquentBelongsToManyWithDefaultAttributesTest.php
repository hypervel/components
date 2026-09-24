<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Database\Query\Grammars\Grammar;
use Hypervel\Tests\TestCase;
use Mockery as m;

class DatabaseEloquentBelongsToManyWithDefaultAttributesTest extends TestCase
{
    public function testWithPivotValueMethodSetsWhereConditionsForFetching(): void
    {
        $relation = new BelongsToMany(...$this->getRelationArguments());
        $relation->withPivotValue(['is_admin' => 1]);
    }

    public function testWithPivotValueMethodSetsDefaultArgumentsForInsertion(): void
    {
        $relation = $this->getMockBuilder(BelongsToMany::class)->onlyMethods(['touchIfTouching'])->setConstructorArgs($this->getRelationArguments())->getMock();
        $relation->expects($this->once())->method('touchIfTouching');
        $relation->withPivotValue(['is_admin' => 1]);

        $query = m::mock(QueryBuilder::class);
        $query->expects('from')->with('club_user')->andReturn($query);
        $query->expects('insert')->with([['club_id' => 1, 'user_id' => 1, 'is_admin' => 1]])->andReturn(true);
        $relation->getQuery()->getQuery()->expects('newQuery')->andReturn($query);

        $relation->attach(1);
    }

    /**
     * Get the arguments for the relationship.
     */
    public function getRelationArguments(): array
    {
        $parent = m::mock(Model::class);
        $parent->shouldReceive('getKey')->andReturn(1);
        $parent->shouldReceive('getCreatedAtColumn')->andReturn('created_at');
        $parent->shouldReceive('getUpdatedAtColumn')->andReturn('updated_at');
        $parent->shouldReceive('getAttribute')->with('id')->andReturn(1);

        $builder = m::mock(Builder::class);
        $related = m::mock(Model::class);
        $builder->shouldReceive('getModel')->andReturn($related);

        $related->shouldReceive('getTable')->andReturn('users');
        $related->shouldReceive('getKeyName')->andReturn('id');
        $related->shouldReceive('qualifyColumn')->with('id')->andReturn('users.id');

        $builder->expects('join')->with('club_user', 'users.id', '=', 'club_user.user_id');
        $builder->expects('where')->with('club_user.club_id', '=', 1)->andReturnSelf();
        $builder->expects('where')->with('club_user.is_admin', '=', 1, 'and')->andReturnSelf();

        $mockQueryBuilder = m::mock(QueryBuilder::class);
        $builder->shouldReceive('getQuery')->andReturn($mockQueryBuilder);
        $mockQueryBuilder->shouldReceive('getGrammar')->andReturn(m::mock(Grammar::class, ['isExpression' => false]));
        $connection = m::mock(ConnectionInterface::class);
        $connection->shouldReceive('table')->andReturnUsing(
            fn (string $table): QueryBuilder => $mockQueryBuilder->newQuery()->from($table)
        );
        $mockQueryBuilder->shouldReceive('getConnection')->andReturn($connection);

        return [
            $builder,
            $parent,
            'club_user',
            'club_id',
            'user_id',
            'id',
            'id',
            null,
            false,
        ];
    }
}
