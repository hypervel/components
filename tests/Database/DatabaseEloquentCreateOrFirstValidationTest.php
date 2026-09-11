<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Database\Query\Grammars\Grammar;
use Hypervel\Database\Query\Processors\Processor;
use Hypervel\Tests\TestCase;
use LogicException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;

class DatabaseEloquentCreateOrFirstValidationTest extends TestCase
{
    #[DataProvider('creationHelpers')]
    public function testRelatedBuilderValidationRunsBeforeQueriesAndValueCallbacks(string $relation, string $method): void
    {
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getTablePrefix')->andReturn('');
        $connection->shouldReceive('query')->andReturnUsing(fn () => new QueryBuilder($connection, new Grammar($connection), new Processor));
        $connection->shouldNotReceive('select');
        $connection->shouldNotReceive('insert');
        $connection->shouldNotReceive('update');
        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->andReturn($connection);
        Model::setConnectionResolver($resolver);
        $parent = new CreationValidationParent(['id' => 1]);
        $related = CreationValidationModel::class;
        $query = match ($relation) {
            'direct' => (new $related)->newQuery(),
            'hasOne' => $parent->hasOne($related, 'parent_id'),
            'hasMany' => $parent->hasMany($related, 'parent_id'),
            'morphOne' => $parent->morphOne($related, 'parent'),
            'morphMany' => $parent->morphMany($related, 'parent'),
            'hasOneThrough' => $parent->hasOneThrough($related, CreationValidationParent::class, 'parent_id', 'through_id'),
            'hasManyThrough' => $parent->hasManyThrough($related, CreationValidationParent::class, 'parent_id', 'through_id'),
            'belongsToMany' => $parent->belongsToMany($related, 'parent_related', 'parent_id', 'related_id', relation: 'related'),
            'morphToMany' => $parent->morphToMany($related, 'parent', 'parent_related', 'parent_id', 'related_id', relation: 'related'),
        };
        $values = function (): never {
            $this->fail('The value callback must not run before validation.');
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Create-or-first is unavailable for this builder.');
        $query->{$method}(['id' => 2], $values);
    }

    /**
     * Provide creation helpers for each relationship type.
     */
    public static function creationHelpers(): iterable
    {
        foreach (['direct', 'hasOne', 'hasMany', 'morphOne', 'morphMany', 'hasOneThrough', 'hasManyThrough', 'belongsToMany', 'morphToMany'] as $relation) {
            foreach (['firstOrCreate', 'createOrFirst', 'updateOrCreate'] as $method) {
                yield $relation . ' ' . $method => [$relation, $method];
            }
        }
    }
}

class CreationValidationParent extends Model
{
    public bool $timestamps = false;

    protected array $guarded = [];
}

class CreationValidationModel extends CreationValidationParent
{
    protected static string $builder = CreationValidationBuilder::class;
}

class CreationValidationBuilder extends Builder
{
    /**
     * Reject create-or-first operations for this builder.
     */
    public function ensureCanCreateOrFirst(): never
    {
        throw new LogicException('Create-or-first is unavailable for this builder.');
    }
}
