<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Eloquent\Relations\HasOne;
use Hypervel\Database\PdoConnection;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Database\Query\Grammars\Grammar;
use Hypervel\Database\Query\Processors\Processor;
use Hypervel\Tests\TestCase;
use PDO;

class EloquentHasOneOrManyDeprecationTest extends TestCase
{
    public function testHasManyMatchWithNullLocalKey(): void
    {
        $relation = $this->getHasManyRelation();

        $result1 = new HasOneOrManyDeprecationModelStub;
        $result1->foreign_key = 1;

        $result2 = new HasOneOrManyDeprecationModelStub;
        $result2->foreign_key = '';

        $model1 = new HasOneOrManyDeprecationModelStub;
        $model1->id = 1;
        $model2 = new HasOneOrManyDeprecationModelStub;
        $model2->id = null;

        $models = $relation->match([$model1, $model2], new Collection([$result1, $result2]), 'foo');

        $this->assertCount(1, $models[0]->foo);
        $this->assertNull($models[1]->foo);
    }

    public function testHasOneMatchWithNullLocalKey(): void
    {
        $relation = $this->getHasOneRelation();

        $result1 = new HasOneOrManyDeprecationModelStub;
        $result1->foreign_key = 1;

        $model1 = new HasOneOrManyDeprecationModelStub;
        $model1->id = 1;
        $model2 = new HasOneOrManyDeprecationModelStub;
        $model2->id = null;

        $models = $relation->match([$model1, $model2], new Collection([$result1]), 'foo');

        $this->assertInstanceOf(HasOneOrManyDeprecationModelStub::class, $models[0]->foo);
        $this->assertNull($models[1]->foo);
    }

    public function testHasManyMatchWithNullForeignKey(): void
    {
        $relation = $this->getHasManyRelation();

        $result = new HasOneOrManyDeprecationModelStub;
        $result->foreign_key = null;

        $model = new HasOneOrManyDeprecationModelStub;
        $model->id = '';

        $models = $relation->match([$model], new Collection([$result]), 'foo');

        $this->assertNull($models[0]->foo);
    }

    /**
     * Create a has-many relation.
     */
    protected function getHasManyRelation(): HasMany
    {
        return new HasMany($this->newBuilder(), $this->newParent(), 'table.foreign_key', 'id');
    }

    /**
     * Create a has-one relation.
     */
    protected function getHasOneRelation(): HasOne
    {
        return new HasOne($this->newBuilder(), $this->newParent(), 'table.foreign_key', 'id');
    }

    /**
     * Create an Eloquent builder for the related stub model.
     */
    protected function newBuilder(): Builder
    {
        $connection = new PdoConnection(new PDO('sqlite::memory:'));

        return (new Builder(new QueryBuilder($connection, new Grammar($connection), new Processor)))->setModel(new HasOneOrManyDeprecationModelStub);
    }

    /**
     * Create the parent model.
     */
    protected function newParent(): Model
    {
        $parent = new HasOneOrManyDeprecationModelStub;
        $parent->id = 1;

        return $parent;
    }
}

class HasOneOrManyDeprecationModelStub extends Model
{
    protected string $keyType = 'string';

    public mixed $foreign_key = null;
}
