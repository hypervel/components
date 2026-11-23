<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\DatabaseEloquentHasOneTest;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasOne;
use Hypervel\Database\PdoConnection;
use Hypervel\Database\Query\Builder as BaseBuilder;
use Hypervel\Database\Query\Grammars\Grammar;
use Hypervel\Database\Query\Processors\Processor;
use Hypervel\Tests\TestCase;
use PDO;

class DatabaseEloquentHasOneTest extends TestCase
{
    public function testRelationIsProperlyInitialized(): void
    {
        $relation = $this->getRelationWithRealQuery();
        $model = new ModelStub;
        $models = $relation->initRelation([$model], 'foo');

        $this->assertSame([$model], $models);
        $this->assertTrue($model->relationLoaded('foo'));
        $this->assertNull($model->getRelation('foo'));
    }

    public function testEagerConstraintsAreProperlyAdded(): void
    {
        $relation = $this->getRelationWithRealQuery();
        $model1 = new ModelStub;
        $model1->id = 1;
        $model2 = new ModelStub;
        $model2->id = 2;
        $relation->addEagerConstraints([$model1, $model2]);

        $this->assertSame('select * from "model_stubs" where "table"."foreign_key" = ? and "table"."foreign_key" is not null and "table"."foreign_key" in (1, 2)', $relation->toSql());
        $this->assertSame([1], $relation->getBindings());
    }

    public function testModelsAreProperlyMatchedToParents(): void
    {
        $relation = $this->getRelationWithRealQuery();

        $result1 = new ModelStub;
        $result1->foreign_key = 1;
        $result2 = new ModelStub;
        $result2->foreign_key = 2;
        $result3 = new ModelStub;
        $result3->foreign_key = new class {
            public function __toString()
            {
                return '4';
            }
        };

        $model1 = new ModelStub;
        $model1->id = 1;
        $model2 = new ModelStub;
        $model2->id = 2;
        $model3 = new ModelStub;
        $model3->id = 3;
        $model4 = new ModelStub;
        $model4->id = 4;

        $models = $relation->match([$model1, $model2, $model3, $model4], new Collection([$result1, $result2, $result3]), 'foo');

        $this->assertEquals(1, $models[0]->foo->foreign_key);
        $this->assertEquals(2, $models[1]->foo->foreign_key);
        $this->assertNull($models[2]->foo);
        $this->assertSame('4', (string) $models[3]->foo->foreign_key);
    }

    public function testRelationCountQueryCanBeBuilt(): void
    {
        $relation = $this->getRelationWithRealQuery();

        $query = $relation->getRelationExistenceCountQuery($this->newBuilder('one'), $this->newBuilder('two'));

        $this->assertSame('select count(*) from "one" where "model_stubs"."id" = "table"."foreign_key"', $query->toSql());
    }

    /**
     * Create a builder for the stub model, optionally from the given table.
     */
    protected function newBuilder(?string $table = null): Builder
    {
        $connection = new PdoConnection(new PDO('sqlite::memory:'));
        $builder = (new Builder(new BaseBuilder($connection, new Grammar($connection), new Processor)))->setModel(new ModelStub);

        return $table ? $builder->from($table) : $builder;
    }

    /**
     * Get a has one relationship backed by a real query builder.
     */
    protected function getRelationWithRealQuery(): HasOne
    {
        $parent = new ModelStub;
        $parent->id = 1;

        return new HasOne($this->newBuilder(), $parent, 'table.foreign_key', 'id');
    }
}

class ModelStub extends Model
{
    public mixed $foreign_key = 'foreign.value';
}
