<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel\DatabaseEloquentBelongsToManyWithCastedAttributesTest;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Database\Query\Grammars\Grammar;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class DatabaseEloquentBelongsToManyWithCastedAttributesTest extends TestCase
{
    public function testModelsAreProperlyMatchedToParents()
    {
        $relation = $this->getRelation();
        $model1 = m::mock(Model::class);
        $model1->shouldReceive('hasAttribute')->passthru();
        $model1->shouldReceive('getAttribute')->with('parent_key')->andReturn(1);
        $model1->shouldReceive('getAttribute')->with('foo')->passthru();
        $model1->shouldReceive('hasGetMutator')->andReturn(false);
        $model1->shouldReceive('hasAttributeMutator')->andReturn(false);
        $model1->shouldReceive('hasRelationAutoloadCallback')->andReturn(false);
        $model1->shouldReceive('getCasts')->andReturn([]);
        $model1->shouldReceive('getRelationValue', 'relationLoaded', 'relationResolver', 'setRelation', 'isRelation')->passthru();

        $model2 = m::mock(Model::class);
        $model2->shouldReceive('hasAttribute')->passthru();
        $model2->shouldReceive('getAttribute')->with('parent_key')->andReturn(2);
        $model2->shouldReceive('getAttribute')->with('foo')->passthru();
        $model2->shouldReceive('hasGetMutator')->andReturn(false);
        $model2->shouldReceive('hasAttributeMutator')->andReturn(false);
        $model2->shouldReceive('hasRelationAutoloadCallback')->andReturn(false);
        $model2->shouldReceive('getCasts')->andReturn([]);
        $model2->shouldReceive('getRelationValue', 'relationLoaded', 'relationResolver', 'setRelation', 'isRelation')->passthru();

        $result1 = (object) [
            'pivot' => (object) [
                'foreign_key' => new class {
                    public function __toString()
                    {
                        return '1';
                    }
                },
            ],
        ];

        $models = $relation->match([$model1, $model2], Collection::wrap($result1), 'foo');
        $this->assertNull($models[1]->foo);
        $this->assertSame(1, $models[0]->foo->count());
        $this->assertContains($result1, $models[0]->foo);
    }

    protected function getRelation()
    {
        $builder = m::mock(Builder::class);
        $related = m::mock(Model::class);
        $related->shouldReceive('newCollection')->passthru();
        $related->shouldReceive('resolveCollectionFromAttribute')->passthru();
        $builder->shouldReceive('getModel')->andReturn($related);
        $related->shouldReceive('qualifyColumn');
        $builder->shouldReceive('join', 'where');
        $builder->shouldReceive('getQuery')->andReturn(
            m::mock(QueryBuilder::class, ['getGrammar' => m::mock(Grammar::class, ['isExpression' => false])])
        );

        return new BelongsToMany(
            $builder,
            new ModelStub(),
            'relation',
            'foreign_key',
            'id',
            'parent_key',
            'related_key'
        );
    }
}

class ModelStub extends Model
{
    public $foreign_key = 'foreign.value';
}
